<?php
// Файл: includes/class-fb-migrations.php
defined( 'ABSPATH' ) || exit;

/**
 * Перевіряє необхідність оновлення схеми бази даних.
 *
 * Порівнює збережену в опціях WordPress версію БД із актуальною константою
 * FB_DB_VERSION. Якщо встановлена версія нижча — запускає міграцію та
 * оновлює мітку версії, щоб уникнути повторного запуску.
 *
 * Підключається до хука plugins_loaded.
 *
 * @since  1.0.0
 * @return void
 */
/**
 * Перевіряє необхідність оновлення схеми бази даних.
 */
/**
 * Файл: includes/class-fb-migrations.php
 */
function fb_check_db_updates(): void {
    $installed_version = get_option( 'fb_db_version', '1.0.0' );

    // FB_DB_VERSION вже визначена в головному файлі, тут просто використовуємо
    if ( version_compare( $installed_version, FB_DB_VERSION, '<' ) ) {

        if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
            // ВАЖЛИВО: Оскільки цей файл вже в includes/,
            // використовуємо __DIR__ для точного шляху
            $migration_v2 = __DIR__ . '/class-fb-migrations-v2.php';

            if ( file_exists( $migration_v2 ) ) {
                require_once $migration_v2;

                if ( function_exists( 'fb_migrate_utilities_v2' ) ) {
                    fb_migrate_utilities_v2();
                }
            }
        }

        update_option( 'fb_db_version', FB_DB_VERSION );
    }
}

/**
 * Виконує всі ALTER TABLE міграції схеми бази даних.
 *
 * Кожен блок ідемпотентний: перед внесенням змін перевіряє поточний стан
 * таблиці через INFORMATION_SCHEMA, тому безпечно запускається повторно.
 *
 * Поточні міграції:
 *  1. CategoryType — додавання колонок Family_ID, CategoryType_Order та FK.
 *  2. Category    — видалення застарілої колонки Family_ID та її FK/індексу.
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