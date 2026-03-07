<?php
/**
 * МОДУЛЬ ACCOUNT-TYPE (Типи рахунків)
 * Версія: 1.0.24.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Обробка дій CRUD для типів рахунків
 */
add_action( 'template_redirect', 'fb_handle_account_type_actions' );

function fb_handle_account_type_actions() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'AccountType';
    $referer = wp_get_raw_referer() ? wp_get_raw_referer() : $_SERVER['REQUEST_URI'];

    if ( isset( $_POST['fb_action'] ) && 'save_account_type' === $_POST['fb_action'] ) {
        if ( ! wp_verify_nonce( $_POST['fb_account_type_nonce'], 'fb_save_account_type' ) ) {
            wp_die( 'Security check failed' );
        }

        $name = sanitize_text_field( $_POST['account_type_name'] );
        $id = isset( $_POST['account_type_id'] ) ? intval( $_POST['account_type_id'] ) : 0;

        if ( $id > 0 ) {
            $wpdb->update( $table, array( 'AccountType_Name' => $name ), array( 'id' => $id ) );
        } else {
            $wpdb->insert( $table, array( 'AccountType_Name' => $name, 'created_at' => current_time( 'mysql' ) ) );
        }

        wp_safe_redirect( remove_query_arg( 'edit', $referer ) );
        exit;
    }

    if ( isset( $_GET['del_account_type'] ) ) {
        $id = intval( $_GET['del_account_type'] );
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}Account WHERE AccountType_ID = %d", $id ) );

        if ( $count == 0 ) {
            $wpdb->delete( $table, array( 'id' => $id ) );
            wp_safe_redirect( remove_query_arg( 'del_account_type', $referer ) );
            exit;
        } else {
            set_transient( 'fb_acc_error', 'Неможливо видалити: тип використовується в активних рахунках.', 30 );
        }
    }
}

/**
 * ШОРТКОД [fb_account_type]
 */
function fb_render_account_type_interface() {
    if ( ! current_user_can( 'manage_options' ) ) return 'Доступ обмежено.';

    global $wpdb;
    $table = $wpdb->prefix . 'AccountType';

    if ( $error = get_transient( 'fb_acc_error' ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        delete_transient( 'fb_acc_error' );
    }

    $edit_item = null;
    if ( isset( $_GET['edit'] ) ) {
        $edit_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $_GET['edit'] ) );
    }

    $types = $wpdb->get_results( "SELECT * FROM $table ORDER BY id ASC" );

    ob_start();
    //fb_render_nav();
    ?>
    <div class="fb-container">
        <h2><i class="dashicons dashicons-bank"></i> Довідник: Типи рахунків</h2>

        <form method="POST" class="fb-card">
            <?php wp_nonce_field( 'fb_save_account_type', 'fb_account_type_nonce' ); ?>
            <input type="hidden" name="fb_action" value="save_account_type">
            <?php if ( $edit_item ) : ?>
                <input type="hidden" name="account_type_id" value="<?php echo $edit_item->id; ?>">
            <?php endif; ?>

            <input type="text" name="account_type_name"
                   placeholder="Назва (напр. Картка, Готівка)"
                   value="<?php echo $edit_item ? esc_attr( $edit_item->AccountType_Name ) : ''; ?>" required>

            <button type="submit" class="fb-btn-save">
                <?php echo $edit_item ? 'Оновити' : 'Додати'; ?>
            </button>
            <?php if ( $edit_item ) : ?>
                <a href="<?php echo remove_query_arg('edit'); ?>" class="fb-btn-cancel">Скасувати</a>
            <?php endif; ?>
        </form>

        <table class="fb-table">
            <thead>
            <tr>
                <th width="50">ID</th>
                <th>Назва типу рахунку</th>
                <th width="100">Дії</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $types as $t ) : ?>
                <tr>
                    <td><?php echo $t->id; ?></td>
                    <td><strong><?php echo esc_html( $t->AccountType_Name ); ?></strong></td>
                    <td>
                        <a href="<?php echo add_query_arg( 'edit', $t->id ); ?>">📝</a>
                        <a href="<?php echo esc_url( add_query_arg( 'del_account_type', $t->id ) ); ?>"
                           onclick="return confirm('Видалити цей тип?')" style="color:red;">🗑️</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_account_type', 'fb_render_account_type_interface' );