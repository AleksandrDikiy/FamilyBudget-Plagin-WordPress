<?php
/**
 * Міграція v1.2.0: Модуль обліку осель та комунальних показників
 * * Цей файл відповідає за:
 * 1. Створення 7 нових таблиць.
 * 2. Наповнення службових довідників (Seed Data).
 * 3. Налаштування зв'язків (Foreign Keys) з існуючими таблицями Family та Amount.
 * * @package FamilyBudget
 * @version 1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Головна функція міграції
 */
function fb_migrate_utilities_v2(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // 1. Створення таблиць (спрощено через dbDelta)
    $queries = [
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}house_type (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            house_type_name VARCHAR(100) NOT NULL,
            house_type_order SMALLINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}houses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_house_type BIGINT UNSIGNED NULL,
            houses_city VARCHAR(50) NOT NULL,
            houses_street VARCHAR(150) NOT NULL,
            houses_number VARCHAR(10) NOT NULL,
            houses_number_apartment VARCHAR(10) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}personal_accounts_type (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            personal_accounts_type_name VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}personal_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_personal_accounts_type BIGINT UNSIGNED  NULL,
            id_houses BIGINT UNSIGNED  NULL,
            personal_accounts_number VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}indicators (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_personal_accounts BIGINT UNSIGNED  NULL,
            indicators_month TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'номер місяця (від 1 до 12)',
            indicators_year YEAR NOT NULL DEFAULT '2026' COMMENT 'номер року (4 цифри)',
            indicators_value1 DECIMAL(12, 3) NULL COMMENT 'показання лічильника1',
            indicators_value2 DECIMAL(12, 3) NULL COMMENT 'показання лічильника2',
            indicators_consumed DECIMAL(12, 3) NOT NULL COMMENT 'спожито за місяць',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_period (indicators_year, indicators_month, id_personal_accounts)
        ) $charset_collate ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}house_family (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_houses BIGINT UNSIGNED NOT NULL,
            id_family BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_house_family (id_houses, id_family)
        ) $charset_collate ENGINE=InnoDB;",

        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}indicator_amount (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_indicators BIGINT UNSIGNED NOT NULL,
            id_amount BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate ENGINE=InnoDB;"
    ];

    foreach ( $queries as $sql ) {
        dbDelta( $sql );
    }

    // 2. Наповнення даними (Seed Data)
    fb_seed_utility_data();

    // 3. Додавання зовнішніх ключів
    fb_add_utility_foreign_keys();
}

/**
 * Наповнення довідників початковими даними
 */
function fb_seed_utility_data(): void {
    global $wpdb;

    // Типи осель
    $house_types = ['будинок', 'квартира', 'дача'];
    foreach ( $house_types as $index => $name ) {
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}house_type WHERE house_type_name = %s", $name ) );
        if ( ! $exists ) {
            $wpdb->insert( "{$wpdb->prefix}house_type", [
                'house_type_name'  => $name,
                'house_type_order' => $index + 1
            ] );
        }
    }

    // Типи рахунків (комунальні послуги)
    $account_types = ['газ', 'світло', 'вода'];
    foreach ( $account_types as $name ) {
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}personal_accounts_type WHERE personal_accounts_type_name = %s", $name ) );
        if ( ! $exists ) {
            $wpdb->insert( "{$wpdb->prefix}personal_accounts_type", [
                'personal_accounts_type_name' => $name
            ] );
        }
    }
}

/**
 * Додавання всіх зв'язків (Foreign Keys)
 */
function fb_add_utility_foreign_keys(): void {
    global $wpdb;

    $foreign_keys = [
        // houses -> house_type
        ['table' => 'houses', 'key' => 'fk_houses_type', 'sql' => "ALTER TABLE {$wpdb->prefix}houses ADD CONSTRAINT fk_houses_type FOREIGN KEY (id_house_type) REFERENCES {$wpdb->prefix}house_type(id) ON DELETE SET NULL"],

        // personal_accounts -> personal_accounts_type
        ['table' => 'personal_accounts', 'key' => 'fk_pa_type', 'sql' => "ALTER TABLE {$wpdb->prefix}personal_accounts ADD CONSTRAINT fk_pa_type FOREIGN KEY (id_personal_accounts_type) REFERENCES {$wpdb->prefix}personal_accounts_type(id) ON DELETE SET NULL"],

        // personal_accounts -> houses
        ['table' => 'personal_accounts', 'key' => 'fk_pa_houses', 'sql' => "ALTER TABLE {$wpdb->prefix}personal_accounts ADD CONSTRAINT fk_pa_houses FOREIGN KEY (id_houses) REFERENCES {$wpdb->prefix}houses(id) ON DELETE CASCADE"],

        // indicators -> personal_accounts
        ['table' => 'indicators', 'key' => 'fk_ind_pa', 'sql' => "ALTER TABLE {$wpdb->prefix}indicators ADD CONSTRAINT fk_ind_pa FOREIGN KEY (id_personal_accounts) REFERENCES {$wpdb->prefix}personal_accounts(id) ON DELETE CASCADE"],

        // house_family -> houses
        ['table' => 'house_family', 'key' => 'fk_hf_houses', 'sql' => "ALTER TABLE {$wpdb->prefix}house_family ADD CONSTRAINT fk_hf_houses FOREIGN KEY (id_houses) REFERENCES {$wpdb->prefix}houses(id) ON DELETE CASCADE"],

        // house_family -> Family (зовнішня таблиця)
        ['table' => 'house_family', 'key' => 'fk_hf_family', 'sql' => "ALTER TABLE {$wpdb->prefix}house_family ADD CONSTRAINT fk_hf_family FOREIGN KEY (id_family) REFERENCES {$wpdb->prefix}Family(id) ON DELETE CASCADE"],

        // indicator_amount -> indicators
        ['table' => 'indicator_amount', 'key' => 'fk_ia_ind', 'sql' => "ALTER TABLE {$wpdb->prefix}indicator_amount ADD CONSTRAINT fk_ia_ind FOREIGN KEY (id_indicators) REFERENCES {$wpdb->prefix}indicators(id) ON DELETE CASCADE"],

        // indicator_amount -> Amount (зовнішня таблиця)
        ['table' => 'indicator_amount', 'key' => 'fk_ia_amount', 'sql' => "ALTER TABLE {$wpdb->prefix}indicator_amount ADD CONSTRAINT fk_ia_amount FOREIGN KEY (id_amount) REFERENCES {$wpdb->prefix}Amount(id) ON DELETE CASCADE"]
    ];

    foreach ( $foreign_keys as $fk ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s",
            $wpdb->prefix . $fk['table'], $fk['key']
        ) );

        if ( ! $exists ) {
            $wpdb->query( $fk['sql'] );
        }
    }
}