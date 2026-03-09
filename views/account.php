<?php
/**
 * Модуль Рахунків (Family Budget)
 * Назва файлу: account.php
 * @package FamilyBudget
 */

// Захист від прямого доступу
defined( 'ABSPATH' ) || exit;

/**
 * Двошарова перевірка безпеки для AJAX-обробників цього модуля.
 *
 * @since  1.3.4
 * @param  string $action Ім'я nonce-дії WordPress.
 * @return void
 */
function fb_accounts_verify_request( string $action = 'fb_account_nonce' ): void {
	fb_verify_ajax_request( $action );
}

/**
 * Отримання відфільтрованих даних рахунків
 */
function fb_get_accounts_data( $family_id = 0, $type_id = 0 ) {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) return [];

    $family_id = absint( $family_id );
    $type_id   = absint( $type_id );

    $query = "
        SELECT a.*, f.Family_Name, t.AccountType_Name 
        FROM {$wpdb->prefix}Account AS a
        INNER JOIN {$wpdb->prefix}Family AS f ON f.id = a.Family_ID
        INNER JOIN {$wpdb->prefix}AccountType AS t ON t.id = a.AccountType_ID
        INNER JOIN {$wpdb->prefix}UserFamily AS u ON u.Family_ID = f.id
        WHERE u.User_ID = %d
    ";

    $args = [ $user_id ];

    if ( $family_id > 0 ) {
        $query .= " AND a.Family_ID = %d";
        $args[] = $family_id;
    }

    if ( $type_id > 0 ) {
        $query .= " AND a.AccountType_ID = %d";
        $args[] = $type_id;
    }

    $query .= " ORDER BY a.Account_Default DESC, a.Account_Order DESC";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    return $wpdb->get_results( $wpdb->prepare( $query, $args ) );
}

/**
 * Шорткод для виводу інтерфейсу рахунків
 */
