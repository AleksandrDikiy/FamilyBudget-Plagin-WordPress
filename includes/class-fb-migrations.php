<?php
/**
 * Файл: includes/class-fb-migrations.php
 *
 * Точка входу системи міграцій бази даних плагіна Family Budget.
 * Підключається до хука `plugins_loaded` та послідовно застосовує
 * всі необхідні зміни схеми залежно від поточної версії БД.
 *
 * @package FamilyBudget
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Перевіряє необхідність оновлення схеми бази даних.
 *
 * Порівнює збережену в опціях WordPress версію БД із актуальною константою
 * FB_DB_VERSION. Якщо встановлена версія нижча — послідовно запускає всі
 * міграції, що ще не були застосовані, та оновлює мітку версії,
 * щоб уникнути повторного запуску.
 *
 * Підключається до хука `plugins_loaded`.
 *
 * @since  1.0.0
 * @return void
 */
function fb_check_db_updates(): void {
    $installed_version = get_option( 'fb_db_version', '1.0.0' );

    // FB_DB_VERSION визначена в головному файлі плагіна.
    if ( ! version_compare( $installed_version, FB_DB_VERSION, '<' ) ) {
        return;
    }

    // -------------------------------------------------------------------------
    // Міграція v1.2.0: модуль обліку осель та комунальних показників.
    // -------------------------------------------------------------------------
    if ( version_compare( $installed_version, '1.2.0', '<' ) ) {
        $migration_v2 = __DIR__ . '/class-fb-migrations-v2.php';

        if ( file_exists( $migration_v2 ) ) {
            require_once $migration_v2;

            if ( function_exists( 'fb_migrate_utilities_v2' ) ) {
                fb_migrate_utilities_v2();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Міграція v1.3.0: статус імпорту показників лічильників.
    // -------------------------------------------------------------------------
    if ( version_compare( $installed_version, '1.3.0', '<' ) ) {
        $migration_v3 = __DIR__ . '/class-fb-migrations-v3.php';

        if ( file_exists( $migration_v3 ) ) {
            require_once $migration_v3;

            if ( function_exists( 'fb_migrate_indicators_v3' ) ) {
                fb_migrate_indicators_v3();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Міграція v1.4.0: інтеграція з Monobank (transaction_id, mcc, comment).
    // -------------------------------------------------------------------------
    if ( version_compare( $installed_version, '1.4.0', '<' ) ) {
        $migration_v4 = __DIR__ . '/class-fb-migrations-v4.php';

        if ( file_exists( $migration_v4 ) ) {
            require_once $migration_v4;

            if ( function_exists( 'fb_migrate_amount_v4' ) ) {
                fb_migrate_amount_v4();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Міграція v1.5.0: Мапінг MCC-кодів на категорії.
    // -------------------------------------------------------------------------
    if ( version_compare( $installed_version, '1.5.0', '<' ) ) {
        $migration_v5 = __DIR__ . '/class-fb-migrations-v5.php';

        if ( file_exists( $migration_v5 ) ) {
            require_once $migration_v5;

            if ( function_exists( 'fb_migrate_mcc_mapping_v5' ) ) {
                fb_migrate_mcc_mapping_v5();
            }
        }
    }
    // -------------------------------------------------------------------------
    // Міграція v1.6.0: Прив'язка зовнішнього ID рахунку Monobank.
    // -------------------------------------------------------------------------
    if ( version_compare( $installed_version, '1.6.0', '<' ) ) {
        $migration_v6 = __DIR__ . '/class-fb-migrations-v6.php';

        if ( file_exists( $migration_v6 ) ) {
            require_once $migration_v6;

            if ( function_exists( 'fb_migrate_account_mono_v6' ) ) {
                fb_migrate_account_mono_v6();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Міграція v1.7.0: Account to Category Mapping
    // -------------------------------------------------------------------------
    if ( version_compare( $installed_version, '1.7.0', '<' ) ) {
        $migration_v7 = __DIR__ . '/class-fb-migrations-v7.php';

        if ( file_exists( $migration_v7 ) ) {
            require_once $migration_v7;

            if ( function_exists( 'fb_migrate_account_mono_v7' ) ) {
                fb_migrate_account_mono_v7();
            }
        }
    }

    // Зберігаємо нову версію після успішного виконання всіх міграцій.
    update_option( 'fb_db_version', FB_DB_VERSION );
}

/**
 * Виконує ALTER TABLE міграції схеми бази даних версії 1.0.x.
 *
 * Кожен блок є ідемпотентним: перед внесенням змін перевіряє поточний стан
 * таблиці через INFORMATION_SCHEMA, тому безпечно запускається повторно.
 *
 * Поточні міграції:
 *  1. CategoryType — додавання колонок Family_ID, CategoryType_Order та FK.
 *  2. Category     — видалення застарілої колонки Family_ID та її FK/індексу.
 *  3. CategoryType — заміна одиночного UNIQUE по CategoryType_Name на
 *                    складений UNIQUE (Family_ID, CategoryType_Name), щоб
 *                    дозволити однакові назви типів у різних родинах.
 *
 * @since  1.0.0
 * @return void
 */
function fb_migrate_database_schema(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_category_type = $wpdb->prefix . 'CategoryType';
    $table_category      = $wpdb->prefix . 'Category';
    $table_family        = $wpdb->prefix . 'Family';

    $wpdb->hide_errors();

    // =========================================================================
    // 1. Оновлення довідника CategoryType:
    //    додаємо Family_ID, CategoryType_Order та зовнішній ключ.
    // =========================================================================
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

    // =========================================================================
    // 2. Очищення таблиці Category:
    //    видаляємо застарілий зв'язок Family_ID (перенесений у CategoryType).
    // =========================================================================
    $col_exists_cat = $wpdb->get_var( "SHOW COLUMNS FROM {$table_category} LIKE 'Family_ID'" );
    if ( $col_exists_cat ) {
        $fk_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME        = %s
                   AND COLUMN_NAME       = 'Family_ID'
                   AND REFERENCED_TABLE_NAME = %s",
                $table_category,
                $table_family
            )
        );

        if ( $fk_name ) {
            $wpdb->query( "ALTER TABLE {$table_category} DROP FOREIGN KEY {$fk_name}" );
        }

        $wpdb->query( "ALTER TABLE {$table_category} DROP INDEX Idx_Family_ID" );
        $wpdb->query( "ALTER TABLE {$table_category} DROP COLUMN Family_ID" );
    }

    // =========================================================================
    // 3. Виправлення унікального індексу в CategoryType:
    //    Замінюємо UNIQUE (CategoryType_Name) на UNIQUE (Family_ID, CategoryType_Name).
    //
    //    Причина: одиночний UNIQUE блокує однакові назви типів у різних родинах
    //    (наприклад, дві родини не можуть обидві мати тип "Продукти").
    //    Складений індекс гарантує унікальність лише в межах однієї родини.
    // =========================================================================

    // 3а. Перевіряємо, чи старий одиночний індекс ще існує.
    $old_unique_exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = %s
               AND INDEX_NAME   = 'Unique_CategoryType_Name'",
            $table_category_type
        )
    );

    if ( $old_unique_exists > 0 ) {
        $wpdb->query( "ALTER TABLE {$table_category_type} DROP INDEX Unique_CategoryType_Name" );
    }

    // 3б. Перевіряємо, чи новий складений індекс вже існує (ідемпотентність).
    $new_unique_exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = %s
               AND INDEX_NAME   = 'Unique_FamilyType'",
            $table_category_type
        )
    );

    if ( 0 === $new_unique_exists ) {
        $wpdb->query( "ALTER TABLE {$table_category_type} ADD UNIQUE KEY Unique_FamilyType (Family_ID, CategoryType_Name)" );
    }
}
