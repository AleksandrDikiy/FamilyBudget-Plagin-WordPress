<?php
/**
 * Модуль Категорій (Family Budget)
 * Назва файлу: category.php
 * @package FamilyBudget
 */

// Захист від прямого доступу
defined( 'ABSPATH' ) || exit;

/**
 * Двошарова перевірка безпеки: авторизація + nonce
 */
/**
 * Двошарова перевірка безпеки для AJAX-обробників цього модуля.
 *
 * @since  1.3.1
 * @param  string $action Ім'я nonce-дії WordPress.
 * @return void
 */
function fb_category_verify_request( string $action = 'fb_category_nonce' ): void {
	fb_verify_ajax_request( $action );
}

/**
 * Отримання відфільтрованих даних категорій
 */
function fb_get_category_data( $family_id = 0, $type_id = 0 ) {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) return [];

    $family_id = absint( $family_id );
    $type_id   = absint( $type_id );

    $query = "
        SELECT c.*, f.Family_Name, ct.CategoryType_Name,
               (SELECT COUNT(*) FROM {$wpdb->prefix}CategoryParam WHERE Category_ID = c.id) as params_count
        FROM {$wpdb->prefix}Category AS c
        INNER JOIN {$wpdb->prefix}Family AS f ON c.Family_ID = f.id
        INNER JOIN {$wpdb->prefix}CategoryType AS ct ON c.CategoryType_ID = ct.id
        INNER JOIN {$wpdb->prefix}UserFamily AS u ON u.Family_ID = f.id
        WHERE u.User_ID = %d
    ";

    $args = [ $user_id ];

    if ( $family_id > 0 ) {
        $query .= " AND c.Family_ID = %d";
        $args[] = $family_id;
    }

    if ( $type_id > 0 ) {
        $query .= " AND c.CategoryType_ID = %d";
        $args[] = $type_id;
    }

    $query .= " ORDER BY c.CategoryType_ID ASC, c.Category_Order ASC";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return $wpdb->get_results( $wpdb->prepare( $query, $args ) );
}

/**
 * Шорткод для виводу інтерфейсу категорій
 */
