<?php
/**
 * Модуль Категорій — Family Budget Plugin
 * Файл: category.php
 *
 * Відповідає за управління категоріями бюджету:
 * перегляд, додавання, редагування (назва + тип), видалення,
 * сортування, управління параметрами категорій та MCC-маппінгом.
 *
 * @package FamilyBudget
 * @since   1.5.0 (Додано: інтеграція MCC-маппінгу, колонка MCC у таблиці)
 */

// Захист від прямого доступу до файлу.
defined( 'ABSPATH' ) || exit;

// =========================================================
// РОЗДІЛ 1: ІНІЦІАЛІЗАЦІЯ ТА БЕЗПЕКА
// =========================================================

/**
 * Двошарова перевірка безпеки AJAX-запитів модуля категорій.
 *
 * Делегує перевірку глобальному хелперу плагіна fb_verify_ajax_request(),
 * який одночасно перевіряє: (1) роль/авторизацію користувача, (2) nonce-токен.
 * Завершує виконання з wp_die() при будь-якому порушенні.
 *
 * @since  1.4.0
 * @param  string $action Ім'я nonce-дії WordPress.
 * @return void
 */
function fb_category_verify_request( string $action = 'fb_category_nonce' ): void {
    fb_verify_ajax_request( $action );
}

/**
 * Перевіряє доступ поточного користувача до родини категорії для MCC-операцій.
 *
 * Виконує пошук Family_ID через зв'язок Category → CategoryType → Family,
 * після чого перевіряє членство поточного користувача в цій родині.
 *
 * @since  1.5.0
 * @param  int $category_id ID категорії для перевірки.
 * @return int              Family_ID якщо доступ є, або 0 у разі відмови.
 */
function fb_mcc_verify_category_access( int $category_id ): int {
    global $wpdb;

    if ( ! $category_id ) {
        return 0;
    }

    $family_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT ct.Family_ID
         FROM {$wpdb->prefix}Category AS c
         INNER JOIN {$wpdb->prefix}CategoryType AS ct ON c.CategoryType_ID = ct.id
         WHERE c.id = %d
         LIMIT 1",
        $category_id
    ) );

    if ( ! $family_id
        || ! function_exists( 'fb_user_has_family_access' )
        || ! fb_user_has_family_access( $family_id )
    ) {
        return 0;
    }

    return $family_id;
}

// =========================================================
// РОЗДІЛ 2: БІЗНЕС-ЛОГІКА — ОТРИМАННЯ ДАНИХ
// =========================================================

/**
 * Повертає список категорій доступних родин поточного користувача.
 *
 * Ізоляція даних реалізована через INNER JOIN:
 * Category → CategoryType → Family → UserFamily
 *
 * Оптимізовано проти N+1: лічильники params_count та mcc_count
 * отримуються в межах одного SQL-запиту через subquery та LEFT JOIN.
 *
 * @since  1.4.1
 * @since  1.5.0 Додано mcc_count через LEFT JOIN на агреговану підтаблицю.
 * @param  int $type_id Фільтр за ID типу категорії. 0 — без фільтрації.
 * @return array        Масив об'єктів stdClass або порожній масив.
 */
function fb_get_category_data( int $type_id = 0 ): array {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return [];
    }

    // Запит із агрегацією MCC через LEFT JOIN на підзапит (захист від N+1).
    $query = "
        SELECT
            c.*,
            ct.CategoryType_Name,
            ( SELECT COUNT(*) FROM {$wpdb->prefix}CategoryParam WHERE Category_ID = c.id ) AS params_count,
            COALESCE( mm.mcc_count, 0 ) AS mcc_count
        FROM {$wpdb->prefix}Category       AS c
        INNER JOIN {$wpdb->prefix}CategoryType AS ct  ON c.CategoryType_ID  = ct.id
        INNER JOIN {$wpdb->prefix}Family       AS f   ON ct.Family_ID        = f.id
        INNER JOIN {$wpdb->prefix}UserFamily   AS u   ON u.Family_ID         = f.id
        LEFT JOIN (
            SELECT category_id, COUNT(*) AS mcc_count
            FROM {$wpdb->prefix}mcc_mapping
            GROUP BY category_id
        ) AS mm ON mm.category_id = c.id
        WHERE u.User_ID = %d
    ";

    $args = [ $user_id ];

    if ( absint( $type_id ) > 0 ) {
        $query .= ' AND c.CategoryType_ID = %d';
        $args[] = absint( $type_id );
    }

    $query .= ' ORDER BY c.CategoryType_ID ASC, c.Category_Order ASC';

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return $wpdb->get_results( $wpdb->prepare( $query, $args ) ) ?: [];
}

// =========================================================
// РОЗДІЛ 3: HTML-РЕНДЕРИНГ — ШОРТКОД [fb_categories]
// =========================================================

/**
 * Реєструє та рендерить шорткод [fb_categories].
 *
 * @since  1.4.1
 * @since  1.5.0 Додано MCC-колонку у таблицю та модальне вікно MCC-маппінгу.
 * @return string Буферизована HTML-розмітка.
 */
