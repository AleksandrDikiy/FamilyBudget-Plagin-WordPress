<?php
// Файл: includes/class-fb-migrations.php
defined( 'ABSPATH' ) || exit;

/**
 * Перевіряє, чи потрібне оновлення бази даних.
 * Викликається на хуку plugins_loaded.
 */
function fb_check_db_updates() {
    // Отримуємо поточну встановлену версію БД з опцій WP
    $installed_version = get_option( 'fb_db_version', '1.0.0' );

    // Якщо версія у файлі вища за встановлену — запускаємо міграцію
    if ( version_compare( $installed_version, FB_DB_VERSION, '<' ) ) {
        fb_migrate_database_schema();

        // Після успішної міграції оновлюємо опцію, щоб не ганяти міграцію по колу
        update_option( 'fb_db_version', FB_DB_VERSION );
    }
}

/**
 * Основна функція міграції.
 * Тут зібрані всі ALTER TABLE запити.
 */
function fb_migrate_database_schema() {
    global $wpdb;

    // ВАЖЛИВО: Підключаємо файл upgrade.php, він потрібен для dbDelta,
    // навіть якщо зараз ми використовуємо прямі запити, це гарна практика для майбутнього.
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $table_category_type = $wpdb->prefix . 'CategoryType';
    $table_category      = $wpdb->prefix . 'Category';
    $table_family        = $wpdb->prefix . 'Family';

    $wpdb->hide_errors();

    // ==========================================
    // 1. Оновлення довідника CategoryType
    // ==========================================
    $col_exists = $wpdb->get_var( "SHOW COLUMNS FROM {$table_category_type} LIKE 'Family_ID'" );
    if ( ! $col_exists ) {
        $wpdb->query( "
            ALTER TABLE {$table_category_type} 
            ADD COLUMN Family_ID BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Код родини' AFTER id,
            ADD COLUMN CategoryType_Order SMALLINT DEFAULT 1 COMMENT 'сортування' AFTER CategoryType_Name,
            ADD INDEX Idx_Family_ID (Family_ID)
        " );

        $wpdb->query( "
            ALTER TABLE {$table_category_type}
            ADD CONSTRAINT fk_categorytype_family 
            FOREIGN KEY (Family_ID) REFERENCES {$table_family}(id) ON DELETE CASCADE
        " );
    }

    // ==========================================
    // 2. Видалення зайвого зв'язку в таблиці Category
    // ==========================================
    $col_exists_cat = $wpdb->get_var( "SHOW COLUMNS FROM {$table_category} LIKE 'Family_ID'" );
    if ( $col_exists_cat ) {
        $fk_name = $wpdb->get_var( $wpdb->prepare( "
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = %s 
              AND COLUMN_NAME = 'Family_ID' 
              AND REFERENCED_TABLE_NAME = %s
        ", $table_category, $table_family ) );

        if ( $fk_name ) {
            $wpdb->query( "ALTER TABLE {$table_category} DROP FOREIGN KEY {$fk_name}" );
        }

        $wpdb->query( "ALTER TABLE {$table_category} DROP INDEX Idx_Family_ID" );
        $wpdb->query( "ALTER TABLE {$table_category} DROP COLUMN Family_ID" );
    }
}