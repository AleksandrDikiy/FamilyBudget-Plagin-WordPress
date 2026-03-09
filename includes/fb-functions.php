<?php
/**
 * Глобальні допоміжні функції — Family Budget Plugin
 *
 * Містить функції для отримання даних поточного користувача:
 * родини, типи рахунків та перевірка доступу.
 *
 * @package FamilyBudget
 * @version 1.4.1
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
 * Отримання списку категорій родини.
 * Змінено: Перевірка Family_ID йде через таблицю CategoryType.
 */
function fb_get_categories( int $family_id ) {
    global $wpdb;
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id, c.Category_Name as name, c.CategoryType_ID, t.CategoryType_Name as type_name
            FROM {$wpdb->prefix}Category c
            JOIN {$wpdb->prefix}CategoryType t ON t.id = c.CategoryType_ID
            WHERE t.Family_ID = %d
            ORDER BY t.CategoryType_Order ASC, c.Category_Order ASC, c.Category_Name ASC",
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
 * Отримує типи категорій, доступні в контексті родин поточного користувача,
 * ЯКІ МАЮТЬ ХОЧА Б ОДНУ СТВОРЕНУ КАТЕГОРІЮ (використовується для фільтрів).
 */
function fb_get_category_type() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    global $wpdb;
    $uid = get_current_user_id();
    $types = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT ct.id, ct.CategoryType_Name, ct.CategoryType_Order
               FROM {$wpdb->prefix}CategoryType ct
               JOIN {$wpdb->prefix}Category c ON c.CategoryType_ID = ct.id
               JOIN {$wpdb->prefix}UserFamily uf ON ct.Family_ID = uf.Family_ID
              WHERE uf.User_ID = %d
              ORDER BY ct.CategoryType_Order ASC, ct.CategoryType_Name ASC",
            $uid
        )
    );
    return $types ?: false;
}

/**
 * Отримує ВСІ типи категорій, що належать родинам поточного користувача
 * (використовується для випадаючих списків при додаванні/редагуванні).
 */
function fb_get_all_category_types() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    global $wpdb;
    $uid = get_current_user_id();
    $types = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT ct.id, ct.CategoryType_Name, ct.CategoryType_Order
               FROM {$wpdb->prefix}CategoryType ct
               JOIN {$wpdb->prefix}UserFamily uf ON ct.Family_ID = uf.Family_ID
              WHERE uf.User_ID = %d
              ORDER BY ct.CategoryType_Order ASC, ct.CategoryType_Name ASC",
            $uid
        ),
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

    // 1. Перевіряємо Account
    $acc_count = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}Account WHERE Family_ID = %d LIMIT 1", $family_id )
    );
    if ( $acc_count > 0 ) return true;

    // 2. Перевіряємо CategoryType (нові типи категорій)
    $cat_type_count = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}CategoryType WHERE Family_ID = %d LIMIT 1", $family_id )
    );
    if ( $cat_type_count > 0 ) return true;

    // 3. Перевіряємо Category (через CategoryType)
    $cat_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}Category c
             JOIN {$wpdb->prefix}CategoryType ct ON c.CategoryType_ID = ct.id
             WHERE ct.Family_ID = %d LIMIT 1",
            $family_id
        )
    );
    if ( $cat_count > 0 ) return true;

    // 4. CurrencyFamily
    $currency_count = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}CurrencyFamily WHERE Family_ID = %d LIMIT 1", $family_id )
    );
    if ( $currency_count > 0 ) return true;

    // 5. Amount
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

/**
 * Єдина точка перевірки безпеки для AJAX-обробників.
 *
 * Замінює три ідентичні локальні функції: fb_accounts_verify_request(),
 * fb_category_verify_request(), fb_currency_verify_request().
 * Виконує двошарову перевірку: авторизацію + валідацію nonce з $_POST['security'].
 * У разі невдачі завершує виконання через wp_send_json_error() (HTTP 403).
 *
 * @since  1.3.1
 * @param  string $action Ім'я nonce-дії WordPress (наприклад, 'fb_account_nonce').
 * @return void
 */
function fb_verify_ajax_request( string $action ): void {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => __( 'Необхідна авторизація.', 'family-budget' ) ), 403 );
    }

    $nonce = isset( $_POST['security'] ) ? sanitize_key( wp_unslash( $_POST['security'] ) ) : '';

    if ( ! wp_verify_nonce( $nonce, $action ) ) {
        wp_send_json_error( array( 'message' => __( 'Помилка безпеки. Оновіть сторінку.', 'family-budget' ) ), 403 );
    }
}