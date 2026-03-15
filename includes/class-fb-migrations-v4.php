<?php
/**
 * Migration v1.4.0: Monobank API Integration Fields
 *
 * This migration:
 * 1. Adds `transaction_id` to the {prefix}Amount table (to prevent duplicates).
 * 2. Adds `mcc` (Merchant Category Code) for automatic categorization.
 * 3. Adds `comment` for extended transaction details.
 * 4. Ensures all operations are idempotent.
 *
 * @package FamilyBudget
 * @version 1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes migration v1.4.0
 *
 * @since 1.4.0
 * @return void
 */
function fb_migrate_amount_v4(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_amount = $wpdb->prefix . 'Amount';
    $wpdb->hide_errors();

    // 1. Add 'transaction_id' for duplicate prevention
    $col_id_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table_amount} LIKE %s", 'transaction_id'
    ));

    if ( ! $col_id_exists ) {
        $wpdb->query( "ALTER TABLE {$table_amount} ADD COLUMN transaction_id VARCHAR(50) NULL AFTER Currency_ID" );
        $wpdb->query( "ALTER TABLE {$table_amount} ADD UNIQUE INDEX Idx_Transaction_ID (transaction_id)" );
    }

    // 2. Add 'mcc' (Merchant Category Code)
    $col_mcc_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table_amount} LIKE %s", 'mcc'
    ));

    if ( ! $col_mcc_exists ) {
        $wpdb->query( "ALTER TABLE {$table_amount} ADD COLUMN mcc SMALLINT UNSIGNED NULL AFTER transaction_id" );
        $wpdb->query( "ALTER TABLE {$table_amount} ADD INDEX Idx_MCC (mcc)" );
    }

    // 3. Add 'comment' for Monobank transaction notes
    $col_comment_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table_amount} LIKE %s", 'comment'
    ));

    if ( ! $col_comment_exists ) {
        $wpdb->query( "ALTER TABLE {$table_amount} ADD COLUMN comment TEXT NULL AFTER mcc" );
    }
}