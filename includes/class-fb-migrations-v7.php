<?php
/**
 * Migration v1.7.0: Account to Category Mapping
 *
 * Створює таблицю для жорсткої прив'язки конкретних рахунків до категорій.
 * Це вирішує проблему однакових MCC (4900) для різних осель.
 *
 * @package FamilyBudget
 * @version 1.7.0
 */

defined( 'ABSPATH' ) || exit;

function fb_migrate_account_category_v7(): void {
    global $wpdb;

    $table_name = $wpdb->prefix . 'account_category';
    $table_account = $wpdb->prefix . 'Account';
    $table_category = $wpdb->prefix . 'Category';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL COMMENT 'ID з wp_Account',
        category_id BIGINT UNSIGNED NOT NULL COMMENT 'ID з wp_Category',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_account (account_id),
        CONSTRAINT fk_acc_cat_account FOREIGN KEY (account_id) REFERENCES $table_account(id) ON DELETE CASCADE,
        CONSTRAINT fk_acc_cat_category FOREIGN KEY (category_id) REFERENCES $table_category(id) ON DELETE CASCADE
    ) $charset_collate COMMENT='Мапінг рахунків на категорії для авто-розподілу';";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}