function fb_shortcode_categories_interface(): string {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
    }

    // Отримуємо довідникові дані для форм.
    $filter_types = function_exists( 'fb_get_category_type' )      ? fb_get_category_type()      : [];
    $add_types    = function_exists( 'fb_get_all_category_types' ) ? fb_get_all_category_types() : [];
    $param_types  = function_exists( 'fb_get_parameter_types' )    ? fb_get_parameter_types()    : [];

    // Підключаємо стилі та скрипти.
    wp_enqueue_style( 'fb-category-css', FB_PLUGIN_URL . 'css/category.css', [], FB_VERSION );
    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_script(
        'fb-category-js',
        FB_PLUGIN_URL . 'js/category.js',
        [ 'jquery', 'jquery-ui-sortable' ],
        FB_VERSION,
        true
    );

    // Формуємо масив типів для JS (inline-редагування).
    $types_for_js = [];
    foreach ( $add_types as $at ) {
        $types_for_js[] = [
            'id'   => (int) fb_extract_value( $at, [ 'id', 'ID' ] ),
            'name' => (string) fb_extract_value( $at, [ 'CategoryType_Name', 'name' ] ),
        ];
    }

    wp_localize_script( 'fb-category-js', 'fbCatObj', [
        'ajax_url'          => admin_url( 'admin-ajax.php' ),
        'nonce'             => wp_create_nonce( 'fb_category_nonce' ),
        'confirm_delete'    => esc_html__( 'Видалити цей запис?', 'family-budget' ),
        'confirm_mcc_del'   => esc_html__( 'Видалити цей MCC-запис?', 'family-budget' ),
        'category_types'    => $types_for_js,
        'txt_saving'        => esc_html__( '…', 'family-budget' ),
        'err_connect'       => esc_html__( 'Помилка з\'єднання. Спробуйте ще раз.', 'family-budget' ),
        'err_save'          => esc_html__( 'Помилка збереження.', 'family-budget' ),
        'err_mcc_code'      => esc_html__( 'MCC-код повинен бути числом від 1 до 9999.', 'family-budget' ),
        'mcc_modal_title'   => esc_html__( 'MCC-коди: ', 'family-budget' ),
    ] );

    wp_add_inline_script( 'fb-category-js', fb_category_get_inline_js() );

    ob_start();
    ?>
    <div class="fb-category-wrapper">

        <?php /* --- ПАНЕЛЬ ФІЛЬТРІВ ТА ФОРМА ДОДАВАННЯ --- */ ?>
        <div class="fb-category-controls">

            <div class="fb-filter-group">
                <select id="fb-filter-cat-type" class="fb-compact-input">
                    <option value="0"><?php esc_html_e( 'Всі типи', 'family-budget' ); ?></option>
                    <?php foreach ( $filter_types as $t ) :
                        $tid = fb_extract_value( $t, [ 'id', 'ID' ] );
                        $tnm = fb_extract_value( $t, [ 'CategoryType_Name', 'name' ] );
                        ?>
                        <option value="<?php echo esc_attr( $tid ); ?>"><?php echo esc_html( $tnm ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <form id="fb-add-category-form" class="fb-add-group">
                <select name="type_id" required class="fb-compact-input">
                    <option value="" disabled selected><?php esc_html_e( 'Тип', 'family-budget' ); ?></option>
                    <?php foreach ( $add_types as $at ) :
                        $atid = fb_extract_value( $at, [ 'id', 'ID' ] );
                        $atnm = fb_extract_value( $at, [ 'CategoryType_Name', 'name' ] );
                        ?>
                        <option value="<?php echo esc_attr( $atid ); ?>"><?php echo esc_html( $atnm ); ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="category_name" required
                       placeholder="<?php esc_attr_e( 'Назва категорії', 'family-budget' ); ?>"
                       class="fb-compact-input">
                <button type="submit" class="fb-btn-primary"><?php esc_html_e( 'Додати', 'family-budget' ); ?></button>
            </form>

        </div>

        <?php /* --- ТАБЛИЦЯ КАТЕГОРІЙ --- */ ?>
        <div class="fb-category-table-container">
            <table class="fb-table">
                <thead>
                <tr>
                    <th width="30"></th>
                    <th width="130"><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
                    <th><?php esc_html_e( 'Назва', 'family-budget' ); ?></th>
                    <th width="80" class="text-center"><?php esc_html_e( 'Параметри', 'family-budget' ); ?></th>
                    <th width="60" class="text-center"><?php esc_html_e( 'MCC', 'family-budget' ); ?></th>
                    <th width="80" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                </tr>
                </thead>
                <tbody id="fb-category-tbody"></tbody>
            </table>
        </div>

        <?php /* --- МОДАЛЬНЕ ВІКНО: ПАРАМЕТРИ КАТЕГОРІЇ --- */ ?>
        <div id="fb-params-modal" class="fb-modal hidden">
            <div class="fb-modal-content">
                <div class="fb-modal-header">
                    <h3 id="fb-modal-cat-name"><?php esc_html_e( 'Параметри', 'family-budget' ); ?></h3>
                    <span class="fb-modal-close">&times;</span>
                </div>
                <div class="fb-modal-body">
                    <form id="fb-add-param-form" class="fb-add-group">
                        <input type="hidden" id="modal_category_id"  name="modal_category_id" value="">

                        <select name="param_type_id" required class="fb-compact-input">
                            <option value="" disabled selected><?php esc_html_e( 'Тип', 'family-budget' ); ?></option>
                            <?php foreach ( $param_types as $pt ) :
                                $ptid = fb_extract_value( $pt, [ 'id', 'ID' ] );
                                $ptnm = fb_extract_value( $pt, [ 'ParameterType_Name', 'name' ] );
                                ?>
                                <option value="<?php echo esc_attr( $ptid ); ?>"><?php echo esc_html( $ptnm ); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" name="param_name" required
                               placeholder="<?php esc_attr_e( 'Назва параметра', 'family-budget' ); ?>"
                               class="fb-compact-input">
                        <button type="submit" class="fb-btn-primary">+</button>
                    </form>

                    <table class="fb-table">
                        <thead>
                        <tr>
                            <th width="30"></th>
                            <th><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
                            <th><?php esc_html_e( 'Назва параметра', 'family-budget' ); ?></th>
                            <th width="70" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                        </tr>
                        </thead>
                        <tbody id="fb-params-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php /* --- МОДАЛЬНЕ ВІКНО: MCC-МАППІНГ --- */ ?>
        <div id="fb-mcc-modal" class="fb-modal hidden">
            <div class="fb-modal-content fb-modal-content--wide">
                <div class="fb-modal-header">
                    <h3 id="fb-mcc-modal-title"><?php esc_html_e( 'MCC-коди', 'family-budget' ); ?></h3>
                    <span class="fb-modal-close fb-mcc-modal-close">&times;</span>
                </div>
                <div class="fb-modal-body">

                    <?php /* Форма додавання нового MCC-запису */ ?>
                    <form id="fb-add-mcc-form" class="fb-add-group fb-mcc-add-form">
                        <input type="hidden" id="fb-mcc-category-id" name="mcc_category_id" value="">

                        <input type="number" name="mcc_code" required
                               min="1" max="9999"
                               placeholder="<?php esc_attr_e( 'MCC-код', 'family-budget' ); ?>"
                               class="fb-compact-input fb-mcc-code-input">

                        <input type="text" name="mcc_desc"
                               placeholder="<?php esc_attr_e( 'Опис (необов\'язково)', 'family-budget' ); ?>"
                               class="fb-compact-input fb-mcc-desc-input">

                        <button type="submit" class="fb-btn-primary">+</button>
                    </form>

                    <table class="fb-table">
                        <thead>
                        <tr>
                            <th width="90"><?php esc_html_e( 'Код MCC', 'family-budget' ); ?></th>
                            <th><?php esc_html_e( 'Опис', 'family-budget' ); ?></th>
                            <th width="70" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                        </tr>
                        </thead>
                        <tbody id="fb-mcc-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_categories', 'fb_shortcode_categories_interface' );

// =========================================================
// РОЗДІЛ 4: JAVASCRIPT — INLINE-СКРИПТ (INLINE-РЕДАГУВАННЯ РЯДКІВ ТАБЛИЦІ)
// =========================================================

/**
 * Повертає рядок JavaScript для inline-редагування рядків таблиці категорій.
 *
 * Скрипт підключається через wp_add_inline_script() і відповідає за:
 * зміну типу категорії через випадаючий список та редагування назви.
 * Логіка MCC-модалі знаходиться в зовнішньому файлі category.js.
 *
 * @since  1.4.0
 * @return string JavaScript-код (без тегів <script>).
 */
function fb_category_get_inline_js(): string {
    return <<<'JS'
/* global fbCatObj, jQuery */
( function ( $ ) {
    'use strict';

    /**
     * Заповнює <select> типів категорій для inline-редагування.
     *
     * @param {jQuery} $select      Елемент <select>.
     * @param {number} currentTypeId Поточний ID типу для позначення selected.
     */
    function fbFillTypeSelect( $select, currentTypeId ) {
        $select.empty();
        $.each( fbCatObj.category_types, function ( i, t ) {
            $select.append(
                $( '<option>', { value: t.id, text: t.name } )
                    .prop( 'selected', parseInt( t.id, 10 ) === parseInt( currentTypeId, 10 ) )
            );
        } );
    }

    /**
     * Переводить рядок таблиці у режим редагування.
     *
     * @param {jQuery} $row Рядок <tr>.
     */
    function fbEnterEditMode( $row ) {
        var $typeSelect = $row.find( '.fb-type-select' );
        fbFillTypeSelect( $typeSelect, $row.data( 'type-id' ) );

        $row.find( '.fb-type-val' ).addClass( 'hidden' );
        $typeSelect.removeClass( 'hidden' );

        $row.find( '.fb-cat-name-val' ).addClass( 'hidden' );
        $row.find( '.fb-name-input' ).removeClass( 'hidden' ).trigger( 'focus' );

        $row.find( '.fb-param-btn[data-action="params"], .fb-edit-btn[data-action="edit"], .fb-delete-btn[data-action="delete"]' ).addClass( 'hidden' );
        $row.find( '.fb-save-btn[data-action="save"], .fb-cancel-btn[data-action="cancel"]' ).removeClass( 'hidden' );
    }

    /**
     * Виводить рядок таблиці з режиму редагування.
     *
     * @param {jQuery} $row Рядок <tr>.
     */
    function fbExitEditMode( $row ) {
        $row.find( '.fb-type-val' ).removeClass( 'hidden' );
        $row.find( '.fb-type-select' ).addClass( 'hidden' );
        $row.find( '.fb-cat-name-val' ).removeClass( 'hidden' );
        $row.find( '.fb-name-input' ).addClass( 'hidden' );

        $row.find( '.fb-param-btn[data-action="params"], .fb-edit-btn[data-action="edit"], .fb-delete-btn[data-action="delete"]' ).removeClass( 'hidden' );
        $row.find( '.fb-save-btn[data-action="save"], .fb-cancel-btn[data-action="cancel"]' ).addClass( 'hidden' );
    }

    // Обробник: кнопка "Редагувати".
    $( document ).on( 'click.fbCatRowEdit', '#fb-category-tbody .fb-edit-btn[data-action="edit"]', function () {
        fbEnterEditMode( $( this ).closest( 'tr' ) );
    } );

    // Обробник: кнопка "Скасувати".
    $( document ).on( 'click.fbCatRowEdit', '#fb-category-tbody .fb-cancel-btn[data-action="cancel"]', function () {
        fbExitEditMode( $( this ).closest( 'tr' ) );
    } );

    // Обробник: кнопка "Зберегти" зміни категорії.
    $( document ).on( 'click.fbCatRowEdit', '#fb-category-tbody .fb-save-btn[data-action="save"]', function () {
        var $btn   = $( this );
        var $row   = $btn.closest( 'tr' );
        var id     = parseInt( $row.data( 'id' ), 10 );
        var name   = $.trim( $row.find( '.fb-name-input' ).val() );
        var typeId = parseInt( $row.find( '.fb-type-select' ).val(), 10 );

        if ( ! name ) {
            return;
        }

        $btn.prop( 'disabled', true ).text( fbCatObj.txt_saving );

        $.post( fbCatObj.ajax_url, {
            action:   'fb_ajax_update_category',
            security: fbCatObj.nonce,
            nonce:    fbCatObj.nonce,
            id:       id,
            name:     name,
            type_id:  typeId,
        } )
        .done( function ( resp ) {
            if ( resp.success ) {
                var typeName = $row.find( '.fb-type-select option:selected' ).text();
                $row.data( 'type-id', typeId );
                $row.attr( 'data-type-id', typeId );
                $row.attr( 'data-cat-name', name );
                $row.find( '.fb-type-val' ).text( typeName );
                $row.find( '.fb-cat-name-val strong' ).text( name );
                $row.find( '.fb-name-input' ).val( name );
                fbExitEditMode( $row );
            } else {
                alert( ( resp.data && resp.data.message ) ? resp.data.message : fbCatObj.err_save );
            }
        } )
        .fail( function ( xhr ) {
            console.error( '[FB Categories] AJAX fail:', xhr.status, xhr.responseText );
            alert( fbCatObj.err_connect );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( '✔' );
        } );
    } );

    // Обробник: клавіші Enter / Escape у полі вводу назви.
    $( document ).on( 'keydown.fbCatRowEdit', '#fb-category-tbody .fb-name-input', function ( e ) {
        if ( 13 === e.which ) {
            $( this ).closest( 'tr' ).find( '.fb-save-btn[data-action="save"]' ).trigger( 'click' );
        }
        if ( 27 === e.which ) {
            $( this ).closest( 'tr' ).find( '.fb-cancel-btn[data-action="cancel"]' ).trigger( 'click' );
        }
    } );

} )( jQuery );
JS;
}

// =========================================================
// РОЗДІЛ 5: AJAX-ОБРОБНИКИ — КАТЕГОРІЇ
// =========================================================

/**
 * AJAX: Завантаження та рендеринг рядків таблиці категорій.
 *
 * Повертає HTML рядків <tr> для основної таблиці,
 * включно з колонкою MCC (кількість кодів) та колонкою Параметри.
 *
 * @since  1.4.1
 * @since  1.5.0 Додано рендеринг MCC-бейджу з кількістю кодів.
 * @return void wp_send_json_success()/wp_send_json_error()
 */
add_action( 'wp_ajax_fb_ajax_load_categories', 'fb_ajax_load_categories' );
function fb_ajax_load_categories(): void {
    fb_category_verify_request();

    $type_id    = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
    $categories = fb_get_category_data( $type_id );

    ob_start();

    if ( empty( $categories ) ) {
        echo '<tr><td colspan="6" class="text-center">'
            . esc_html__( 'Записів не знайдено', 'family-budget' )
            . '</td></tr>';
    } else {
        foreach ( $categories as $cat ) {
            $type_lower = mb_strtolower( (string) $cat->CategoryType_Name );
            $type_class = ( str_contains( $type_lower, 'витрат' ) || 1 === (int) $cat->CategoryType_ID )
                ? 'fb-color-red' : 'fb-color-green';
            $mcc_count  = (int) $cat->mcc_count;
            ?>
            <tr data-id="<?php echo esc_attr( $cat->id ); ?>"
                data-type-id="<?php echo esc_attr( $cat->CategoryType_ID ); ?>"
                data-cat-name="<?php echo esc_attr( $cat->Category_Name ); ?>">

                <td class="fb-drag-handle">☰</td>

                <td class="fb-edit-type-col">
                    <span class="fb-text-val fb-type-val <?php echo esc_attr( $type_class ); ?>">
                        <?php echo esc_html( $cat->CategoryType_Name ); ?>
                    </span>
                    <select class="fb-input-val fb-type-select hidden fb-compact-input"></select>
                </td>

                <td class="fb-edit-col">
                    <span class="fb-text-val fb-cat-name-val">
                        <strong><?php echo esc_html( $cat->Category_Name ); ?></strong>
                    </span>
                    <input type="text"
                           class="fb-input-val fb-name-input hidden fb-compact-input"
                           value="<?php echo esc_attr( $cat->Category_Name ); ?>">
                </td>

                <td class="text-center">
                    <span class="fb-badge"><?php echo esc_html( $cat->params_count ); ?></span>
                </td>

                <td class="text-center">
                    <span class="fb-badge fb-mcc-badge"
                          data-cat-id="<?php echo esc_attr( $cat->id ); ?>"
                          data-cat-name="<?php echo esc_attr( $cat->Category_Name ); ?>"
                          title="<?php esc_attr_e( 'Керувати MCC-кодами', 'family-budget' ); ?>">
                        <?php echo esc_html( $mcc_count ); ?>
                    </span>
                </td>

                <td class="fb-actions text-center">
                    <span class="fb-param-btn"         data-action="params" title="<?php esc_attr_e( 'Параметри', 'family-budget' ); ?>">⚙️</span>
                    <span class="fb-edit-btn"           data-action="edit"   title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">✎</span>
                    <span class="fb-save-btn hidden"    data-action="save"   title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>">✔</span>
                    <span class="fb-cancel-btn hidden"  data-action="cancel" title="<?php esc_attr_e( 'Скасувати', 'family-budget' ); ?>">✖</span>
                    <span class="fb-delete-btn"         data-action="delete" title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>">🗑️</span>
                </td>
            </tr>
            <?php
        }
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/**
 * AJAX: Додавання нової категорії.
 *
 * Перевіряє доступ до обраного типу категорії через родину.
 * Встановлює порядковий номер як MAX(Category_Order) + 1.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_add_category', 'fb_ajax_add_category' );
function fb_ajax_add_category(): void {
    fb_category_verify_request();
    global $wpdb;

    $type_id = isset( $_POST['type_id'] )       ? absint( wp_unslash( $_POST['type_id'] ) )                    : 0;
    $name    = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';

    if ( empty( $name ) || ! $type_id ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Необхідно вказати назву та тип.', 'family-budget' ) ] );
    }

    // Перевірка доступу до родини через CategoryType.
    $type_family_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT Family_ID FROM {$wpdb->prefix}CategoryType WHERE id = %d",
        $type_id
    ) );

    if ( ! $type_family_id
        || ! function_exists( 'fb_user_has_family_access' )
        || ! fb_user_has_family_access( $type_family_id )
    ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Немає доступу до обраного типу.', 'family-budget' ) ] );
    }

    $max_order = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(Category_Order) FROM {$wpdb->prefix}Category WHERE CategoryType_ID = %d",
        $type_id
    ) );

    $inserted = $wpdb->insert(
        "{$wpdb->prefix}Category",
        [
            'CategoryType_ID' => $type_id,
            'Category_Name'   => $name,
            'Category_Order'  => $max_order + 1,
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        ],
        [ '%d', '%s', '%d', '%s', '%s' ]
    );

    if ( false === $inserted ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка запису до бази даних.', 'family-budget' ) ] );
    }

    wp_send_json_success( [ 'message' => esc_html__( 'Категорію додано.', 'family-budget' ) ] );
}

/**
 * AJAX: Оновлення назви та типу існуючої категорії.
 *
 * Перевіряє доступ до поточної та (якщо змінюється) нової родини.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_update_category', 'fb_ajax_update_category' );
function fb_ajax_update_category(): void {
    fb_category_verify_request();
    global $wpdb;

    $id      = isset( $_POST['id'] )      ? absint( wp_unslash( $_POST['id'] ) )                : 0;
    $name    = isset( $_POST['name'] )    ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) )           : 0;

    if ( ! $id || empty( $name ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані запиту.', 'family-budget' ) ] );
    }

    // Перевіряємо поточний доступ до категорії.
    $cat_family_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT ct.Family_ID FROM {$wpdb->prefix}Category c
         INNER JOIN {$wpdb->prefix}CategoryType ct ON c.CategoryType_ID = ct.id
         WHERE c.id = %d LIMIT 1",
        $id
    ) );

    if ( ! $cat_family_id
        || ! function_exists( 'fb_user_has_family_access' )
        || ! fb_user_has_family_access( $cat_family_id )
    ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено.', 'family-budget' ) ] );
    }

    // Якщо змінюється тип, перевіряємо доступ до нового типу.
    if ( $type_id > 0 ) {
        $new_type_family_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT Family_ID FROM {$wpdb->prefix}CategoryType WHERE id = %d",
            $type_id
        ) );

        if ( ! $new_type_family_id || ! fb_user_has_family_access( $new_type_family_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Немає доступу до обраного нового типу.', 'family-budget' ) ] );
        }
    }

    $data   = [ 'Category_Name' => $name, 'updated_at' => current_time( 'mysql' ) ];
    $format = [ '%s', '%s' ];

    if ( $type_id > 0 ) {
        $data['CategoryType_ID'] = $type_id;
        $format[]                = '%d';
    }

    $updated = $wpdb->update(
        "{$wpdb->prefix}Category",
        $data,
        [ 'id' => $id ],
        $format,
        [ '%d' ]
    );

    if ( false === $updated ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка оновлення бази даних.', 'family-budget' ) ] );
    }

    wp_send_json_success( [ 'message' => esc_html__( 'Категорію оновлено.', 'family-budget' ) ] );
}

/**
 * AJAX: Видалення категорії.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_delete_category', 'fb_ajax_delete_category' );
function fb_ajax_delete_category(): void {
    fb_category_verify_request();
    global $wpdb;

    $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідний ID.', 'family-budget' ) ] );
    }

    $cat_family_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT ct.Family_ID FROM {$wpdb->prefix}Category c
         INNER JOIN {$wpdb->prefix}CategoryType ct ON c.CategoryType_ID = ct.id
         WHERE c.id = %d LIMIT 1",
        $id
    ) );

    if ( ! $cat_family_id
        || ! function_exists( 'fb_user_has_family_access' )
        || ! fb_user_has_family_access( $cat_family_id )
    ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка видалення або доступ заборонено.', 'family-budget' ) ] );
    }

    $wpdb->delete( "{$wpdb->prefix}Category", [ 'id' => $id ], [ '%d' ] );
    wp_send_json_success( [ 'message' => esc_html__( 'Категорію видалено.', 'family-budget' ) ] );
}

/**
 * AJAX: Збереження нового порядку категорій після drag-and-drop.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_move_category', 'fb_ajax_move_category' );
function fb_ajax_move_category(): void {
    fb_category_verify_request();
    global $wpdb;

    if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані порядку.', 'family-budget' ) ] );
    }

    $order = array_map( 'absint', wp_unslash( $_POST['order'] ) );

    foreach ( $order as $index => $cat_id ) {
        if ( $cat_id > 0 ) {
            $wpdb->update(
                "{$wpdb->prefix}Category",
                [ 'Category_Order' => $index + 1 ],
                [ 'id' => $cat_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }
    }
    wp_send_json_success();
}

// =========================================================
// РОЗДІЛ 6: AJAX-ОБРОБНИКИ — ПАРАМЕТРИ КАТЕГОРІЙ
// =========================================================

/**
 * AJAX: Завантаження параметрів категорії для модального вікна.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_load_category_params', 'fb_ajax_load_category_params' );
function fb_ajax_load_category_params(): void {
    fb_category_verify_request();
    global $wpdb;

    $category_id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;

    if ( ! $category_id ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідний ID категорії.', 'family-budget' ) ] );
    }

    $params = $wpdb->get_results( $wpdb->prepare(
        "SELECT cp.*, pt.ParameterType_Name
         FROM {$wpdb->prefix}CategoryParam  AS cp
         JOIN {$wpdb->prefix}ParameterType  AS pt ON cp.ParameterType_ID = pt.id
         WHERE cp.Category_ID = %d
         ORDER BY cp.CategoryParam_Order ASC",
        $category_id
    ) );

    ob_start();

    if ( empty( $params ) ) {
        echo '<tr><td colspan="4" class="text-center">'
            . esc_html__( 'Параметрів немає', 'family-budget' )
            . '</td></tr>';
    } else {
        foreach ( $params as $p ) {
            ?>
            <tr data-id="<?php echo esc_attr( $p->id ); ?>">
                <td class="fb-drag-handle-param">☰</td>
                <td><?php echo esc_html( $p->ParameterType_Name ); ?></td>
                <td class="fb-edit-col">
                    <span class="fb-text-val fb-p-name-val"><?php echo esc_html( $p->CategoryParam_Name ); ?></span>
                    <input type="text"
                           class="fb-input-val fb-p-name-input hidden fb-compact-input"
                           value="<?php echo esc_attr( $p->CategoryParam_Name ); ?>">
                </td>
                <td class="fb-actions text-center">
                    <span data-action="edit-param"   title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">✎</span>
                    <span class="hidden" data-action="save-param"   title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>">✔</span>
                    <span class="hidden" data-action="cancel-param" title="<?php esc_attr_e( 'Скасувати', 'family-budget' ); ?>">✖</span>
                    <span data-action="delete-param" title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>">🗑️</span>
                </td>
            </tr>
            <?php
        }
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/**
 * AJAX: Додавання параметра до категорії.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_add_category_param', 'fb_ajax_add_category_param' );
function fb_ajax_add_category_param(): void {
    fb_category_verify_request();
    global $wpdb;

    $user_id     = get_current_user_id();
    $category_id = isset( $_POST['category_id'] )   ? absint( wp_unslash( $_POST['category_id'] ) )             : 0;
    $type_id     = isset( $_POST['param_type_id'] ) ? absint( wp_unslash( $_POST['param_type_id'] ) )           : 0;
    $name        = isset( $_POST['param_name'] )    ? sanitize_text_field( wp_unslash( $_POST['param_name'] ) ) : '';

    if ( ! $category_id || empty( $name ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані запиту.', 'family-budget' ) ] );
    }

    // Визначаємо family_id динамічно (Family_ID більше немає у формі).
    $family_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT ct.Family_ID FROM {$wpdb->prefix}Category c
         INNER JOIN {$wpdb->prefix}CategoryType ct ON c.CategoryType_ID = ct.id
         WHERE c.id = %d",
        $category_id
    ) );

    $max_order = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(CategoryParam_Order) FROM {$wpdb->prefix}CategoryParam WHERE Category_ID = %d",
        $category_id
    ) );

    $inserted = $wpdb->insert(
        "{$wpdb->prefix}CategoryParam",
        [
            'User_ID'             => $user_id,
            'Family_ID'           => $family_id,
            'Category_ID'         => $category_id,
            'ParameterType_ID'    => $type_id,
            'CategoryParam_Name'  => $name,
            'CategoryParam_Order' => $max_order + 1,
            'created_at'          => current_time( 'mysql' ),
            'updated_at'          => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
    );

    if ( false === $inserted ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка запису до бази даних.', 'family-budget' ) ] );
    }
    wp_send_json_success();
}

/**
 * AJAX: Оновлення назви параметра категорії.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_edit_category_param', 'fb_ajax_edit_category_param' );
function fb_ajax_edit_category_param(): void {
    fb_category_verify_request();
    global $wpdb;

    $id   = isset( $_POST['id'] )   ? absint( wp_unslash( $_POST['id'] ) )                : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( ! $id || empty( $name ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані запиту.', 'family-budget' ) ] );
    }

    $updated = $wpdb->update(
        "{$wpdb->prefix}CategoryParam",
        [ 'CategoryParam_Name' => $name, 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => $id ],
        [ '%s', '%s' ],
        [ '%d' ]
    );

    if ( false === $updated ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка оновлення.', 'family-budget' ) ] );
    }
    wp_send_json_success();
}

/**
 * AJAX: Видалення параметра категорії.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_delete_category_param', 'fb_ajax_delete_category_param' );
function fb_ajax_delete_category_param(): void {
    fb_category_verify_request();
    global $wpdb;

    $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідний ID.', 'family-budget' ) ] );
    }

    $wpdb->delete( "{$wpdb->prefix}CategoryParam", [ 'id' => $id ], [ '%d' ] );
    wp_send_json_success();
}

/**
 * AJAX: Збереження нового порядку параметрів після drag-and-drop.
 *
 * @since 1.4.1
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_move_category_param', 'fb_ajax_move_category_param' );
function fb_ajax_move_category_param(): void {
    fb_category_verify_request();
    global $wpdb;

    if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані порядку.', 'family-budget' ) ] );
    }

    $order = array_map( 'absint', wp_unslash( $_POST['order'] ) );

    foreach ( $order as $index => $param_id ) {
        if ( $param_id > 0 ) {
            $wpdb->update(
                "{$wpdb->prefix}CategoryParam",
                [ 'CategoryParam_Order' => $index + 1 ],
                [ 'id' => $param_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }
    }
    wp_send_json_success();
}

// =========================================================
// РОЗДІЛ 7: AJAX-ОБРОБНИКИ — MCC-МАППІНГ
// =========================================================

/**
 * AJAX: Завантаження MCC-кодів для конкретної категорії.
 *
 * Повертає HTML рядків таблиці MCC-записів для модального вікна.
 * Ізоляція: перевіряє доступ до родини категорії перед видачею даних.
 *
 * @since  1.5.0
 * @return void wp_send_json_success() з полем 'html', або wp_send_json_error().
 */
add_action( 'wp_ajax_fb_ajax_load_mcc_mapping', 'fb_ajax_load_mcc_mapping' );
function fb_ajax_load_mcc_mapping(): void {
    fb_category_verify_request();
    global $wpdb;

    $category_id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;

    if ( ! $category_id || ! fb_mcc_verify_category_access( $category_id ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено або невалідний ID категорії.', 'family-budget' ) ] );
    }

    $records = $wpdb->get_results( $wpdb->prepare(
        "SELECT mcc, mcc_description
         FROM {$wpdb->prefix}mcc_mapping
         WHERE category_id = %d
         ORDER BY mcc ASC",
        $category_id
    ) );

    ob_start();

    if ( empty( $records ) ) {
        echo '<tr><td colspan="3" class="text-center">'
            . esc_html__( 'MCC-записів немає', 'family-budget' )
            . '</td></tr>';
    } else {
        foreach ( $records as $r ) {
            ?>
            <tr data-mcc="<?php echo esc_attr( $r->mcc ); ?>"
                data-cat-id="<?php echo esc_attr( $category_id ); ?>">

                <td class="fb-edit-col">
                    <span class="fb-text-val fb-mcc-code-val">
                        <strong><?php echo esc_html( $r->mcc ); ?></strong>
                    </span>
                    <input type="number" min="1" max="9999"
                           class="fb-input-val fb-mcc-code-input-edit hidden fb-compact-input"
                           value="<?php echo esc_attr( $r->mcc ); ?>"
                           style="width:80px;">
                </td>

                <td class="fb-edit-col">
                    <span class="fb-text-val fb-mcc-desc-val">
                        <?php echo esc_html( $r->mcc_description ?? '' ); ?>
                    </span>
                    <input type="text"
                           class="fb-input-val fb-mcc-desc-input-edit hidden fb-compact-input"
                           value="<?php echo esc_attr( $r->mcc_description ?? '' ); ?>">
                </td>

                <td class="fb-actions text-center">
                    <span data-action="edit-mcc"   title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">✎</span>
                    <span class="hidden" data-action="save-mcc"   title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>">✔</span>
                    <span class="hidden" data-action="cancel-mcc" title="<?php esc_attr_e( 'Скасувати', 'family-budget' ); ?>">✖</span>
                    <span data-action="delete-mcc" title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>">🗑️</span>
                </td>
            </tr>
            <?php
        }
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/**
 * AJAX: Додавання нового MCC-запису для категорії.
 *
 * Валідує діапазон MCC-коду (1–9999) та перевіряє відсутність дублікату.
 * Перевіряє доступ через fb_mcc_verify_category_access().
 *
 * @since  1.5.0
 * @return void wp_send_json_success() або wp_send_json_error() з повідомленням.
 */
add_action( 'wp_ajax_fb_ajax_add_mcc_mapping', 'fb_ajax_add_mcc_mapping' );
function fb_ajax_add_mcc_mapping(): void {
    fb_category_verify_request();
    global $wpdb;

    $category_id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) )             : 0;
    $mcc_code    = isset( $_POST['mcc_code'] )    ? absint( wp_unslash( $_POST['mcc_code'] ) )                 : 0;
    $mcc_desc    = isset( $_POST['mcc_desc'] )    ? sanitize_text_field( wp_unslash( $_POST['mcc_desc'] ) )    : '';

    // Валідація обов'язкових полів та діапазону MCC.
    if ( ! $category_id || $mcc_code < 1 || $mcc_code > 9999 ) {
        wp_send_json_error( [ 'message' => esc_html__( 'MCC-код повинен бути числом від 1 до 9999.', 'family-budget' ) ] );
    }

    // Перевірка доступу до родини категорії.
    if ( ! fb_mcc_verify_category_access( $category_id ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено.', 'family-budget' ) ] );
    }

    // Перевірка на дублікат MCC (PRIMARY KEY).
    $exists = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}mcc_mapping WHERE mcc = %d",
        $mcc_code
    ) );

    if ( $exists > 0 ) {
        wp_send_json_error( [ 'message' => esc_html__( 'MCC-код вже існує в системі.', 'family-budget' ) ] );
    }

    $inserted = $wpdb->insert(
        "{$wpdb->prefix}mcc_mapping",
        [
            'mcc'             => $mcc_code,
            'category_id'     => $category_id,
            'mcc_description' => $mcc_desc ?: null,
        ],
        [ '%d', '%d', '%s' ]
    );

    if ( false === $inserted ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка запису до бази даних.', 'family-budget' ) ] );
    }

    wp_send_json_success( [ 'message' => esc_html__( 'MCC-код додано.', 'family-budget' ) ] );
}

/**
 * AJAX: Оновлення існуючого MCC-запису (код та/або опис).
 *
 * Якщо MCC-код змінюється, виконується безпечна операція DELETE + INSERT,
 * оскільки mcc є PRIMARY KEY таблиці mcc_mapping.
 * Перевіряє доступ до категорії і відсутність дублікату нового коду.
 *
 * @since  1.5.0
 * @return void wp_send_json_success() або wp_send_json_error() з повідомленням.
 */
add_action( 'wp_ajax_fb_ajax_update_mcc_mapping', 'fb_ajax_update_mcc_mapping' );
function fb_ajax_update_mcc_mapping(): void {
    fb_category_verify_request();
    global $wpdb;

    $old_mcc     = isset( $_POST['old_mcc'] )     ? absint( wp_unslash( $_POST['old_mcc'] ) )                  : 0;
    $new_mcc     = isset( $_POST['new_mcc'] )     ? absint( wp_unslash( $_POST['new_mcc'] ) )                  : 0;
    $category_id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) )             : 0;
    $mcc_desc    = isset( $_POST['mcc_desc'] )    ? sanitize_text_field( wp_unslash( $_POST['mcc_desc'] ) )    : '';

    // Валідація вхідних даних.
    if ( ! $old_mcc || ! $category_id || $new_mcc < 1 || $new_mcc > 9999 ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані запиту.', 'family-budget' ) ] );
    }

    // Перевірка доступу до родини категорії.
    if ( ! fb_mcc_verify_category_access( $category_id ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено.', 'family-budget' ) ] );
    }

    $desc_value = $mcc_desc ?: null;

    // Якщо MCC-код не змінюється — просте UPDATE.
    if ( $old_mcc === $new_mcc ) {
        $updated = $wpdb->update(
            "{$wpdb->prefix}mcc_mapping",
            [ 'mcc_description' => $desc_value ],
            [ 'mcc' => $old_mcc, 'category_id' => $category_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        if ( false === $updated ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Помилка оновлення бази даних.', 'family-budget' ) ] );
        }

        wp_send_json_success();
    }

    // Код змінився → перевіряємо дублікат нового коду.
    $exists = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}mcc_mapping WHERE mcc = %d",
        $new_mcc
    ) );

    if ( $exists > 0 ) {
        wp_send_json_error( [ 'message' => esc_html__( 'MCC-код вже існує в системі.', 'family-budget' ) ] );
    }

    // DELETE старого запису та INSERT нового (через обмеження PRIMARY KEY).
    $wpdb->delete(
        "{$wpdb->prefix}mcc_mapping",
        [ 'mcc' => $old_mcc, 'category_id' => $category_id ],
        [ '%d', '%d' ]
    );

    $inserted = $wpdb->insert(
        "{$wpdb->prefix}mcc_mapping",
        [
            'mcc'             => $new_mcc,
            'category_id'     => $category_id,
            'mcc_description' => $desc_value,
        ],
        [ '%d', '%d', '%s' ]
    );

    if ( false === $inserted ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка збереження нового MCC-коду.', 'family-budget' ) ] );
    }

    wp_send_json_success( [ 'new_mcc' => $new_mcc ] );
}

