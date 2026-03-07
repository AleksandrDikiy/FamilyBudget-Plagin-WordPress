<?php
/**
 * Файл налаштування бази даних для плагіна FamilyBudget
 * 
 * Відповідає за створення та управління структурою БД:
 * - Створення 12 таблиць
 * - Налаштування зовнішніх ключів
 * - Наповнення базовими даними
 * - Видалення таблиць при деактивації
 * 
 * @package FamilyBudget
 * @version 1.1.0.1
 * @date_edit 22.02.2026
 * @since 1.0.0
 */

// Захист від прямого доступу
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Основна функція створення структури БД
 * 
 * Створює всі 12 таблиць плагіна з правильними індексами та зовнішніми ключами.
 * Автоматично викликається при активації плагіна.
 * 
 * @since 1.1.0
 * @return void
 */
function fb_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();

    // Реєстрація ролей
    fb_register_roles();

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    // Масив SQL-запитів для створення таблиць
    $queries = array(
        
        // 1. Таблиця родин
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}Family (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            Family_Name VARCHAR(50) NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            INDEX Idx_Family_Name (Family_Name)
        ) {$charset_collate} COMMENT='Таблиця родин користувачів';",

        // 2. Зв'язок користувачів з родинами
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}UserFamily (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            User_ID BIGINT UNSIGNED NOT NULL,
            Family_ID BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY Unique_User_Family (User_ID, Family_ID),
            INDEX Idx_User_ID (User_ID),
            INDEX Idx_Family_ID (Family_ID),
            FOREIGN KEY (Family_ID) REFERENCES {$wpdb->prefix}Family(id) ON DELETE CASCADE
        ) {$charset_collate} COMMENT='Зв\'язок користувачів з родинами';",

        // 3. Типи параметрів (службовий довідник)
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ParameterType (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ParameterType_Name VARCHAR(50) NOT NULL,
            ParameterType_Order SMALLINT NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY Unique_ParameterType_Name (ParameterType_Name),
            INDEX Idx_ParameterType_Order (ParameterType_Order)
        ) {$charset_collate} COMMENT='Типи параметрів категорій';",

        // 4. Валюти (службовий довідник)
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}Currency (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            Currency_Name VARCHAR(50) NOT NULL COMMENT 'назва валюти',
            Currency_Code VARCHAR(3) NULL COMMENT 'код валюти',
            Currency_Symbol VARCHAR(1) NULL COMMENT 'символ валюти',
            Currency_Order SMALLINT NOT NULL DEFAULT 1 COMMENT 'сортування',
            created_at DATETIME NULL COMMENT 'дата створення',
            PRIMARY KEY (id),
            INDEX Idx_Currency_Code (Currency_Code)
        ) {$charset_collate} COMMENT='Валюти родин';",

        // 4a. Курси валют (завантаження з НБУ по API)
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}CurrencyValue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            Currency_ID BIGINT UNSIGNED NOT NULL COMMENT 'код валюти',
            CurrencyValue_Rate DECIMAL(12, 6) NOT NULL COMMENT 'Курс валюти до гривні',
            CurrencyValue_Date DATE NOT NULL DEFAULT (CURRENT_DATE) COMMENT 'Дата курсу',
            created_at DATETIME NULL COMMENT 'дата створення',
            PRIMARY KEY (id),
            UNIQUE KEY Unique_Currency_Date (Currency_ID, CurrencyValue_Date),
            INDEX Idx_Currency_ID (Currency_ID),
            INDEX Idx_CurrencyValue_Date (CurrencyValue_Date),
            FOREIGN KEY (Currency_ID) REFERENCES {$wpdb->prefix}Currency(id) ON DELETE CASCADE
        ) {$charset_collate} COMMENT='Історичні курси валют з НБУ';",

        // 4b. валюти родини
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}CurrencyFamily (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            Family_ID BIGINT UNSIGNED NOT NULL COMMENT 'Код родини',
            Currency_ID BIGINT UNSIGNED NOT NULL COMMENT 'Код валюти',
            CurrencyFamily_Primary SMALLINT DEFAULT 0 COMMENT 'головна валюта',
            CurrencyFamily_Order SMALLINT NOT NULL DEFAULT 1 COMMENT 'сортування',
            created_at DATETIME NULL COMMENT 'дата створення',
            PRIMARY KEY (id),
            UNIQUE KEY Unique_Currency_Family (Currency_ID, Family_ID),
            INDEX Idx_Currency_ID (Currency_ID),
            INDEX Idx_Family_ID (Family_ID),
            INDEX Idx_CurrencyFamily_Primary (CurrencyFamily_Primary),
            FOREIGN KEY (Currency_ID) REFERENCES {$wpdb->prefix}Currency(id) ON DELETE CASCADE,
            FOREIGN KEY (Family_ID) REFERENCES {$wpdb->prefix}Family(id) ON DELETE CASCADE
        ) {$charset_collate} COMMENT='валюти родини';",

        // 5. Типи рахунків (службовий довідник)
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}AccountType (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            AccountType_Name VARCHAR(50) NOT NULL,
            AccountType_Order SMALLINT DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY Unique_AccountType_Name (AccountType_Name),
            INDEX Idx_AccountType_Order (AccountType_Order)
        ) {$charset_collate} COMMENT='Типи рахунків';",

        // 6. Рахунки
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}Account (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            Family_ID BIGINT UNSIGNED NOT NULL,
            AccountType_ID BIGINT UNSIGNED NOT NULL,
            Account_Name VARCHAR(50) NOT NULL,
            Account_Order SMALLINT DEFAULT 1,
            Account_Default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            INDEX Idx_Family_ID (Family_ID),
            INDEX Idx_AccountType_ID (AccountType_ID),
            INDEX Idx_Account_Order (Account_Order),
            FOREIGN KEY (AccountType_ID) REFERENCES {$wpdb->prefix}AccountType(id),
            FOREIGN KEY (Family_ID) REFERENCES {$wpdb->prefix}Family(id) ON DELETE CASCADE
        ) {$charset_collate} COMMENT='Рахунки родин';",

        // 7. Типи транзакцій (службовий довідник)
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}AmountType (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            AmountType_Name VARCHAR(50) NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY Unique_AmountType_Name (AmountType_Name)
        ) {$charset_collate} COMMENT='Типи транзакцій';",

        // 8. Типи категорій (службовий довідник)
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}CategoryType (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            CategoryType_Name VARCHAR(50) NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY Unique_CategoryType_Name (CategoryType_Name)
        ) {$charset_collate} COMMENT='Типи категорій';",

        // 9. Категорії
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}Category (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            Family_ID BIGINT UNSIGNED NOT NULL,
            CategoryType_ID BIGINT UNSIGNED NOT NULL,
            Category_Name VARCHAR(50) NOT NULL,
            Category_Order SMALLINT DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            INDEX Idx_Family_ID (Family_ID),
            INDEX Idx_CategoryType_ID (CategoryType_ID),
            INDEX Idx_Category_Order (Category_Order),
            FOREIGN KEY (CategoryType_ID) REFERENCES {$wpdb->prefix}CategoryType(id),
            FOREIGN KEY (Family_ID) REFERENCES {$wpdb->prefix}Family(id) ON DELETE CASCADE
        ) {$charset_collate} COMMENT='Категорії доходів та витрат';",

        // 10. Параметри категорій - користувацький
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}CategoryParam (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            User_ID BIGINT UNSIGNED NOT NULL,
            Family_ID BIGINT UNSIGNED NOT NULL,
            Category_ID BIGINT UNSIGNED NOT NULL,
            ParameterType_ID BIGINT UNSIGNED NOT NULL,
            CategoryParam_Name VARCHAR(50) NOT NULL,
            CategoryParam_Order SMALLINT NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            INDEX Idx_User_ID (User_ID),
            INDEX Idx_Family_ID (Family_ID),
            INDEX Idx_Category_ID (Category_ID),
            INDEX Idx_ParameterType_ID (ParameterType_ID),
            FOREIGN KEY (ParameterType_ID) REFERENCES {$wpdb->prefix}ParameterType(id),
            FOREIGN KEY (Category_ID) REFERENCES {$wpdb->prefix}Category(id) ON DELETE CASCADE,
            FOREIGN KEY (Family_ID) REFERENCES {$wpdb->prefix}Family(id) ON DELETE CASCADE
        ) {$charset_collate} COMMENT='Додаткові параметри категорій';",

        // 11. Транзакції - загальна
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}Amount (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            AmountType_ID BIGINT UNSIGNED NOT NULL,
            Account_ID BIGINT UNSIGNED NOT NULL,
            Category_ID BIGINT UNSIGNED NOT NULL,
            Currency_ID BIGINT UNSIGNED NOT NULL,
            Amount_Value DECIMAL(12,2) NOT NULL,
            Note VARCHAR(100) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            INDEX Idx_AmountType_ID (AmountType_ID),
            INDEX Idx_Account_ID (Account_ID),
            INDEX Idx_Category_ID (Category_ID),
            INDEX Idx_Currency_ID (Currency_ID),
            INDEX Idx_Created_At (created_at),
            INDEX Idx_Updated_At (updated_at),
            FULLTEXT INDEX idx_fulltext_note (Note),
            FOREIGN KEY (AmountType_ID) REFERENCES {$wpdb->prefix}AmountType(id),
            FOREIGN KEY (Account_ID) REFERENCES {$wpdb->prefix}Account(id),
            FOREIGN KEY (Category_ID) REFERENCES {$wpdb->prefix}Category(id),
            FOREIGN KEY (Currency_ID) REFERENCES {$wpdb->prefix}Currency(id)
        ) ENGINE=InnoDB {$charset_collate} COMMENT='Транзакції (доходи/витрати)';",

        // 12. Значення параметрів транзакцій - користувацький
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}AmountParam (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            Amount_ID BIGINT UNSIGNED NOT NULL,
            CategoryParam_ID BIGINT UNSIGNED NOT NULL,
            AmountParam_Value VARCHAR(50) NOT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            INDEX Idx_Amount_ID (Amount_ID),
            INDEX Idx_CategoryParam_ID (CategoryParam_ID),
            FOREIGN KEY (Amount_ID) REFERENCES {$wpdb->prefix}Amount(id) ON DELETE CASCADE,
            FOREIGN KEY (CategoryParam_ID) REFERENCES {$wpdb->prefix}CategoryParam(id) ON DELETE CASCADE
        ) {$charset_collate} COMMENT='Значення додаткових параметрів транзакцій';"

    );

    // Виконання всіх запитів
    foreach ( $queries as $sql ) {
        dbDelta( $sql );
    }

    // Наповнення базовими даними
    fb_seed_data();
}