function fb_shortcode_accounts() {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
    }

    $families     = function_exists( 'fb_get_families' ) ? fb_get_families() : [];
    $filter_types = function_exists( 'fb_get_account_type' ) ? fb_get_account_type() : [];
    // ВИПРАВЛЕНО: Використовуємо нову функцію fb_get_all_account_type
    $add_types    = function_exists( 'fb_get_all_account_type' ) ? fb_get_all_account_type() : [];

    wp_enqueue_style( 'fb-account-css', FB_PLUGIN_URL . 'css/account.css', [], time() );
    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_script( 'fb-account-js', FB_PLUGIN_URL . 'js/account.js', [ 'jquery', 'jquery-ui-sortable' ], '1.0.4', true );

    wp_localize_script( 'fb-account-js', 'fbAccountObj', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fb_account_nonce' ),
        'confirm'  => esc_html__( 'Ви впевнені, що хочете видалити цей рахунок?', 'family-budget' ),
    ] );

    ob_start();
    ?>
    <div class="fb-accounts-wrapper">
        <div class="fb-accounts-controls">

            <div class="fb-filter-group">
                <select id="fb-filter-family" class="fb-compact-input">
                    <option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
                        $f_id   = fb_extract_value( $f, ['id', 'ID'] );
                        $f_name = fb_extract_value( $f, ['Family_Name', 'name', 'Name'] );
                        ?>
                        <option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <select id="fb-filter-type" class="fb-compact-input">
                    <option value="0"><?php esc_html_e( 'Всі типи', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $filter_types ) ) : foreach ( $filter_types as $t ) :
                        $t_id   = fb_extract_value( $t, ['id', 'ID'] );
                        $t_name = fb_extract_value( $t, ['AccountType_Name', 'name', 'Name'] );
                        ?>
                        <option value="<?php echo esc_attr( $t_id ); ?>"><?php echo esc_html( $t_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>

            <form id="fb-add-account-form" class="fb-add-group">
                <select name="family_id" required class="fb-compact-input">
                    <option value="" disabled selected><?php esc_html_e( 'Оберіть родину', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
                        $f_id   = fb_extract_value( $f, ['id', 'ID'] );
                        $f_name = fb_extract_value( $f, ['Family_Name', 'name', 'Name'] );
                        ?>
                        <option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <select name="type_id" required class="fb-compact-input">
                    <option value="" disabled selected><?php esc_html_e( 'Оберіть тип', 'family-budget' ); ?></option>
                    <?php if ( ! empty( $add_types ) ) : foreach ( $add_types as $at ) :
                        $at_id   = fb_extract_value( $at, ['id', 'ID'] );
                        $at_name = fb_extract_value( $at, ['AccountType_Name', 'name', 'Name', 'title', 'category_name'] );
                        ?>
                        <option value="<?php echo esc_attr( $at_id ); ?>"><?php echo esc_html( $at_name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>

                <input type="text" name="account_name" required placeholder="<?php esc_attr_e( 'Назва рахунку', 'family-budget' ); ?>" class="fb-compact-input">
                <button type="submit" class="fb-btn-primary"><?php esc_html_e( 'Додати', 'family-budget' ); ?></button>
            </form>

        </div>

        <div class="fb-accounts-table-container">
            <table class="fb-table">
                <thead>
                <tr>
                    <th width="30px"></th>
                    <th><?php esc_html_e( 'Родина', 'family-budget' ); ?></th>
                    <th><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
                    <th><?php esc_html_e( 'Назва рахунку', 'family-budget' ); ?></th>
                    <th width="120px" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                </tr>
                </thead>
                <tbody id="fb-accounts-tbody">
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_accounts', 'fb_shortcode_accounts' );

/**
 * AJAX: Завантаження таблиці
 */
add_action( 'wp_ajax_fb_load_accounts', 'fb_ajax_load_accounts' );
function fb_ajax_load_accounts() {
    fb_accounts_verify_request();

    $family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
    $type_id   = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;

    $accounts = fb_get_accounts_data( $family_id, $type_id );

    ob_start();
    if ( empty( $accounts ) ) {
        echo '<tr><td colspan="5" class="text-center">' . esc_html__( 'Записів не знайдено', 'family-budget' ) . '</td></tr>';
    } else {
        foreach ( $accounts as $acc ) {
            $is_default = (int) $acc->Account_Default === 1;
            $star_class = $is_default ? 'fb-star is-default' : 'fb-star';

            $family_name = ! empty( $acc->Family_Name ) ? $acc->Family_Name : '—';
            $type_name   = ! empty( $acc->AccountType_Name ) ? $acc->AccountType_Name : '—';
            ?>
            <tr data-id="<?php echo esc_attr( $acc->id ); ?>">
                <td class="fb-drag-handle">☰</td>
                <td><?php echo esc_html( $family_name ); ?></td>
                <td><?php echo esc_html( $type_name ); ?></td>
                <td class="fb-name-col">
                    <span class="fb-acc-name-text"><?php echo esc_html( $acc->Account_Name ); ?></span>
                    <input type="text" class="fb-acc-name-input hidden" value="<?php echo esc_attr( $acc->Account_Name ); ?>">
                </td>
                <td class="fb-actions text-center">
                    <span class="<?php echo esc_attr( $star_class ); ?>" data-action="set_default" title="<?php esc_attr_e('Головна', 'family-budget'); ?>">★</span>
                    <span class="fb-edit-btn" data-action="edit" title="<?php esc_attr_e('Редагувати', 'family-budget'); ?>">✎</span>
                    <span class="fb-save-btn hidden" data-action="save" title="<?php esc_attr_e('Зберегти', 'family-budget'); ?>">✔</span>
                    <span class="fb-delete-btn" data-action="delete" title="<?php esc_attr_e('Видалити', 'family-budget'); ?>">🗑</span>
                </td>
            </tr>
            <?php
        }
    }
    $html = ob_get_clean();
    wp_send_json_success( [ 'html' => $html ] );
}

/**
 * AJAX: Додавання рахунку
 */
add_action( 'wp_ajax_fb_add_account', 'fb_ajax_add_account' );
function fb_ajax_add_account() {
    fb_accounts_verify_request();
    global $wpdb;

    $user_id   = get_current_user_id();
    $family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
    $type_id   = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
    $name      = isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) ) : '';

    $has_access = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
        $user_id, $family_id
    ) );

    if ( ! $has_access ) {
        wp_send_json_error( [ 'message' => 'Немає доступу до обраної родини.' ] );
    }

    if ( empty( $name ) ) {
        wp_send_json_error( [ 'message' => 'Назва рахунку обов\'язкова.' ] );
    }

    $max_order = $wpdb->get_var( $wpdb->prepare(
        "SELECT MAX(Account_Order) FROM {$wpdb->prefix}Account WHERE Family_ID = %d",
        $family_id
    ) );
    $new_order = $max_order ? $max_order + 1 : 1;

    $wpdb->insert(
        "{$wpdb->prefix}Account",
        [
            'Family_ID'      => $family_id,
            'AccountType_ID' => $type_id,
            'Account_Name'   => $name,
            'Account_Order'  => $new_order,
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' )
        ],
        [ '%d', '%d', '%s', '%d', '%s', '%s' ]
    );

    wp_send_json_success( [ 'message' => 'Рахунок додано успішно.' ] );
}

