<?php
/**
 * Migration v1.5.0: MCC to Category Mapping
 *
 * This migration:
 * 1. Creates {prefix}mcc_mapping table to link MonoBank MCC codes with Family Budget categories.
 * 2. Sets up foreign key constraints for data integrity.
 *
 * @package FamilyBudget
 * @version 1.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes migration v1.5.0
 *
 * @since 1.5.0
 * @return void
 */
function fb_migrate_mcc_mapping_v5(): void {
    global $wpdb;

    $table_name = $wpdb->prefix . 'mcc_mapping';
    $table_categories = $wpdb->prefix . 'Category';
    $charset_collate = $wpdb->get_charset_collate();

    $wpdb->hide_errors();

    // Перевіряємо існування таблиці
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {

        $sql = "CREATE TABLE $table_name (
            mcc SMALLINT UNSIGNED NOT NULL COMMENT 'ISO 18245 MCC Code',
            category_id BIGINT UNSIGNED NULL COMMENT 'Linked Family Budget Category',
            mcc_description VARCHAR(100) NULL COMMENT 'Bank/System description',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (mcc),
            INDEX Idx_Category_ID (category_id),
            CONSTRAINT fk_mcc_category 
                FOREIGN KEY (category_id) 
                REFERENCES $table_categories(id) 
                ON DELETE SET NULL
        ) $charset_collate COMMENT='Mapping between bank MCC and internal categories';";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}