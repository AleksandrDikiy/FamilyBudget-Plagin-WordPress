<?php
/**
 * Модуль Категорій — Family Budget Plugin
 * Файл: category.php
 *
 * Відповідає за управління категоріями бюджету:
 * перегляд, додавання, редагування (назва + тип), видалення,
 * сортування та управління параметрами категорій.
 *
 * @package FamilyBudget
 * @since   1.4.0
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

// =========================================================
// РОЗДІЛ 2: БІЗНЕС-ЛОГІКА — ОТРИМАННЯ ДАНИХ
// =========================================================

/**
 * Повертає список категорій доступних родин поточного користувача.
 *
 * Ізоляція даних реалізована через INNER JOIN з таблицею UserFamily —
 * користувач отримує лише категорії тих родин, до яких він прив'язаний.
 * Усі параметри фільтрації санітизуються перед використанням у запиті.
 *
 * @since  1.4.0
 * @param  int $family_id Фільтр за ID родини. 0 — без фільтрації.
 * @param  int $type_id   Фільтр за ID типу категорії. 0 — без фільтрації.
 * @return array          Масив об'єктів stdClass або порожній масив.
 */
function fb_get_category_data( int $family_id = 0, int $type_id = 0 ): array {
	global $wpdb;

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return [];
	}

	// Базовий запит з підрахунком параметрів та ізоляцією по UserFamily.
	$query = "
		SELECT
			c.*,
			f.Family_Name,
			ct.CategoryType_Name,
			( SELECT COUNT(*) FROM {$wpdb->prefix}CategoryParam WHERE Category_ID = c.id ) AS params_count
		FROM {$wpdb->prefix}Category       AS c
		INNER JOIN {$wpdb->prefix}Family       AS f  ON c.Family_ID       = f.id
		INNER JOIN {$wpdb->prefix}CategoryType AS ct ON c.CategoryType_ID = ct.id
		INNER JOIN {$wpdb->prefix}UserFamily   AS u  ON u.Family_ID       = f.id
		WHERE u.User_ID = %d
	";

	$args = [ $user_id ];

	if ( absint( $family_id ) > 0 ) {
		$query .= ' AND c.Family_ID = %d';
		$args[] = absint( $family_id );
	}

	if ( absint( $type_id ) > 0 ) {
		$query .= ' AND c.CategoryType_ID = %d';
		$args[] = absint( $type_id );
	}

	$query .= ' ORDER BY c.CategoryType_ID ASC, c.Category_Order ASC';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- запит динамічно будується з безпечних частин вище.
	return $wpdb->get_results( $wpdb->prepare( $query, $args ) ) ?: [];
}

// =========================================================
// РОЗДІЛ 3: HTML-РЕНДЕРИНГ — ШОРТКОД [fb_categories]
// =========================================================

/**
 * Реєструє та рендерить шорткод [fb_categories].
 *
 * Підключає CSS/JS-залежності, передає конфігурацію до JavaScript
 * через wp_localize_script (включно з масивом типів для inline-редагування),
 * та виводить компактний інтерфейс управління категоріями.
 *
 * @since  1.0.0
 * @return string Буферизована HTML-розмітка або повідомлення про необхідність входу.
 */