/**
 * AJAX: Видалення MCC-запису.
 *
 * Видаляє запис з mcc_mapping за composite-умовою (mcc + category_id)
 * для гарантії ізоляції даних.
 *
 * @since  1.5.0
 * @return void wp_send_json_success() або wp_send_json_error() з повідомленням.
 */
add_action( 'wp_ajax_fb_ajax_delete_mcc_mapping', 'fb_ajax_delete_mcc_mapping' );
function fb_ajax_delete_mcc_mapping(): void {
    fb_category_verify_request();
    global $wpdb;

    $mcc_code    = isset( $_POST['mcc_code'] )    ? absint( wp_unslash( $_POST['mcc_code'] ) )    : 0;
    $category_id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;

    if ( ! $mcc_code || ! $category_id ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні параметри запиту.', 'family-budget' ) ] );
    }

    // Подвійна перевірка: доступ до категорії + коректність category_id запису.
    if ( ! fb_mcc_verify_category_access( $category_id ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено.', 'family-budget' ) ] );
    }

    $deleted = $wpdb->delete(
        "{$wpdb->prefix}mcc_mapping",
        [ 'mcc' => $mcc_code, 'category_id' => $category_id ],
        [ '%d', '%d' ]
    );

    if ( false === $deleted ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка видалення запису.', 'family-budget' ) ] );
    }

    wp_send_json_success();
}