/**
 * Реєстрація ролей користувачів
 * 
 * Створює спеціальні ролі для адміністраторів та членів родин.
 * 
 * @since 1.0.0
 * @return void
 */
function fb_register_roles() {
    // Роль адміністратора родини
    if ( ! get_role( 'fb_admin' ) ) {
        add_role(
            'fb_admin',
            'Family Budget Admin',
            array(
                'read' => true,
                'fb_admin' => true,
                'upload_files' => true,
                'edit_posts' => false
            )
        );
    }

    // Роль члена родини (з правом імпорту)
    if ( ! get_role( 'fb_user' ) ) {
        add_role(
            'fb_user',
            'Family Budget User',
            array(
                'read' => true,
                'fb_user' => true,
                'upload_files' => true,  // Для імпорту XLS
                'edit_posts' => false
            )
        );
    }

    // Роль члена родини (з платним функціоналом)
    if ( ! get_role( 'fb_payment' ) ) {
        add_role(
            'fb_payment',
            'Family Budget Payment',
            array(
                'read' => true,
                'fb_payment' => true,
                'upload_files' => true,  // Для імпорту XLS
                'edit_posts' => false
            )
        );
    }
}

/**
 * Наповнення таблиць базовими даними
 * 
 * Додає початкові значення для системних довідників.
 * Використовує перевірку наявності запису перед вставкою.
 * 
 * @since 1.0.0
 * @return void
 */
