<?php
/**
 * Глобальні допоміжні функції — Family Budget Plugin
 *
 * Містить функції для отримання даних поточного користувача:
 * родини, типи рахунків та перевірка доступу.
 *
 * @package FamilyBudget
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit; // Захист від прямого доступу до файлу.

/**
 * Універсальний помічник для витягування даних з об'єктів або масивів (ARRAY_A).
 * Вирішує проблему порожніх списків, якщо ключі відрізняються.
 */
function fb_extract_value( $item, $keys ) {
    foreach ( $keys as $k ) {
        if ( is_array( $item ) && array_key_exists( $k, $item ) ) return $item[ $k ];
        if ( is_object( $item ) && property_exists( $item, $k ) ) return $item->$k;
    }
    return is_array($item) ? 'Ключ не знайдено' : '';
}

/**
 * Отримує список родин, до яких має доступ поточний авторизований користувач.
 */
function fb_get_families() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    global $wpdb;
    $uid = get_current_user_id();
    $families = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT f.id, f.Family_Name
               FROM {$wpdb->prefix}Family f
              WHERE f.id IN (
                    SELECT uf.Family_ID
                      FROM {$wpdb->prefix}UserFamily uf
                     WHERE uf.User_ID = %d
              )
              ORDER BY f.Family_Name ASC",
            $uid
        )
    );
    return $families ?: false;
}

/**
 * Отримує список типів рахунків, доступних поточному користувачу.
 */
function fb_get_account_type() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    global $wpdb;
    $uid = get_current_user_id();
    $types = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT at.id, at.AccountType_Name
               FROM {$wpdb->prefix}AccountType at
              WHERE at.id IN (
                    SELECT a.AccountType_ID
                      FROM {$wpdb->prefix}Account a
                     WHERE a.Family_ID IN (
                           SELECT uf.Family_ID
                             FROM {$wpdb->prefix}UserFamily uf
                            WHERE uf.User_ID = %d
                     )
              )
              ORDER BY at.AccountType_Name ASC",
            $uid
        )
    );
    return $types ?: false;
}

/**
 * Отримує список усіх типів рахунків
 */
function fb_get_all_account_type() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    global $wpdb;
    $types = $wpdb->get_results(
        "SELECT id, AccountType_Name
        FROM {$wpdb->prefix}AccountType
        ORDER BY AccountType_Order ASC",
        ARRAY_A
    );
    return $types ?: false;
}

/**
 * Перевіряє, чи має поточний авторизований користувач доступ до вказаної родини.
 */
function fb_user_has_family_access( int $family_id ): bool {
    if ( ! is_user_logged_in() || $family_id <= 0 ) {
        return false;
    }
    global $wpdb;
    $uid = get_current_user_id();
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->prefix}UserFamily
              WHERE User_ID  = %d
                AND Family_ID = %d",
            $uid,
            $family_id
        )
    );
    return (int) $exists > 0;
}

/**
 * Отримання ID поточної родини користувача
 */
function fb_get_current_family_id() {
    global $wpdb;
    $user_id = get_current_user_id();
    $family_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d LIMIT 1",
            $user_id
        )
    );
    return $family_id ? (int) $family_id : false;
}

/**
 * Отримання списку категорій родини
 */
function fb_get_categories( int $family_id ) {
    global $wpdb;
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id, c.Category_Name as name, c.CategoryType_ID, t.CategoryType_Name as type_name
            FROM {$wpdb->prefix}Category c
            JOIN {$wpdb->prefix}CategoryType t ON t.id = c.CategoryType_ID
            WHERE c.Family_ID = %d
            ORDER BY c.Category_Order, c.Category_Name",
            $family_id
        ),
        ARRAY_A
    );
    return $results;
}

/**
 * Отримання списку рахунків родини
 */
function fb_get_accounts( int $family_id ) {
    global $wpdb;
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT a.id, a.Account_Name as name, at.AccountType_Name as type_name
            FROM {$wpdb->prefix}Account a
            JOIN {$wpdb->prefix}AccountType at ON at.id = a.AccountType_ID
            WHERE a.Family_ID = %d
            ORDER BY a.Account_Order, a.Account_Name",
            $family_id
        ),
        ARRAY_A
    );
    return $results;
}