/**
 * AJAX: Встановлення головного (Default)
 */
add_action( 'wp_ajax_fb_set_default_account', 'fb_ajax_set_default_account' );
function fb_ajax_set_default_account() {
    fb_accounts_verify_request();
    global $wpdb;

    $id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $user_id = get_current_user_id();

    $account = $wpdb->get_row( $wpdb->prepare( "SELECT Family_ID FROM {$wpdb->prefix}Account WHERE id = %d", $id ) );
    if ( ! $account ) wp_send_json_error();

    $has_access = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
        $user_id, $account->Family_ID
    ) );

    if ( ! $has_access ) {
        wp_send_json_error( [ 'message' => 'Помилка доступу.' ] );
    }

    $wpdb->update( "{$wpdb->prefix}Account", [ 'Account_Default' => 0 ], [ 'Family_ID' => $account->Family_ID ], [ '%d' ], [ '%d' ] );
    $wpdb->update( "{$wpdb->prefix}Account", [ 'Account_Default' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

    wp_send_json_success();
}

/**
 * AJAX: Видалення рахунку
 */
add_action( 'wp_ajax_fb_delete_account', 'fb_ajax_delete_account' );
function fb_ajax_delete_account() {
    fb_accounts_verify_request();
    global $wpdb;

    $id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $user_id = get_current_user_id();

    $account = $wpdb->get_row( $wpdb->prepare( "SELECT Family_ID FROM {$wpdb->prefix}Account WHERE id = %d", $id ) );
    if ( $account ) {
        $has_access = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
            $user_id, $account->Family_ID
        ) );

        if ( $has_access ) {
            $wpdb->delete( "{$wpdb->prefix}Account", [ 'id' => $id ], [ '%d' ] );
            wp_send_json_success();
        }
    }
    wp_send_json_error( [ 'message' => 'Неможливо видалити рахунок.' ] );
}

/**
 * AJAX: Збереження зміненої назви (Inline-edit)
 */
add_action( 'wp_ajax_fb_edit_account', 'fb_ajax_edit_account' );
function fb_ajax_edit_account() {
    fb_accounts_verify_request();
    global $wpdb;

    $id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( empty( $name ) ) wp_send_json_error( [ 'message' => 'Назва не може бути порожньою.' ] );

    $wpdb->update(
        "{$wpdb->prefix}Account",
        [ 'Account_Name' => $name, 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => $id ],
        [ '%s', '%s' ],
        [ '%d' ]
    );
    wp_send_json_success();
}

/**
 * AJAX: Зміна порядку (Drag and drop)
 */
add_action( 'wp_ajax_fb_reorder_accounts', 'fb_ajax_reorder_accounts' );
function fb_ajax_reorder_accounts() {
    fb_accounts_verify_request();
    global $wpdb;

    if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
        wp_send_json_error();
    }

    $order = array_map( 'absint', wp_unslash( $_POST['order'] ) );

    foreach ( $order as $index => $id ) {
        $wpdb->update( "{$wpdb->prefix}Account", [ 'Account_Order' => $index + 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
    }

    wp_send_json_success();
}