<?php
/**
 * Модуль Типів Категорій (Family Budget)
 * Назва файлу: category-type.php
 * * Відповідає за управління довідником "Типи категорій":
 * додавання, редагування, видалення та сортування (drag & drop).
 *
 * @package FamilyBudget
 * @since   1.5.0
 */

// Захист від прямого доступу
defined( 'ABSPATH' ) || exit;

/**
 * Двошарова перевірка безпеки для AJAX-обробників цього модуля.
 * Делегує перевірку загальній функції плагіна.
 *
 * @since  1.5.0
 * @param  string $action Ім'я nonce-дії WordPress.
 * @return void
 */
function fb_category_type_verify_request( string $action = 'fb_category_type_nonce' ): void {
    if ( function_exists( 'fb_verify_ajax_request' ) ) {
        fb_verify_ajax_request( $action );
    } else {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка ініціалізації безпеки.', 'family-budget' ) ], 403 );
    }
}

/**
 * Отримання відфільтрованих даних типів категорій.
 * Ізоляція даних: повертає лише типи, прив'язані до родин поточного користувача.
 *
 * @param  int $family_id ID родини для фільтрації (0 - всі доступні).
 * @return array
 */
function fb_get_category_types_data( int $family_id = 0 ): array {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return [];
    }

    $query = "
		SELECT ct.*, f.Family_Name 
		FROM {$wpdb->prefix}CategoryType AS ct
		INNER JOIN {$wpdb->prefix}Family AS f ON f.id = ct.Family_ID
		INNER JOIN {$wpdb->prefix}UserFamily AS u ON u.Family_ID = f.id
		WHERE u.User_ID = %d
	";

    $args = [ $user_id ];

    if ( $family_id > 0 ) {
        $query .= " AND ct.Family_ID = %d";
        $args[] = $family_id;
    }

    $query .= " ORDER BY ct.CategoryType_Order ASC, ct.CategoryType_Name ASC";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return $wpdb->get_results( $wpdb->prepare( $query, $args ) );
}

/**
 * Шорткод для виводу інтерфейсу "Типи категорій"
 */