function fb_shortcode_categories_interface(): string {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
	}

	// Отримуємо довідникові дані для форм.
	$families     = function_exists( 'fb_get_families' )           ? fb_get_families()           : [];
	$filter_types = function_exists( 'fb_get_category_type' )      ? fb_get_category_type()      : [];
	$add_types    = function_exists( 'fb_get_all_category_types' )  ? fb_get_all_category_types() : [];
	$param_types  = function_exists( 'fb_get_parameter_types' )    ? fb_get_parameter_types()    : [];

	// Підключаємо стилі та скрипти.
	wp_enqueue_style( 'fb-category-css', FB_PLUGIN_URL . 'css/category.css', [], FB_VERSION );
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_script(
		'fb-category-js',
		FB_PLUGIN_URL . 'js/category.js',
		[ 'jquery', 'jquery-ui-sortable' ],
		FB_VERSION,
		true // Підключення у футері — не блокує рендеринг.
	);

	// Формуємо масив типів для JS: використовується для побудови <select> при inline-редагуванні рядка.
	$types_for_js = [];
	foreach ( $add_types as $at ) {
		$types_for_js[] = [
			'id'   => (int) fb_extract_value( $at, [ 'id', 'ID' ] ),
			'name' => (string) fb_extract_value( $at, [ 'CategoryType_Name', 'name' ] ),
		];
	}

	// Передаємо всі необхідні дані до JS одним об'єктом.
	wp_localize_script( 'fb-category-js', 'fbCatObj', [
		'ajax_url'       => admin_url( 'admin-ajax.php' ),
		'nonce'          => wp_create_nonce( 'fb_category_nonce' ),
		'confirm_delete' => esc_html__( 'Видалити цей запис?', 'family-budget' ),
		'category_types' => $types_for_js, // Масив {id, name} — для select у режимі редагування.
		'txt_saving'     => esc_html__( '…', 'family-budget' ),
		'err_connect'    => esc_html__( 'Помилка з\'єднання. Спробуйте ще раз.', 'family-budget' ),
		'err_save'       => esc_html__( 'Помилка збереження.', 'family-budget' ),
	] );

	// Підключаємо JS-логіку редагування рядків через wp_add_inline_script.
	// Скрипт додається ПІСЛЯ завантаження fb-category-js, тому може перевизначати
	// або доповнювати його обробники. Якщо category.js вже має обробники
	// edit/save/cancel для рядків категорій — видаліть їх звідти.
	wp_add_inline_script( 'fb-category-js', fb_category_get_inline_js() );

	ob_start();
	?>
	<div class="fb-category-wrapper">

		<!-- ── Панель керування: фільтри + форма додавання ── -->
		<div class="fb-category-controls">

			<!-- Фільтри таблиці -->
			<div class="fb-filter-group">
				<select id="fb-filter-cat-family" class="fb-compact-input">
					<option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
					<?php foreach ( $families as $f ) :
						$fid = fb_extract_value( $f, [ 'id', 'ID' ] );
						$fnm = fb_extract_value( $f, [ 'Family_Name', 'name' ] );
					?>
						<option value="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $fnm ); ?></option>
					<?php endforeach; ?>
				</select>

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

			<!-- Форма додавання нової категорії -->
			<form id="fb-add-category-form" class="fb-add-group">
				<select name="family_id" required class="fb-compact-input">
					<option value="" disabled selected><?php esc_html_e( 'Родина', 'family-budget' ); ?></option>
					<?php foreach ( $families as $f ) :
						$fid = fb_extract_value( $f, [ 'id', 'ID' ] );
						$fnm = fb_extract_value( $f, [ 'Family_Name', 'name' ] );
					?>
						<option value="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $fnm ); ?></option>
					<?php endforeach; ?>
				</select>

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

		<!-- ── Таблиця категорій (tbody заповнюється через AJAX) ── -->
		<div class="fb-category-table-container">
			<table class="fb-table">
				<thead>
					<tr>
						<th width="30"></th>
						<th><?php esc_html_e( 'Родина', 'family-budget' ); ?></th>
						<th width="130"><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
						<th><?php esc_html_e( 'Назва', 'family-budget' ); ?></th>
						<th width="80" class="text-center"><?php esc_html_e( 'Параметри', 'family-budget' ); ?></th>
						<th width="110" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
					</tr>
				</thead>
				<tbody id="fb-category-tbody"></tbody>
			</table>
		</div>

		<!-- ── Модальне вікно параметрів категорії ── -->
		<div id="fb-params-modal" class="fb-modal hidden">
			<div class="fb-modal-content">
				<div class="fb-modal-header">
					<h3 id="fb-modal-cat-name"><?php esc_html_e( 'Параметри', 'family-budget' ); ?></h3>
					<span class="fb-modal-close">&times;</span>
				</div>
				<div class="fb-modal-body">
					<form id="fb-add-param-form" class="fb-add-group">
						<input type="hidden" id="modal_category_id" name="modal_category_id" value="">
						<input type="hidden" id="modal_family_id"   name="modal_family_id"   value="">

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

	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'fb_categories', 'fb_shortcode_categories_interface' );

// =========================================================
// РОЗДІЛ 4: JAVASCRIPT — INLINE-СКРИПТ ЧЕРЕЗ wp_add_inline_script
// =========================================================