/**
 * Отримання списку валют родини.
 *
 * [SCHEMA-v2] Family_ID та Currency_Primary перенесені до таблиці CurrencyFamily.
 * Повертає CurrencyFamily_Primary під ключем Currency_Primary для зворотної сумісності
 * з усіма модулями, що використовують цю функцію (fb-charts.php тощо).
 *
 * @param int $family_id ID родини.
 * @return array Масив валют із ключами: id, name, symbol, Currency_Primary.
 */
function fb_get_currencies( int $family_id ) {
    global $wpdb;
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id, c.Currency_Name as name, c.Currency_Symbol as symbol,
                    cf.CurrencyFamily_Primary as Currency_Primary
             FROM {$wpdb->prefix}Currency AS c
             INNER JOIN {$wpdb->prefix}CurrencyFamily AS cf ON cf.Currency_ID = c.id
             WHERE cf.Family_ID = %d
             ORDER BY cf.CurrencyFamily_Primary DESC, c.Currency_Name ASC",
            $family_id
        ),
        ARRAY_A
    );
    return $results;
}

/**
 * Отримує типи категорій, доступні в контексті родин поточного користувача.
 */
function fb_get_category_type() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    global $wpdb;
    $uid = get_current_user_id();
    $types = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT ct.id, ct.CategoryType_Name
               FROM {$wpdb->prefix}CategoryType ct
              WHERE ct.id IN (
                    SELECT c.CategoryType_ID
                      FROM {$wpdb->prefix}Category c
                     WHERE c.Family_ID IN (
                           SELECT uf.Family_ID
                             FROM {$wpdb->prefix}UserFamily uf
                            WHERE uf.User_ID = %d
                     )
              )
              ORDER BY ct.CategoryType_Name ASC",
            $uid
        )
    );
    return $types ?: false;
}

/**
 * Отримує всі доступні типи категорій.
 */
function fb_get_all_category_types() {
    global $wpdb;
    $types = $wpdb->get_results(
        "SELECT id, CategoryType_Name
        FROM {$wpdb->prefix}CategoryType
        ORDER BY id ASC",
        ARRAY_A
    );
    return $types ?: false;
}

/**
 * Отримує всі типи параметрів категорій.
 */
function fb_get_parameter_types() {
    global $wpdb;
    $types = $wpdb->get_results(
        "SELECT id, ParameterType_Name
           FROM {$wpdb->prefix}ParameterType
          ORDER BY id ASC"
    );
    return $types ?: false;
}

/**
 * Перевіряє, чи має вказана родина пов'язані записи.
 */
function fb_get_available_records( int $family_id ): bool {
    if ( $family_id <= 0 ) {
        return false;
    }
    global $wpdb;
    // Перевіряємо Account та Category (мають Family_ID).
    $tables = array(
        $wpdb->prefix . 'Account',
        $wpdb->prefix . 'Category',
    );
    foreach ( $tables as $table ) {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE Family_ID = %d LIMIT 1",
                $family_id
            )
        );
        if ( $count > 0 ) return true;
    }
    // [SCHEMA-v2] Currency більше не має Family_ID — перевіряємо через CurrencyFamily.
    $currency_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}CurrencyFamily WHERE Family_ID = %d LIMIT 1",
            $family_id
        )
    );
    if ( $currency_count > 0 ) return true;
    $amount_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
			   FROM {$wpdb->prefix}Amount AS a
			   INNER JOIN {$wpdb->prefix}Account AS acc ON a.Account_ID = acc.id
			  WHERE acc.Family_ID = %d
			  LIMIT 1",
            $family_id
        )
    );
    return $amount_count > 0;
}

/**
 * Повертає кількість учасників вказаної родини.
 */
function fb_get_family_member_count( int $family_id ): int {
    if ( $family_id <= 0 ) return 0;
    global $wpdb;
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE Family_ID = %d",
            $family_id
        )
    );
}

/**
 * Повертає назву родини за її ID.
 */
function fb_get_family_name( int $family_id ): string|false {
    if ( ! is_user_logged_in() || $family_id <= 0 ) {
        return false;
    }
    if ( ! fb_user_has_family_access( $family_id ) ) {
        return false;
    }
    global $wpdb;
    $name = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT Family_Name FROM {$wpdb->prefix}Family WHERE id = %d",
            $family_id
        )
    );
    return $name ?: false;
}