function fb_seed_data() {
    global $wpdb;

    // 1. Типи параметрів
    $parameter_types = array(
        array( 'number', 'число', 1 ),
        array( 'string', 'строка', 2 ),
        array( 'date', 'дата', 3 )
    );

    foreach ( $parameter_types as $pt ) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ParameterType WHERE ParameterType_Name = %s",
                $pt[1]
            )
        );

        if ( ! $exists ) {
            $wpdb->insert(
                "{$wpdb->prefix}ParameterType",
                array(
                    'ParameterType_Name' => $pt[1],
                    'ParameterType_Order' => $pt[2],
                    'created_at' => current_time( 'mysql' )
                ),
                array( '%s', '%d', '%s' )
            );
        }
    }

    // 2. Типи категорій
    $category_types = array( 'Витрати', 'Доходи' );

    foreach ( $category_types as $name ) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}CategoryType WHERE CategoryType_Name = %s",
                $name
            )
        );

        if ( ! $exists ) {
            $wpdb->insert(
                "{$wpdb->prefix}CategoryType",
                array(
                    'CategoryType_Name' => $name,
                    'created_at' => current_time( 'mysql' )
                ),
                array( '%s', '%s' )
            );
        }
    }

    // 3. Типи транзакцій
    $amount_types = array( 'Витрата', 'Переказ', 'Дохід' );

    foreach ( $amount_types as $name ) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}AmountType WHERE AmountType_Name = %s",
                $name
            )
        );

        if ( ! $exists ) {
            $wpdb->insert(
                "{$wpdb->prefix}AmountType",
                array(
                    'AmountType_Name' => $name,
                    'created_at' => current_time( 'mysql' )
                ),
                array( '%s', '%s' )
            );
        }
    }

    // 4. Типи рахунків
    $account_types = array(
        array( 'Готівка', 1 ),
        array( 'Картка', 2 ),
        array( 'Депозит', 3 )
    );

    foreach ( $account_types as $at ) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}AccountType WHERE AccountType_Name = %s",
                $at[0]
            )
        );

        if ( ! $exists ) {
            $wpdb->insert(
                "{$wpdb->prefix}AccountType",
                array(
                    'AccountType_Name' => $at[0],
                    'AccountType_Order' => $at[1],
                    'created_at' => current_time( 'mysql' )
                ),
                array( '%s', '%d', '%s' )
            );
        }
    }
}

/**
 * Видалення всіх 13 таблиць плагіна
 * 
 * Викликається при деактивації плагіна.
 * Порядок видалення важливий через зовнішні ключі (FOREIGN KEYS).
 * 
 * @since 1.0.0
 * @return void
 */
function fb_drop_tables() {
    global $wpdb;

    // Масив таблиць у правильному порядку видалення
    $tables = array(
        'AmountParam',
        'Amount',
        'CategoryParam',
        'Category',
        'CategoryType',
        'AmountType',
        'Account',
        'AccountType',
        'CurrencyValue',     // Нова таблиця курсів
        'Currency',
        'ParameterType',
        'UserFamily',
        'Family'
    );

    // Відключення перевірки зовнішніх ключів
    $wpdb->query( "SET FOREIGN_KEY_CHECKS = 0;" );

    // Видалення таблиць
    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
    }

    // Увімкнення перевірки зовнішніх ключів
    $wpdb->query( "SET FOREIGN_KEY_CHECKS = 1;" );

    // Видалення ролей
    remove_role( 'fb_admin' );
    remove_role( 'fb_user' );
    remove_role( 'fb_payment' );

    // Очищення transients
    delete_transient( 'fb_nbu_rates' );
}