/**
 * Повертає рядок з JS-логікою inline-редагування рядків категорій.
 *
 * Скрипт підключається через wp_add_inline_script() після fb-category-js.
 * Реалізує: вхід у режим редагування (показ select типу + input назви),
 * збереження змін через AJAX (fb_ajax_update_category), скасування,
 * а також обробку клавіш Enter/Escape у полі вводу.
 *
 * УВАГА: якщо category.js вже містить обробники click для edit/save/cancel
 * рядків категорій — видаліть їх з category.js, щоб уникнути конфліктів.
 *
 * @since  1.4.0
 * @return string Рядок JavaScript-коду без тегів <script>.
 */
function fb_category_get_inline_js(): string {
	// Код повертається як рядок — wp_add_inline_script додає теги <script> самостійно.
	return <<<'JS'
/* global fbCatObj, jQuery */
( function ( $ ) {
    'use strict';

    /**
     * Заповнює <select> типів категорій та проставляє поточне значення.
     * Дані з fbCatObj.category_types формуються на PHP-стороні у шорткоді.
     *
     * @param {jQuery} $select      Елемент <select> для заповнення.
     * @param {number} currentTypeId ID поточного типу категорії.
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
     * Перемикає рядок таблиці у режим редагування.
     * Відображає select типу та input назви; приховує текстові span.
     *
     * @param {jQuery} $row Рядок таблиці <tr>.
     */
    function fbEnterEditMode( $row ) {
        var $typeSelect = $row.find( '.fb-type-select' );

        // Будуємо select типів на основі даних з PHP, встановлюємо поточний тип.
        fbFillTypeSelect( $typeSelect, $row.data( 'type-id' ) );

        // Перемикаємо колонку "Тип": span → select.
        $row.find( '.fb-type-val' ).addClass( 'hidden' );
        $typeSelect.removeClass( 'hidden' );

        // Перемикаємо колонку "Назва": span → input.
        $row.find( '.fb-cat-name-val' ).addClass( 'hidden' );
        $row.find( '.fb-name-input' ).removeClass( 'hidden' ).trigger( 'focus' );

        // Перемикаємо кнопки дій.
        $row.find( '.fb-edit-btn[data-action="edit"], .fb-delete-btn[data-action="delete"]' ).addClass( 'hidden' );
        $row.find( '.fb-save-btn[data-action="save"], .fb-cancel-btn[data-action="cancel"]' ).removeClass( 'hidden' );
    }

    /**
     * Повертає рядок таблиці у режим перегляду.
     * Відновлює текстові елементи; приховує поля введення.
     *
     * @param {jQuery} $row Рядок таблиці <tr>.
     */
    function fbExitEditMode( $row ) {
        $row.find( '.fb-type-val' ).removeClass( 'hidden' );
        $row.find( '.fb-type-select' ).addClass( 'hidden' );
        $row.find( '.fb-cat-name-val' ).removeClass( 'hidden' );
        $row.find( '.fb-name-input' ).addClass( 'hidden' );

        $row.find( '.fb-edit-btn[data-action="edit"], .fb-delete-btn[data-action="delete"]' ).removeClass( 'hidden' );
        $row.find( '.fb-save-btn[data-action="save"], .fb-cancel-btn[data-action="cancel"]' ).addClass( 'hidden' );
    }

    // ── Кнопка "Редагувати": вхід у режим редагування ────────────
    $( document ).on( 'click.fbCatRowEdit', '#fb-category-tbody .fb-edit-btn[data-action="edit"]', function () {
        fbEnterEditMode( $( this ).closest( 'tr' ) );
    } );

    // ── Кнопка "Скасувати": повернення до режиму перегляду ───────
    $( document ).on( 'click.fbCatRowEdit', '#fb-category-tbody .fb-cancel-btn[data-action="cancel"]', function () {
        fbExitEditMode( $( this ).closest( 'tr' ) );
    } );

    // ── Кнопка "Зберегти": AJAX-оновлення назви та типу ──────────
    // Надсилає id, name та type_id на обробник fb_ajax_update_category.
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
            security: fbCatObj.nonce, // Додаємо 'security' (стандарт для WordPress та більшості глобальних перевірок)
            nonce:    fbCatObj.nonce, // Залишаємо 'nonce' на випадок, якщо ваша логіка використовує його
            id:       id,
            name:     name,
            type_id:  typeId,
        } )
        
        .done( function ( resp ) {
            if ( resp.success ) {
                // Отримуємо назву обраного типу для оновлення span у таблиці.
                var typeName = $row.find( '.fb-type-select option:selected' ).text();

                // Оновлюємо дані та відображення рядка.
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
            // Додано вивід статусу помилки в консоль для легшої діагностики в майбутньому
            console.error( '[FB Categories] AJAX fail:', xhr.status, xhr.responseText );
            alert( fbCatObj.err_connect );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( '✔' );
        } );
    } );

    // ── Enter у полі назви — зберегти; Escape — скасувати ────────
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
 * AJAX: Завантаження рядків таблиці категорій.
 *
 * Повертає готовий HTML для вставки в #fb-category-tbody.
 * Кожен рядок містить data-type-id — атрибут, необхідний для коректної
 * роботи JS-логіки зміни типу при редагуванні.
 *
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з HTML або повідомленням про відсутність даних.
 */