function fb_shortcode_categories_interface() {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
    }

    $families     = function_exists( 'fb_get_families' ) ? fb_get_families() : [];
    $filter_types = function_exists( 'fb_get_category_type' ) ? fb_get_category_type() : [];
    $add_types    = function_exists( 'fb_get_all_category_types' ) ? fb_get_all_category_types() : [];
    $param_types  = function_exists( 'fb_get_parameter_types' ) ? fb_get_parameter_types() : [];

    wp_enqueue_style( 'fb-category-css', plugin_dir_url( __FILE__ ) . 'css/category.css', [], time() );
    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_script( 'fb-category-js', plugin_dir_url( __FILE__ ) . 'js/category.js', [ 'jquery', 'jquery-ui-sortable' ], time(), true );

    wp_localize_script( 'fb-category-js', 'fbCatObj', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fb_category_nonce' ),
        'confirm'  => esc_html__( 'Ви впевнені, що хочете видалити цей запис?', 'family-budget' ),
    ] );

    ob_start();
    ?>
    <div class="fb-category-wrapper">
        <div class="fb-category-controls">

            <div class="fb-filter-group">
                <select id="fb-filter-cat-family" class="fb-compact-input">
                    <option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
                        $f_id   = fb_extract_value( $f, ['id', 'ID'] );
                        $f_name = fb_extract_value( $f, ['Family_Name', 'name'] );
                        ?>
                        <option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <select id="fb-filter-cat-type" class="fb-compact-input">
                    <option value="0"><?php esc_html_e( 'Всі типи', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $filter_types ) ) : foreach ( $filter_types as $t ) :
                        $t_id   = fb_extract_value( $t, ['id', 'ID'] );
                        $t_name = fb_extract_value( $t, ['CategoryType_Name', 'name'] );
                        ?>
                        <option value="<?php echo esc_attr( $t_id ); ?>"><?php echo esc_html( $t_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>

            <form id="fb-add-category-form" class="fb-add-group">
                <select name="family_id" required class="fb-compact-input">
                    <option value="" disabled selected><?php esc_html_e( 'Оберіть родину', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
                        $f_id   = fb_extract_value( $f, ['id', 'ID'] );
                        $f_name = fb_extract_value( $f, ['Family_Name', 'name'] );
                        ?>
                        <option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <select name="type_id" required class="fb-compact-input">
                    <option value="" disabled selected><?php esc_html_e( 'Оберіть тип', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $add_types ) ) : foreach ( $add_types as $at ) :
                        $at_id   = fb_extract_value( $at, ['id', 'ID'] );
                        $at_name = fb_extract_value( $at, ['CategoryType_Name', 'name'] );
                        ?>
                        <option value="<?php echo esc_attr( $at_id ); ?>"><?php echo esc_html( $at_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <input type="text" name="category_name" required placeholder="<?php esc_attr_e( 'Назва категорії', 'family-budget' ); ?>" class="fb-compact-input">
                <button type="submit" class="fb-btn-primary"><?php esc_html_e( 'Додати', 'family-budget' ); ?></button>
            </form>

        </div>

        <div class="fb-category-table-container">
            <table class="fb-table">
                <thead>
                <tr>
                    <th width="30px"></th>
                    <th><?php esc_html_e( 'Родина', 'family-budget' ); ?></th>
                    <th width="120px"><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
                    <th><?php esc_html_e( 'Назва категорії', 'family-budget' ); ?></th>
                    <th width="90px" class="text-center"><?php esc_html_e( 'Параметри', 'family-budget' ); ?></th>
                    <th width="130px" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                </tr>
                </thead>
                <tbody id="fb-category-tbody">
                </tbody>
            </table>
        </div>

        <div id="fb-params-modal" class="fb-modal hidden">
            <div class="fb-modal-content">
                <div class="fb-modal-header">
                    <h3 id="fb-modal-cat-name"><?php esc_html_e( 'Параметри', 'family-budget' ); ?></h3>
                    <span class="fb-modal-close">&times;</span>
                </div>
                <div class="fb-modal-body">
                    <form id="fb-add-param-form" class="fb-add-group" style="margin-bottom: 15px;">
                        <input type="hidden" name="modal_category_id" id="modal_category_id" value="">
                        <input type="hidden" name="modal_family_id" id="modal_family_id" value="">
                        <select name="param_type_id" required class="fb-compact-input">
                            <option value="" disabled selected><?php esc_html_e( 'Тип параметра', 'family-budget' ); ?></option>
                            <?php if ( ! empty( $param_types ) ) : foreach ( $param_types as $pt ) :
                                $pt_id   = fb_extract_value( $pt, ['id', 'ID'] );
                                $pt_name = fb_extract_value( $pt, ['ParameterType_Name', 'name'] );
                                ?>
                                <option value="<?php echo esc_attr( $pt_id ); ?>"><?php echo esc_html( $pt_name ); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                        <input type="text" name="param_name" required placeholder="<?php esc_attr_e( 'Назва', 'family-budget' ); ?>" class="fb-compact-input">
                        <button type="submit" class="fb-btn-primary"><?php esc_html_e( '+', 'family-budget' ); ?></button>
                    </form>

                    <table class="fb-table">
                        <thead>
                        <tr>
                            <th width="30px"></th>
                            <th><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
                            <th><?php esc_html_e( 'Назва параметра', 'family-budget' ); ?></th>
                            <th width="70px" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                        </tr>
                        </thead>
                        <tbody id="fb-params-tbody">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_categories', 'fb_shortcode_categories_interface' );

/**
 * ==========================================
 * AJAX ОБРОБНИКИ ДЛЯ КАТЕГОРІЙ
 * ==========================================
 */

add_action( 'wp_ajax_fb_ajax_load_categories', 'fb_ajax_load_categories' );
function fb_ajax_load_categories() {
    fb_category_verify_request();

    $family_id  = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
    $type_id    = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
    $categories = fb_get_category_data( $family_id, $type_id );

    ob_start();
    if ( empty( $categories ) ) {
        echo '<tr><td colspan="6" class="text-center">' . esc_html__( 'Записів не знайдено', 'family-budget' ) . '</td></tr>';
    } else {
        foreach ( $categories as $cat ) {
            // Визначення кольору (спрощена логіка по назві або ID, налаштуй під себе)
            $type_lower = mb_strtolower( $cat->CategoryType_Name );
            $type_class = ( strpos( $type_lower, 'витрат' ) !== false || $cat->CategoryType_ID == 1 ) ? 'fb-color-red' : 'fb-color-green';
            ?>
            <tr data-id="<?php echo esc_attr( $cat->id ); ?>" data-family-id="<?php echo esc_attr( $cat->Family_ID ); ?>" data-cat-name="<?php echo esc_attr( $cat->Category_Name ); ?>">
                <td class="fb-drag-handle">☰</td>
                <td><?php echo esc_html( $cat->Family_Name ); ?></td>
                <td class="<?php echo esc_attr( $type_class ); ?>"><?php echo esc_html( $cat->CategoryType_Name ); ?></td>
                <td class="fb-edit-col">
                    <span class="fb-text-val fb-cat-name-val"><strong><?php echo esc_html( $cat->Category_Name ); ?></strong></span>
                    <input type="text" class="fb-input-val fb-name-input hidden fb-compact-input" value="<?php echo esc_attr( $cat->Category_Name ); ?>">
                </td>
                <td class="text-center">
                    <span class="fb-badge"><?php echo esc_html( $cat->params_count ); ?></span>
                </td>
                <td class="fb-actions text-center">
                    <span class="fb-param-btn" data-action="params" title="<?php esc_attr_e('Параметри', 'family-budget'); ?>">⚙️</span>
                    <span class="fb-edit-btn" data-action="edit" title="<?php esc_attr_e('Редагувати', 'family-budget'); ?>">📝</span>
                    <span class="fb-save-btn hidden" data-action="save" title="<?php esc_attr_e('Зберегти', 'family-budget'); ?>">✔</span>
                    <span class="fb-delete-btn" data-action="delete" title="<?php esc_attr_e('Видалити', 'family-budget'); ?>">🗑️</span>
                </td>
            </tr>
            <?php
        }
    }
    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

add_action( 'wp_ajax_fb_ajax_add_category', 'fb_ajax_add_category' );
function fb_ajax_add_category() {
    fb_category_verify_request();
    global $wpdb;

    $user_id   = get_current_user_id();
    $family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
    $type_id   = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
    $name      = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';

    if ( ! function_exists('fb_user_has_family_access') || ! fb_user_has_family_access( $family_id ) ) {
        wp_send_json_error( [ 'message' => 'Немає доступу до обраної родини.' ] );
    }

    if ( empty( $name ) ) wp_send_json_error( [ 'message' => 'Назва обов\'язкова.' ] );

    $max_order = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(Category_Order) FROM {$wpdb->prefix}Category WHERE Family_ID = %d AND CategoryType_ID = %d", $family_id, $type_id ) );

    $wpdb->insert(
        "{$wpdb->prefix}Category",
        [
            'Family_ID'       => $family_id,
            'CategoryType_ID' => $type_id,
            'Category_Name'   => $name,
            'Category_Order'  => $max_order ? $max_order + 1 : 1,
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' )
        ],
        [ '%d', '%d', '%s', '%d', '%s', '%s' ]
    );
    wp_send_json_success( [ 'message' => 'Категорію додано.' ] );
}

add_action( 'wp_ajax_fb_ajax_update_category_name', 'fb_ajax_update_category_name' );
function fb_ajax_update_category_name() {
    fb_category_verify_request();
    global $wpdb;

    $id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( empty( $name ) ) wp_send_json_error();

    $wpdb->update( "{$wpdb->prefix}Category", [ 'Category_Name' => $name, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
    wp_send_json_success();
}

add_action( 'wp_ajax_fb_ajax_delete_category', 'fb_ajax_delete_category' );
function fb_ajax_delete_category() {
    fb_category_verify_request();
    global $wpdb;
    $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

    // Перевірка доступу перед видаленням
    $cat = $wpdb->get_row( $wpdb->prepare("SELECT Family_ID FROM {$wpdb->prefix}Category WHERE id = %d", $id) );
    if ( $cat && function_exists('fb_user_has_family_access') && fb_user_has_family_access($cat->Family_ID) ) {
        $wpdb->delete( "{$wpdb->prefix}Category", [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success();
    }
    wp_send_json_error( [ 'message' => 'Помилка видалення.' ] );
}

add_action( 'wp_ajax_fb_ajax_move_category', 'fb_ajax_move_category' );
function fb_ajax_move_category() {
    fb_category_verify_request();
    global $wpdb;
    if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) wp_send_json_error();

    $order = array_map( 'absint', wp_unslash( $_POST['order'] ) );
    foreach ( $order as $index => $id ) {
        $wpdb->update( "{$wpdb->prefix}Category", [ 'Category_Order' => $index + 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
    }
    wp_send_json_success();
}

/**
 * ==========================================
 * AJAX ОБРОБНИКИ ДЛЯ ПАРАМЕТРІВ КАТЕГОРІЙ
 * ==========================================
 */

add_action( 'wp_ajax_fb_ajax_load_category_params', 'fb_ajax_load_category_params' );
function fb_ajax_load_category_params() {
    fb_category_verify_request();
    global $wpdb;

    $category_id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;

    $params = $wpdb->get_results( $wpdb->prepare(
        "SELECT cp.*, pt.ParameterType_Name
        FROM {$wpdb->prefix}CategoryParam cp
        JOIN {$wpdb->prefix}ParameterType pt ON cp.ParameterType_ID = pt.id
        WHERE cp.Category_ID = %d 
        ORDER BY cp.CategoryParam_Order ASC",
        $category_id
    ) );

    ob_start();
    if ( empty( $params ) ) {
        echo '<tr><td colspan="4" class="text-center">' . esc_html__( 'Параметрів немає', 'family-budget' ) . '</td></tr>';
    } else {
        foreach ( $params as $p ) {
            ?>
            <tr data-id="<?php echo esc_attr( $p->id ); ?>">
                <td class="fb-drag-handle-param">☰</td>
                <td><?php echo esc_html( $p->ParameterType_Name ); ?></td>
                <td class="fb-edit-col">
                    <span class="fb-text-val fb-p-name-val"><?php echo esc_html( $p->CategoryParam_Name ); ?></span>
                    <input type="text" class="fb-input-val fb-p-name-input hidden fb-compact-input" value="<?php echo esc_attr( $p->CategoryParam_Name ); ?>">
                </td>
                <td class="fb-actions text-center">
                    <span class="fb-edit-btn" data-action="edit-param" title="Редагувати">📝</span>
                    <span class="fb-save-btn hidden" data-action="save-param" title="Зберегти">✔</span>
                    <span class="fb-delete-btn" data-action="delete-param" title="Видалити">🗑️</span>
                </td>
            </tr>
            <?php
        }
    }
    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

add_action( 'wp_ajax_fb_ajax_add_category_param', 'fb_ajax_add_category_param' );
function fb_ajax_add_category_param() {
    fb_category_verify_request();
    global $wpdb;

    $user_id     = get_current_user_id();
    $family_id   = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
    $category_id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;
    $type_id     = isset( $_POST['param_type_id'] ) ? absint( wp_unslash( $_POST['param_type_id'] ) ) : 0;
    $name        = isset( $_POST['param_name'] ) ? sanitize_text_field( wp_unslash( $_POST['param_name'] ) ) : '';

    if ( empty( $name ) || ! $category_id ) wp_send_json_error();

    $max_order = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(CategoryParam_Order) FROM {$wpdb->prefix}CategoryParam WHERE Category_ID = %d", $category_id ) );

    $wpdb->insert(
        "{$wpdb->prefix}CategoryParam",
        [
            'User_ID'            => $user_id,
            'Family_ID'          => $family_id,
            'Category_ID'        => $category_id,
            'ParameterType_ID'   => $type_id,
            'CategoryParam_Name' => $name,
            'CategoryParam_Order'=> $max_order ? $max_order + 1 : 1,
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' )
        ],
        [ '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
    );
    wp_send_json_success();
}

add_action( 'wp_ajax_fb_ajax_edit_category_param', 'fb_ajax_edit_category_param' );
function fb_ajax_edit_category_param() {
    fb_category_verify_request();
    global $wpdb;
    $id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    if ( empty( $name ) ) wp_send_json_error();

    $wpdb->update( "{$wpdb->prefix}CategoryParam", [ 'CategoryParam_Name' => $name, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
    wp_send_json_success();
}

add_action( 'wp_ajax_fb_ajax_delete_category_param', 'fb_ajax_delete_category_param' );
function fb_ajax_delete_category_param() {
    fb_category_verify_request();
    global $wpdb;
    $id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $wpdb->delete( "{$wpdb->prefix}CategoryParam", [ 'id' => $id ], [ '%d' ] );
    wp_send_json_success();
}

add_action( 'wp_ajax_fb_ajax_move_category_param', 'fb_ajax_move_category_param' );
function fb_ajax_move_category_param() {
    fb_category_verify_request();
    global $wpdb;
    if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) wp_send_json_error();

    $order = array_map( 'absint', wp_unslash( $_POST['order'] ) );
    foreach ( $order as $index => $id ) {
        $wpdb->update( "{$wpdb->prefix}CategoryParam", [ 'CategoryParam_Order' => $index + 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
    }
    wp_send_json_success();
}