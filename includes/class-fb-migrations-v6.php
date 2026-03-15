<?php
/**
 * Migration v1.6.0: Monobank Account Link
 *
 * This migration:
 * 1. Adds `account_id` (External ID from MonoBank) to the {prefix}Account table.
 * 2. Adds a Unique Index to prevent duplicate account mapping.
 *
 * @package FamilyBudget
 * @version 1.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes migration v1.6.0
 *
 * @since 1.6.0
 * @return void
 */
function fb_migrate_account_mono_v6(): void {
    global $wpdb;

    $table_account = $wpdb->prefix . 'Account';
    $wpdb->hide_errors();

    // Перевіряємо, чи колонка вже існує
    $column_exists = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table_account} LIKE %s",
        'account_id'
    ) );

    if ( ! $column_exists ) {
        // Додаємо поле account_id (зазвичай це рядок довжиною близько 22-30 символів)
        $wpdb->query( "ALTER TABLE {$table_account} 
            ADD COLUMN account_id VARCHAR(50) NULL 
            COMMENT 'External ID from Monobank API' 
            AFTER AccountType_ID"
        );

        // Додаємо унікальний індекс для швидкого пошуку ботом
        $wpdb->query( "ALTER TABLE {$table_account} 
            ADD UNIQUE INDEX Idx_Account_External_ID (account_id)"
        );
    }
}