function fb_render_category_type_interface(): string {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
    }

    // Отримуємо список родин користувача для фільтрів та додавання
    $families = function_exists( 'fb_get_families' ) ? fb_get_families() : [];

    // Підключаємо стилі та скрипти
    wp_enqueue_style( 'fb-category-type-css', FB_PLUGIN_URL . 'css/category-type.css', [], FB_VERSION );
    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_script(
        'fb-category-type-js',
        FB_PLUGIN_URL . 'js/category-type.js',
        [ 'jquery', 'jquery-ui-sortable' ],
        FB_VERSION,
        true
    );

    // Передаємо параметри у JavaScript
    wp_localize_script( 'fb-category-type-js', 'fbCategoryTypeObj', [
        'ajax_url'    => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'fb_category_type_nonce' ),
        'confirm_del' => esc_html__( 'Ви впевнені, що хочете видалити цей тип категорії?', 'family-budget' ),
        'err_req'     => esc_html__( 'Помилка з\'єднання.', 'family-budget' ),
    ] );

    ob_start();
    ?>
    <div class="fb-category-type-wrapper">
        <div class="fb-category-type-controls">

            <div class="fb-filter-group">
                <select id="fb-filter-ct-family" class="fb-compact-input">
                    <option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
                        $f_id   = fb_extract_value( $f, ['id', 'ID'] );
                        $f_name = fb_extract_value( $f, ['Family_Name', 'name'] );
                        ?>
                        <option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>

            <form id="fb-add-ct-form" class="fb-add-group">
                <select name="family_id" required class="fb-compact-input">
                    <option value="" disabled selected><?php esc_html_e( 'Оберіть родину', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
                        $f_id   = fb_extract_value( $f, ['id', 'ID'] );
                        $f_name = fb_extract_value( $f, ['Family_Name', 'name'] );
                        ?>
                        <option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <input type="text" name="type_name" required
                       placeholder="<?php esc_attr_e( 'Назва типу категорії', 'family-budget' ); ?>"
                       class="fb-compact-input">
                <button type="submit" class="fb-btn-primary"><?php esc_html_e( 'Додати', 'family-budget' ); ?></button>
            </form>

        </div>

        <div class="fb-category-type-table-container">
            <table class="fb-table">
                <thead>
                <tr>
                    <th width="30px"></th>
                    <th><?php esc_html_e( 'Родина', 'family-budget' ); ?></th>
                    <th><?php esc_html_e( 'Назва типу', 'family-budget' ); ?></th>
                    <th width="100px" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                </tr>
                </thead>
                <tbody id="fb-category-type-tbody">
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_category_type', 'fb_render_category_type_interface' );

// =========================================================
// AJAX-ОБРОБНИКИ
// =========================================================

/**
 * AJAX: Завантаження таблиці
 */
add_action( 'wp_ajax_fb_load_category_types', 'fb_ajax_load_category_types' );
function fb_ajax_load_category_types(): void {
    fb_category_type_verify_request();

    $family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
    $types     = fb_get_category_types_data( $family_id );

    ob_start();
    if ( empty( $types ) ) {
        echo '<tr><td colspan="4" class="text-center">' . esc_html__( 'Записів не знайдено', 'family-budget' ) . '</td></tr>';
    } else {
        foreach ( $types as $t ) {
            $family_name = ! empty( $t->Family_Name ) ? $t->Family_Name : '—';
            ?>
            <tr data-id="<?php echo esc_attr( $t->id ); ?>">
                <td class="fb-drag-handle">☰</td>
                <td><?php echo esc_html( $family_name ); ?></td>
                <td class="fb-name-col">
                    <span class="fb-ct-name-text"><?php echo esc_html( $t->CategoryType_Name ); ?></span>
                    <input type="text" class="fb-ct-name-input hidden fb-compact-input" value="<?php echo esc_attr( $t->CategoryType_Name ); ?>">
                </td>
                <td class="fb-actions text-center">
                    <span class="fb-edit-btn" data-action="edit" title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">✎</span>
                    <span class="fb-save-btn hidden" data-action="save" title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>">✔</span>
                    <span class="fb-cancel-btn hidden" data-action="cancel" title="<?php esc_attr_e( 'Скасувати', 'family-budget' ); ?>">✖</span>
                    <span class="fb-delete-btn" data-action="delete" title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>">🗑</span>
                </td>
            </tr>
            <?php
        }
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/**
 * AJAX: Додавання типу категорії
 */
add_action( 'wp_ajax_fb_add_category_type', 'fb_ajax_add_category_type' );
function fb_ajax_add_category_type(): void {
    fb_category_type_verify_request();
    global $wpdb;

    $family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
    $name      = isset( $_POST['type_name'] ) ? sanitize_text_field( wp_unslash( $_POST['type_name'] ) ) : '';

    if ( empty( $name ) || $family_id <= 0 ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Не всі поля заповнені.', 'family-budget' ) ] );
    }

    // Перевірка доступу до родини
    if ( ! function_exists( 'fb_user_has_family_access' ) || ! fb_user_has_family_access( $family_id ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Немає доступу до обраної родини.', 'family-budget' ) ] );
    }

    $max_order = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(CategoryType_Order) FROM {$wpdb->prefix}CategoryType WHERE Family_ID = %d",
        $family_id
    ) );

    $inserted = $wpdb->insert(
        "{$wpdb->prefix}CategoryType",
        [
            'Family_ID'          => $family_id,
            'CategoryType_Name'  => $name,
            'CategoryType_Order' => $max_order + 1,
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' )
        ],
        [ '%d', '%s', '%d', '%s', '%s' ]
    );

    if ( false === $inserted ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Помилка запису до бази даних.', 'family-budget' ) ] );
    }

    wp_send_json_success( [ 'message' => esc_html__( 'Тип категорії успішно додано.', 'family-budget' ) ] );
}

/**
 * AJAX: Редагування назви
 */
add_action( 'wp_ajax_fb_edit_category_type', 'fb_ajax_edit_category_type' );
function fb_ajax_edit_category_type(): void {
    fb_category_type_verify_request();
    global $wpdb;

    $id   = isset( $_POST['id'] )   ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( ! $id || empty( $name ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані.', 'family-budget' ) ] );
    }

    // Перевірка доступу
    $family_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT Family_ID FROM {$wpdb->prefix}CategoryType WHERE id = %d", $id ) );
    if ( ! $family_id || ! function_exists( 'fb_user_has_family_access' ) || ! fb_user_has_family_access( $family_id ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено.', 'family-budget' ) ] );
    }

    $updated = $wpdb->update(
        "{$wpdb->prefix}CategoryType",
        [ 'CategoryType_Name' => $name, 'updated_at' => current_time( 'mysql' ) ],
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
 * AJAX: Видалення типу
 */
add_action( 'wp_ajax_fb_delete_category_type', 'fb_ajax_delete_category_type' );
function fb_ajax_delete_category_type(): void {
    fb_category_type_verify_request();
    global $wpdb;

    $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідний ID.', 'family-budget' ) ] );
    }

    $family_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT Family_ID FROM {$wpdb->prefix}CategoryType WHERE id = %d", $id ) );
    if ( ! $family_id || ! function_exists( 'fb_user_has_family_access' ) || ! fb_user_has_family_access( $family_id ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Доступ заборонено.', 'family-budget' ) ] );
    }

    $wpdb->delete( "{$wpdb->prefix}CategoryType", [ 'id' => $id ], [ '%d' ] );
    wp_send_json_success();
}

/**
 * AJAX: Зміна порядку (Drag & Drop)
 */
add_action( 'wp_ajax_fb_reorder_category_types', 'fb_ajax_reorder_category_types' );
function fb_ajax_reorder_category_types(): void {
    fb_category_type_verify_request();
    global $wpdb;

    if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
        wp_send_json_error( [ 'message' => esc_html__( 'Невалідні дані сортування.', 'family-budget' ) ] );
    }

    $order = array_map( 'absint', wp_unslash( $_POST['order'] ) );

    foreach ( $order as $index => $id ) {
        if ( $id > 0 ) {
            $wpdb->update(
                "{$wpdb->prefix}CategoryType",
                [ 'CategoryType_Order' => $index + 1 ],
                [ 'id' => $id ],
                [ '%d' ],
                [ '%d' ]
            );
        }
    }

    wp_send_json_success();
}