add_action( 'wp_ajax_fb_ajax_load_categories', 'fb_ajax_load_categories' );
function fb_ajax_load_categories(): void {
	fb_category_verify_request();

	$family_id  = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
	$type_id    = isset( $_POST['type_id'] )   ? absint( wp_unslash( $_POST['type_id'] ) )   : 0;
	$categories = fb_get_category_data( $family_id, $type_id );

	ob_start();

	if ( empty( $categories ) ) {
		echo '<tr><td colspan="6" class="text-center">'
			. esc_html__( 'Записів не знайдено', 'family-budget' )
			. '</td></tr>';
	} else {
		foreach ( $categories as $cat ) {
			// Визначення CSS-класу для кольорового маркування типу (витрати / доходи).
			$type_lower = mb_strtolower( (string) $cat->CategoryType_Name );
			$type_class = ( str_contains( $type_lower, 'витрат' ) || 1 === (int) $cat->CategoryType_ID )
				? 'fb-color-red'
				: 'fb-color-green';
			?>
			<tr data-id="<?php echo esc_attr( $cat->id ); ?>"
				data-family-id="<?php echo esc_attr( $cat->Family_ID ); ?>"
				data-type-id="<?php echo esc_attr( $cat->CategoryType_ID ); ?>"
				data-cat-name="<?php echo esc_attr( $cat->Category_Name ); ?>">

				<td class="fb-drag-handle">☰</td>
				<td><?php echo esc_html( $cat->Family_Name ); ?></td>

				<!--
					Колонка "Тип":
					- .fb-type-val  — span для режиму ПЕРЕГЛЯДУ (видимий за замовчуванням).
					- .fb-type-select — select для режиму РЕДАГУВАННЯ (прихований; заповнюється JS
					  з fbCatObj.category_types при натисканні кнопки редагування).
				-->
				<td class="fb-edit-type-col">
					<span class="fb-text-val fb-type-val <?php echo esc_attr( $type_class ); ?>">
						<?php echo esc_html( $cat->CategoryType_Name ); ?>
					</span>
					<select class="fb-input-val fb-type-select hidden fb-compact-input"
						aria-label="<?php esc_attr_e( 'Тип категорії', 'family-budget' ); ?>"></select>
				</td>

				<!--
					Колонка "Назва":
					- .fb-cat-name-val — span для режиму ПЕРЕГЛЯДУ.
					- .fb-name-input   — input для режиму РЕДАГУВАННЯ.
				-->
				<td class="fb-edit-col">
					<span class="fb-text-val fb-cat-name-val">
						<strong><?php echo esc_html( $cat->Category_Name ); ?></strong>
					</span>
					<input type="text"
						class="fb-input-val fb-name-input hidden fb-compact-input"
						value="<?php echo esc_attr( $cat->Category_Name ); ?>"
						aria-label="<?php esc_attr_e( 'Назва категорії', 'family-budget' ); ?>">
				</td>

				<td class="text-center">
					<span class="fb-badge"><?php echo esc_html( $cat->params_count ); ?></span>
				</td>

				<td class="fb-actions text-center">
					<span class="fb-param-btn"            data-action="params" title="<?php esc_attr_e( 'Параметри', 'family-budget' ); ?>">⚙️</span>
					<span class="fb-edit-btn"             data-action="edit"   title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">✎</span>
					<span class="fb-save-btn hidden"      data-action="save"   title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>">✔</span>
					<span class="fb-cancel-btn hidden"    data-action="cancel" title="<?php esc_attr_e( 'Скасувати', 'family-budget' ); ?>">✖</span>
					<span class="fb-delete-btn"           data-action="delete" title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>">🗑️</span>
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
 * Перед записом перевіряє право доступу поточного користувача до обраної родини
 * через fb_user_has_family_access(). Розраховує наступний порядковий номер у межах
 * типу та родини.
 *
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з результатом операції.
 */
add_action( 'wp_ajax_fb_ajax_add_category', 'fb_ajax_add_category' );
function fb_ajax_add_category(): void {
	fb_category_verify_request();
	global $wpdb;

	$family_id = isset( $_POST['family_id'] )     ? absint( wp_unslash( $_POST['family_id'] ) )                      : 0;
	$type_id   = isset( $_POST['type_id'] )       ? absint( wp_unslash( $_POST['type_id'] ) )                        : 0;
	$name      = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) )     : '';

	// Перевірка доступу до родини — ізоляція даних.
	if ( ! function_exists( 'fb_user_has_family_access' ) || ! fb_user_has_family_access( $family_id ) ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Немає доступу до обраної родини.', 'family-budget' ) ] );
	}

	if ( empty( $name ) ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Назва категорії обов\'язкова.', 'family-budget' ) ] );
	}

	// Визначаємо наступний порядковий номер у межах типу та родини.
	$max_order = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT MAX(Category_Order) FROM {$wpdb->prefix}Category WHERE Family_ID = %d AND CategoryType_ID = %d",
		$family_id,
		$type_id
	) );

	$inserted = $wpdb->insert(
		"{$wpdb->prefix}Category",
		[
			'Family_ID'       => $family_id,
			'CategoryType_ID' => $type_id,
			'Category_Name'   => $name,
			'Category_Order'  => $max_order + 1,
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		],
		[ '%d', '%d', '%s', '%d', '%s', '%s' ]
	);

	if ( false === $inserted ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Помилка запису до бази даних.', 'family-budget' ) ] );
	}

	wp_send_json_success( [ 'message' => esc_html__( 'Категорію додано.', 'family-budget' ) ] );
}

