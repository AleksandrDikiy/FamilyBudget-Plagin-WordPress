<?php
/**
 * МОДУЛЬ CATEGORY-TYPE (Типи категорій)
 * * Керування типами категорій:
 * - Додавання, редагування та видалення системних типів
 * - Перевірка на використання перед видаленням
 * * @package FamilyBudget
 * @version 1.0.24.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Обробка дій CRUD для типів категорій
 */
add_action( 'template_redirect', 'fb_handle_category_type_actions' );

function fb_handle_category_type_actions() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'CategoryType';
    $referer = wp_get_raw_referer() ? wp_get_raw_referer() : $_SERVER['REQUEST_URI'];

    // Додавання/Редагування
    if ( isset( $_POST['fb_action'] ) && 'save_cat_type' === $_POST['fb_action'] ) {
        if ( ! wp_verify_nonce( $_POST['fb_cat_type_nonce'], 'fb_save_category_type' ) ) {
            wp_die( 'Security check failed' );
        }

        $name = sanitize_text_field( $_POST['cat_type_name'] );
        $id = isset( $_POST['cat_type_id'] ) ? intval( $_POST['cat_type_id'] ) : 0;

        if ( $id > 0 ) {
            $wpdb->update( $table, array( 'CategoryType_Name' => $name ), array( 'id' => $id ) );
        } else {
            $wpdb->insert( $table, array( 'CategoryType_Name' => $name, 'created_at' => current_time( 'mysql' ) ) );
        }

        wp_safe_redirect( remove_query_arg( 'edit', $referer ) );
        exit;
    }

    // Видалення
    if ( isset( $_GET['del_cat_type'] ) ) {
        $id = intval( $_GET['del_cat_type'] );

        // Перевірка на використання
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}Category WHERE CategoryType_ID = %d", $id ) );

        if ( $count == 0 ) {
            $wpdb->delete( $table, array( 'id' => $id ) );
            wp_safe_redirect( remove_query_arg( 'del_cat_type', $referer ) );
            exit;
        } else {
            set_transient( 'fb_error', 'Неможливо видалити: тип використовується в категоріях.', 30 );
        }
    }
}

/**
 * ШОРТКОД [fb_category_type]
 */
function fb_render_category_type_interface() {
    if ( ! current_user_can( 'manage_options' ) ) return 'Доступ обмежено.';

    global $wpdb;
    $table = $wpdb->prefix . 'CategoryType';

    // Відображення помилок
    if ( $error = get_transient( 'fb_error' ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        delete_transient( 'fb_error' );
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
        <h2>Довідник: Типи категорій</h2>

        <form method="POST" class="fb-card">
            <?php wp_nonce_field( 'fb_save_category_type', 'fb_cat_type_nonce' ); ?>
            <input type="hidden" name="fb_action" value="save_cat_type">
            <?php if ( $edit_item ) : ?>
                <input type="hidden" name="cat_type_id" value="<?php echo $edit_item->id; ?>">
            <?php endif; ?>

            <input type="text" name="cat_type_name"
                   placeholder="Назва (Витрати, Доходи...)"
                   value="<?php echo $edit_item ? esc_attr( $edit_item->CategoryType_Name ) : ''; ?>" required>

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
                <th>ID</th>
                <th>Назва типу</th>
                <th>Дії</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $types as $t ) : ?>
                <tr>
                    <td><?php echo $t->id; ?></td>
                    <td><strong><?php echo esc_html( $t->CategoryType_Name ); ?></strong></td>
                    <td>
                        <a href="<?php echo add_query_arg( 'edit', $t->id ); ?>">📝</a>
                        <a href="<?php echo esc_url( add_query_arg( 'del_cat_type', $t->id ) ); ?>"
                           onclick="return confirm('Видалити цей тип?')">🗑️</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_category_type', 'fb_render_category_type_interface' );