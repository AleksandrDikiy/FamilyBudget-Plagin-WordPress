<?php
/**
 * МОДУЛЬ AMOUNT-TYPE (Типи операцій)
 * Версія: 1.0.24.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Обробка дій CRUD для типів операцій
 */
add_action( 'template_redirect', 'fb_handle_amount_type_actions' );

function fb_handle_amount_type_actions() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'AmountType';
    $referer = wp_get_raw_referer() ? wp_get_raw_referer() : $_SERVER['REQUEST_URI'];

    // Додавання/Редагування
    if ( isset( $_POST['fb_action'] ) && 'save_amount_type' === $_POST['fb_action'] ) {
        if ( ! wp_verify_nonce( $_POST['fb_amount_type_nonce'], 'fb_save_amount_type' ) ) {
            wp_die( 'Security check failed' );
        }

        $name = sanitize_text_field( $_POST['amount_type_name'] );
        $id = isset( $_POST['amount_type_id'] ) ? intval( $_POST['amount_type_id'] ) : 0;

        if ( $id > 0 ) {
            $wpdb->update( $table, array( 'AmountType_Name' => $name ), array( 'id' => $id ) );
        } else {
            $wpdb->insert( $table, array( 'AmountType_Name' => $name, 'created_at' => current_time( 'mysql' ) ) );
        }

        wp_safe_redirect( remove_query_arg( 'edit', $referer ) );
        exit;
    }

    // Видалення
    if ( isset( $_GET['del_amount_type'] ) ) {
        $id = intval( $_GET['del_amount_type'] );

        // Перевірка на використання в таблиці транзакцій (Amount)
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}Amount WHERE AmountType_ID = %d", $id ) );

        if ( $count == 0 ) {
            $wpdb->delete( $table, array( 'id' => $id ) );
            wp_safe_redirect( remove_query_arg( 'del_amount_type', $referer ) );
            exit;
        } else {
            set_transient( 'fb_amt_error', 'Неможливо видалити: цей тип використовується у фінансових записах.', 30 );
        }
    }
}

/**
 * ШОРТКОД [fb_amount_type]
 */
function fb_render_amount_type_interface() {
    if ( ! current_user_can( 'manage_options' ) ) return 'Доступ обмежено.';

    global $wpdb;
    $table = $wpdb->prefix . 'AmountType';

    if ( $error = get_transient( 'fb_amt_error' ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        delete_transient( 'fb_amt_error' );
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
        <h2><i class="dashicons dashicons-money-alt"></i> Довідник: Типи операцій</h2>

        <form method="POST" class="fb-card">
            <?php wp_nonce_field( 'fb_save_amount_type', 'fb_amount_type_nonce' ); ?>
            <input type="hidden" name="fb_action" value="save_amount_type">
            <?php if ( $edit_item ) : ?>
                <input type="hidden" name="amount_type_id" value="<?php echo $edit_item->id; ?>">
            <?php endif; ?>

            <input type="text" name="amount_type_name"
                   placeholder="Напр.: Планова сума, Факт"
                   value="<?php echo $edit_item ? esc_attr( $edit_item->AmountType_Name ) : ''; ?>" required>

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
                <th>Найменування типу операції</th>
                <th width="100">Дії</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $types as $t ) : ?>
                <tr>
                    <td><?php echo $t->id; ?></td>
                    <td><strong><?php echo esc_html( $t->AmountType_Name ); ?></strong></td>
                    <td>
                        <a href="<?php echo add_query_arg( 'edit', $t->id ); ?>" title="Редагувати">📝</a>
                        <a href="<?php echo esc_url( add_query_arg( 'del_amount_type', $t->id ) ); ?>"
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
add_shortcode( 'fb_amount_type', 'fb_render_amount_type_interface' );