/**
 * AJAX: Оновлення назви та типу існуючої категорії.
 *
 * КЛЮЧОВА ЗМІНА (v1.4.0): обробник розширений для підтримки зміни CategoryType_ID.
 * Раніше оновлювалась лише Category_Name (хук fb_ajax_update_category_name).
 * Тепер через хук fb_ajax_update_category зберігаються обидва поля.
 *
 * Безпека: перед оновленням виконується перевірка доступу поточного користувача
 * до родини, якій належить категорія (через fb_user_has_family_access).
 *
 * @since  1.4.0
 * @return void Відправляє JSON-відповідь з результатом операції.
 */
add_action( 'wp_ajax_fb_ajax_update_category', 'fb_ajax_update_category' );
function fb_ajax_update_category(): void {
	fb_category_verify_request();
	global $wpdb;

	$id      = isset( $_POST['id'] )      ? absint( wp_unslash( $_POST['id'] ) )                          : 0;
	$name    = isset( $_POST['name'] )    ? sanitize_text_field( wp_unslash( $_POST['name'] ) )           : '';
	$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) )                     : 0;

	if ( ! $id || empty( $name ) ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані запиту.', 'family-budget' ) ] );
	}

	// Отримуємо Family_ID категорії — необхідно для перевірки доступу.
	$cat = $wpdb->get_row( $wpdb->prepare(
		"SELECT Family_ID FROM {$wpdb->prefix}Category WHERE id = %d LIMIT 1",
		$id
	) );

	if ( ! $cat ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Категорію не знайдено.', 'family-budget' ) ] );
	}

	// Перевірка: поточний користувач має доступ до родини цієї категорії.
	if ( ! function_exists( 'fb_user_has_family_access' ) || ! fb_user_has_family_access( $cat->Family_ID ) ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено.', 'family-budget' ) ] );
	}

	// Формуємо масив оновлення: завжди оновлюємо назву та час зміни.
	$data   = [ 'Category_Name' => $name, 'updated_at' => current_time( 'mysql' ) ];
	$format = [ '%s', '%s' ];

	// CategoryType_ID оновлюється лише якщо переданий валідний type_id.
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
 * Перед видаленням перевіряє право доступу поточного користувача до родини,
 * якій належить категорія. Тихий збій неможливий — повертає інформативну помилку.
 *
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з результатом операції.
 */
add_action( 'wp_ajax_fb_ajax_delete_category', 'fb_ajax_delete_category' );
function fb_ajax_delete_category(): void {
	fb_category_verify_request();
	global $wpdb;

	$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

	if ( ! $id ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Невалідний ID.', 'family-budget' ) ] );
	}

	$cat = $wpdb->get_row( $wpdb->prepare(
		"SELECT Family_ID FROM {$wpdb->prefix}Category WHERE id = %d LIMIT 1",
		$id
	) );

	if ( ! $cat || ! function_exists( 'fb_user_has_family_access' ) || ! fb_user_has_family_access( $cat->Family_ID ) ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Помилка видалення або доступ заборонено.', 'family-budget' ) ] );
	}

	$wpdb->delete( "{$wpdb->prefix}Category", [ 'id' => $id ], [ '%d' ] );
	wp_send_json_success( [ 'message' => esc_html__( 'Категорію видалено.', 'family-budget' ) ] );
}

/**
 * AJAX: Збереження нового порядку категорій після drag-and-drop сортування.
 *
 * @since  1.0.0
 * @param  void
 * @return void Відправляє JSON-відповідь з результатом операції.
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
 * AJAX: Завантаження параметрів конкретної категорії для модального вікна.
 *
 * Повертає HTML-рядки для таблиці параметрів (#fb-params-tbody).
 *
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з HTML або повідомленням про відсутність даних.
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
						value="<?php echo esc_attr( $p->CategoryParam_Name ); ?>"
						aria-label="<?php esc_attr_e( 'Назва параметра', 'family-budget' ); ?>">
				</td>
				<td class="fb-actions text-center">
					<span class="fb-edit-btn"          data-action="edit-param"   title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">✎</span>
					<span class="fb-save-btn hidden"   data-action="save-param"   title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>">✔</span>
					<span class="fb-cancel-btn hidden" data-action="cancel-param" title="<?php esc_attr_e( 'Скасувати', 'family-budget' ); ?>">✖</span>
					<span class="fb-delete-btn"        data-action="delete-param" title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>">🗑️</span>
				</td>
			</tr>
			<?php
		}
	}

	wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/**
 * AJAX: Додавання нового параметра до категорії.
 *
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з результатом операції.
 */
add_action( 'wp_ajax_fb_ajax_add_category_param', 'fb_ajax_add_category_param' );
function fb_ajax_add_category_param(): void {
	fb_category_verify_request();
	global $wpdb;

	$user_id     = get_current_user_id();
	$family_id   = isset( $_POST['family_id'] )     ? absint( wp_unslash( $_POST['family_id'] ) )                    : 0;
	$category_id = isset( $_POST['category_id'] )   ? absint( wp_unslash( $_POST['category_id'] ) )                  : 0;
	$type_id     = isset( $_POST['param_type_id'] ) ? absint( wp_unslash( $_POST['param_type_id'] ) )                : 0;
	$name        = isset( $_POST['param_name'] )    ? sanitize_text_field( wp_unslash( $_POST['param_name'] ) )      : '';

	if ( ! $category_id || empty( $name ) ) {
		wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані запиту.', 'family-budget' ) ] );
	}

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
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з результатом операції.
 */
add_action( 'wp_ajax_fb_ajax_edit_category_param', 'fb_ajax_edit_category_param' );
function fb_ajax_edit_category_param(): void {
	fb_category_verify_request();
	global $wpdb;

	$id   = isset( $_POST['id'] )   ? absint( wp_unslash( $_POST['id'] ) )                          : 0;
	$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) )           : '';

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
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з результатом операції.
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
 * AJAX: Збереження нового порядку параметрів категорії після drag-and-drop.
 *
 * @since  1.0.0
 * @return void Відправляє JSON-відповідь з результатом операції.
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
