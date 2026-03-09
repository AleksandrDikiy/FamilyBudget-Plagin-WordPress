<?php
/**
 * Модуль Баланс — Управління бюджетними транзакціями
 *
 * Комплексні CRUD операції для сімейних бюджетних транзакцій включаючи:
 *  - Створення транзакцій з динамічними параметрами категорії
 *  - Редагування всіх параметрів транзакції через AJAX модальне вікно
 *  - Видалення транзакцій з суворим контролем доступу через UserFamily
 *  - Редагування параметрів категорії (окреме модальне вікно)
 *  - AJAX-фільтрація таблиці (пошук, дата, тип, рахунок, категорія)
 *  - Імпорт з CSV файлів
 *  - Автоматична синхронізація курсів валют (НБУ API)
 *  - Розрахунок та відображення балансу
 *  - Пагінація списку транзакцій
 *
 * @package    FamilyBudget
 * @subpackage Modules
 * @version    1.3.8
 * @since      1.0.0
 *
 * CHANGELOG v1.1.1.0:
 * ================================================
 * UI REFACTOR:
 *  [UI-1] Блок «Імпорт транзакцій» та форму додавання транзакції перенесено
 *          до лівої колонки (sidebar), розміщено вище блоку «Баланс».
 *  [UI-2] Форма додавання перероблена на компактний вигляд.
 *          Новий порядок полів: Тип → Рахунок → Категорія → Дата → Примітка
 *          → Сума + Валюта (inline) → кнопка «Зберегти».
 *
 * ARCHITECTURE:
 *  [ARCH-1] Увесь JavaScript винесено до зовнішнього файлу js/amount.js.
 *  [ARCH-2] Усі стилі винесено до зовнішнього файлу css/amount.css.
 *  [ARCH-3] Рядки UI для JS-повідомлень передаються через wp_localize_script
 *            (об'єкт fbAmountI18n), жодного inline-JS у шаблоні.
 *
 * SECURITY (збережено з v1.1.0.0):
 *  [SEC-1] fb_handle_amount_deletion: UserFamily JOIN для захисту видалення.
 *  [SEC-2] fb_ajax_get_amount: UserFamily JOIN для захисту читання.
 *  [SEC-3] fb_ajax_update_amount: UserFamily JOIN для захисту оновлення.
 *  [SEC-4] fb_ajax_get_category_params: UserFamily JOIN для захисту читання.
 *  [SEC-5] fb_ajax_update_category_params: UserFamily JOIN для захисту запису.
 *  [SEC-6] fb_ajax_filter_transactions: LIMIT/OFFSET через $wpdb->prepare().
 *  [SEC-7] Єдиний nonce 'fb_ajax_nonce' для всіх AJAX-обробників.
 *
 * PERFORMANCE (збережено з v1.1.0.0):
 *  [PERF-1] fb_ajax_filter_transactions: усунуто N+1 запит через LEFT JOIN.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Заборона прямого доступу до файлу.
}

// ============================================================================
// ENQUEUE — Підключення зовнішніх скриптів та стилів
// ============================================================================

add_action( 'wp_enqueue_scripts', 'fb_amount_enqueue_scripts' );

/**
 * Реєстрація та підключення зовнішніх ресурсів модуля «Баланс».
 *
 * [ARCH-1] JavaScript підключається з js/amount.js (не inline).
 * [ARCH-2] Стилі підключаються з css/amount.css.
 * [ARCH-3] Рядки UI для JS передаються через fbAmountI18n.
 * [SEC-7]  Єдиний уніфікований nonce 'fb_ajax_nonce' передається через fbAmountData.
 *
 * @since  1.1.1.0
 * @return void
 */
function fb_amount_enqueue_scripts(): void {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $plugin_url = FB_PLUGIN_URL;
    $version    = '1.1.1.0';

    // Стилі модуля.
    wp_enqueue_style(
        'fb-amount-style',
        $plugin_url . 'css/amount.css',
        array(),
        $version
    );

    // Основний скрипт модуля (залежить від jQuery, підключається у футері).
    wp_enqueue_script(
        'fb-amount-script',
        $plugin_url . 'js/amount.js',
        array( 'jquery' ),
        $version,
        true
    );

    // Локалізація: AJAX URL та nonce для всіх AJAX-запитів.
    wp_localize_script(
        'fb-amount-script',
        'fbAmountData',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'fb_ajax_nonce' ),
        )
    );

    // Локалізація: рядки UI для повідомлень у JavaScript.
    wp_localize_script(
        'fb-amount-script',
        'fbAmountI18n',
        array(
            'loadError'      => __( 'Помилка завантаження. Спробуйте оновити сторінку.', 'family-budget' ),
            'networkError'   => __( 'Помилка мережі. Спробуйте ще раз.', 'family-budget' ),
            'saveError'      => __( 'Помилка збереження.', 'family-budget' ),
            'paramsError'    => __( 'Помилка збереження параметрів.', 'family-budget' ),
            'paramsNotFound' => __( 'Параметри не знайдені.', 'family-budget' ),
            'txLoadError'    => __( 'Помилка завантаження транзакції.', 'family-budget' ),
            'saving'         => __( 'Збереження...', 'family-budget' ),
            'deleteConfirm'  => __( 'Ви впевнені що хочете видалити цю транзакцію?', 'family-budget' ),
        )
    );
}

/**
 * ЗАМІНИТИ В amount.php: секцію "CURRENCY SYNC" (рядки ~122–253)
 *
 * Це лише патч-фрагмент — вставляється замість оригінальної
 * функції fb_sync_currency_rates_after_transaction() та її хука.
 *
 * ЗМІНИ v2.0.0:
 *  [FIX-1] $currency_code: виправлено Currency_Name → Currency_Symbol для
 *           коректного порівняння з ISO-кодами НБУ API (USD, EUR, PLN...).
 *  [FIX-2] Умова фільтрації курсу: було <= 1.0, стало <= 0.
 *           Ця помилка "тихо" відкидала всі валюти, якщо Currency_Name не збігався.
 *  [FIX-3] Transient-кеш: API-відповідь НБУ кешується на 23 год через
 *           FB_Currency_Rates::get_rates_for_date(). Повторні виклики (ручне
 *           додавання транзакцій) не породжують нових HTTP-запитів.
 *  [FIX-4] Умова SQL: прибрано "OR v.CurrencyValue_Rate = 1" — вона могла
 *           повторно перезаписувати вже коректно збережені курси.
 *  [LOG-1] Розширене логування для відстеження пропущених кодів валют.
 */

// ============================================================================
// CURRENCY SYNC — Автоматична синхронізація курсів валют
// ============================================================================

/**
 * Автоматична синхронізація курсів після збереження одиничної транзакції.
 *
 * Хук: fb_after_amount_inserted (з fb_handle_amount_submission).
 * Знаходить НЕ-основні валюти родини без курсу на дату транзакції та
 * завантажує їх з НБУ через FB_Currency_Rates (з Transient-кешем).
 *
 * @since  1.0.26.1
 * @param  int    $amount_id ID нової транзакції.
 * @param  string $date      Дата транзакції Y-m-d.
 * @return void
 */
function fb_sync_currency_rates_after_transaction( int $amount_id, string $date ): void {
    global $wpdb;

    $dbg = static function ( string $msg ) use ( $amount_id ): void {
        if ( ! defined( 'FB_CURRENCY_DEBUG' ) || ! FB_CURRENCY_DEBUG ) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents(
            WP_CONTENT_DIR . '/fb-currency-debug.log',
            '[' . gmdate( 'Y-m-d H:i:s' ) . "][SYNC amt={$amount_id}] {$msg}" . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    };

    $dbg( "START | date={$date}" );

    if ( ! class_exists( 'FB_Currency_Rates' ) ) {
        error_log( '[FB Currency Sync] Клас FB_Currency_Rates відсутній.' );
        return;
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        // Під час імпорту через wp-cron user_id може бути 0.
        // Синхронізація при імпорті виконується окремо через FB_Import.
        $dbg( 'SKIP: user_id=0 (не-інтерактивний контекст).' );
        return;
    }

    // Знаходимо НЕ-основні валюти родини без курсу на задану дату.
    // [ROOT-FIX] Вибираємо Currency_Code (ISO-код: USD, EUR...) —
    //            саме він порівнюється з кодами НБУ API.
    //            Currency_Symbol ($, €) для цього непридатний.
    // [FIX-1]   COALESCE — NULL <> 1 у MySQL = NULL (не TRUE).
    // [FIX-2]   v.id IS NULL — лише валюти без запису на задану дату.
    $missing = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.id AS Currency_ID, c.Currency_Symbol, c.Currency_Code
             FROM {$wpdb->prefix}UserFamily AS uf
             JOIN {$wpdb->prefix}CurrencyFamily AS cf ON cf.Family_ID = uf.Family_ID
             JOIN {$wpdb->prefix}Currency AS c         ON c.id = cf.Currency_ID
             LEFT JOIN {$wpdb->prefix}CurrencyValue AS v
                 ON v.Currency_ID = c.id AND v.CurrencyValue_Date = %s
             WHERE uf.User_ID = %d
               AND COALESCE(cf.CurrencyFamily_Primary, 0) <> 1
               AND c.Currency_Code <> ''
               AND v.id IS NULL
             GROUP BY c.id",
            $date,
            $user_id
        )
    );

    $dbg( 'Валют без курсу: ' . count( (array) $missing ) );

    if ( empty( $missing ) ) {
        $dbg( 'Всі курси вже є — нічого не робимо.' );
        return;
    }

    foreach ( (array) $missing as $m ) {
        $dbg( "  → id={$m->Currency_ID}, symbol='{$m->Currency_Symbol}', code='{$m->Currency_Code}'" );
    }

    // Отримуємо курси НБУ (з Transient-кешу або API).
    $nbu_rates = FB_Currency_Rates::get_rates_for_date( $date );

    if ( empty( $nbu_rates ) ) {
        $dbg( 'ABORT: НБУ повернув порожній масив.' );
        error_log( "[FB Currency Sync] НБУ порожній | amount_id={$amount_id} | дата={$date}" );
        return;
    }

    foreach ( $missing as $currency ) {
        // [ROOT-FIX] Використовуємо Currency_Code (USD, EUR) для пошуку у НБУ.
        $iso_code = strtoupper( trim( $currency->Currency_Code ) );

        if ( ! isset( $nbu_rates[ $iso_code ] ) ) {
            $dbg( "  SKIP '{$iso_code}': відсутній у відповіді НБУ." );
            error_log( "[FB Currency Sync] ISO-код '{$iso_code}' відсутній у НБУ | дата={$date}" );
            continue;
        }

        $rate = (float) $nbu_rates[ $iso_code ];

        if ( $rate <= 0 ) {
            $dbg( "  SKIP '{$iso_code}': курс {$rate} <= 0." );
            continue;
        }

        $result = $wpdb->replace(
            $wpdb->prefix . 'CurrencyValue',
            array(
                'Currency_ID'        => (int) $currency->Currency_ID,
                'CurrencyValue_Rate' => $rate,
                'CurrencyValue_Date' => $date,
                'created_at'         => current_time( 'mysql' ),
            ),
            array( '%d', '%f', '%s', '%s' )
        );

        if ( false !== $result ) {
            $dbg( "  SAVED '{$iso_code}': id={$currency->Currency_ID}, rate={$rate}" );
        } else {
            $dbg( "  DB REPLACE FAILED '{$iso_code}': " . $wpdb->last_error );
            error_log( "[FB Currency Sync] DB помилка для '{$iso_code}': {$wpdb->last_error}" );
        }
    }

    $dbg( "DONE." );
}

add_action( 'fb_after_amount_inserted', 'fb_sync_currency_rates_after_transaction', 10, 2 );

// ============================================================================
// CREATE — Створення транзакції
// ============================================================================

add_action( 'template_redirect', 'fb_handle_amount_submission' );

/**
 * Обробка POST-запиту для створення нової транзакції.
 *
 * Виконує перевірку nonce, авторизації та валідацію всіх вхідних полів.
 * Після успішного збереження запускає синхронізацію курсів валют (хук fb_after_amount_inserted).
 *
 * @since  1.0.0
 * @return void
 */
function fb_handle_amount_submission(): void {
    if ( ! isset( $_POST['fb_action'] ) || 'add_amount' !== $_POST['fb_action'] ) {
        return;
    }

    if ( ! is_user_logged_in() ) {
        wp_die(
            esc_html__( 'Ви повинні бути авторизовані для виконання цієї дії.', 'family-budget' ),
            esc_html__( 'Необхідна аутентифікація', 'family-budget' ),
            array( 'response' => 403 )
        );
    }

    if ( ! isset( $_POST['fb_amt_nonce'] ) || ! wp_verify_nonce( $_POST['fb_amt_nonce'], 'fb_add_amount' ) ) {
        wp_die(
            esc_html__( 'Помилка перевірки безпеки. Спробуйте ще раз.', 'family-budget' ),
            esc_html__( 'Помилка безпеки', 'family-budget' ),
            array( 'response' => 403 )
        );
    }

    global $wpdb;

    $amount_type_id = isset( $_POST['type_id'] ) ? absint( $_POST['type_id'] ) : 0;
    $account_id     = isset( $_POST['acc_id'] )  ? absint( $_POST['acc_id'] )  : 0;
    $category_id    = isset( $_POST['cat_id'] )  ? absint( $_POST['cat_id'] )  : 0;
    $currency_id    = isset( $_POST['cur_id'] )  ? absint( $_POST['cur_id'] )  : 0;
    $amount_value   = isset( $_POST['val'] ) ? filter_var( $_POST['val'], FILTER_VALIDATE_FLOAT ) : false;
    $note           = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
    $date           = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : current_time( 'Y-m-d' );

    if ( ! $amount_type_id || ! $account_id || ! $category_id || ! $currency_id ) {
        wp_safe_redirect( add_query_arg( 'error', 'missing_fields', wp_get_referer() ) );
        exit;
    }

    if ( false === $amount_value || $amount_value <= 0 ) {
        wp_safe_redirect( add_query_arg( 'error', 'invalid_amount', wp_get_referer() ) );
        exit;
    }

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        $date = current_time( 'Y-m-d' );
    }

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'Amount',
        array(
            'AmountType_ID' => $amount_type_id,
            'Account_ID'    => $account_id,
            'Category_ID'   => $category_id,
            'Currency_ID'   => $currency_id,
            'Amount_Value'  => $amount_value,
            'Note'          => $note,
            'created_at'    => $date . ' ' . current_time( 'H:i:s' ),
        ),
        array( '%d', '%d', '%d', '%d', '%f', '%s', '%s' )
    );

    if ( false === $inserted ) {
        error_log( sprintf(
            'FB Amount Insert Error: %s | Користувач: %d',
            $wpdb->last_error,
            get_current_user_id()
        ) );
        wp_safe_redirect( add_query_arg( 'error', 'db_insert_failed', wp_get_referer() ) );
        exit;
    }

    $amount_id = $wpdb->insert_id;

    // Зберігаємо динамічні параметри категорії (якщо передані).
    if ( ! empty( $_POST['dyn_params'] ) && is_array( $_POST['dyn_params'] ) ) {
        foreach ( $_POST['dyn_params'] as $param_id => $param_value ) {
            $param_value = trim( $param_value );
            if ( '' === $param_value ) {
                continue;
            }

            $wpdb->insert(
                $wpdb->prefix . 'AmountParam',
                array(
                    'Amount_ID'         => $amount_id,
                    'CategoryParam_ID'  => absint( $param_id ),
                    'AmountParam_Value' => sanitize_text_field( $param_value ),
                    'created_at'        => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s', '%s' )
            );

            if ( $wpdb->last_error ) {
                error_log( sprintf( 'FB AmountParam Insert Error: %s', $wpdb->last_error ) );
            }
        }
    }

    /**
     * Хук після успішного створення транзакції.
     *
     * Використовується для запуску синхронізації курсів валют через НБУ API.
     *
     * @param int    $amount_id ID нової транзакції.
     * @param string $date      Дата транзакції (Y-m-d).
     */
    do_action( 'fb_after_amount_inserted', $amount_id, $date );

    wp_safe_redirect( add_query_arg( 'success', '1', wp_get_referer() ) );
    exit;
}

// ============================================================================
// DELETE — Видалення транзакції
// ============================================================================

add_action( 'template_redirect', 'fb_handle_amount_deletion' );

/**
 * Обробка видалення транзакції з суворим контролем доступу.
 *
 * [SEC-1] Перевіряє приналежність транзакції до родини поточного користувача
 * через UserFamily JOIN перед виконанням DELETE-запиту.
 *
 * @since  1.0.0
 * @return void
 */
function fb_handle_amount_deletion(): void {
    if ( ! isset( $_GET['delete_amount'], $_GET['_wpnonce'] ) ) {
        return;
    }

    $amount_id = absint( $_GET['delete_amount'] );

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_amount_' . $amount_id ) ) {
        wp_die(
            esc_html__( 'Помилка перевірки безпеки.', 'family-budget' ),
            esc_html__( 'Помилка безпеки', 'family-budget' ),
            array( 'response' => 403 )
        );
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // [SEC-1] UserFamily JOIN — забороняємо видалення чужих транзакцій.
    $has_access = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}Amount AS a
             INNER JOIN {$wpdb->prefix}Account AS acc ON a.Account_ID = acc.id
             INNER JOIN {$wpdb->prefix}UserFamily AS uf ON acc.Family_ID = uf.Family_ID
             WHERE a.id = %d AND uf.User_ID = %d",
            $amount_id,
            $user_id
        )
    );

    if ( ! $has_access ) {
        wp_die(
            esc_html__( 'У вас немає прав на видалення цієї транзакції.', 'family-budget' ),
            esc_html__( 'Доступ заборонено', 'family-budget' ),
            array( 'response' => 403 )
        );
    }

    $deleted = $wpdb->delete(
        $wpdb->prefix . 'Amount',
        array( 'id' => $amount_id ),
        array( '%d' )
    );

    if ( false === $deleted ) {
        error_log( sprintf(
            'FB Amount Delete Error: %s | Amount ID: %d | Користувач: %d',
            $wpdb->last_error,
            $amount_id,
            $user_id
        ) );
    }

    wp_safe_redirect( add_query_arg( 'deleted', '1', wp_get_referer() ) );
    exit;
}

// ============================================================================
// AJAX: GET AMOUNT — Отримання даних транзакції для редагування
// ============================================================================

add_action( 'wp_ajax_fb_get_amount', 'fb_ajax_get_amount' );

/**
 * AJAX: Отримання повних даних транзакції для форми редагування.
 *
 * [SEC-2] Перевіряє доступ через UserFamily JOIN —
 * забороняє читання транзакцій чужих родин.
 *
 * @since  1.0.0
 * @return void Повертає JSON-відповідь із даними транзакції.
 */
function fb_ajax_get_amount(): void {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( __( 'Необхідна аутентифікація.', 'family-budget' ) );
    }

    global $wpdb;
    $amount_id = isset( $_POST['amount_id'] ) ? absint( $_POST['amount_id'] ) : 0;
    $user_id   = get_current_user_id();

    if ( ! $amount_id ) {
        wp_send_json_error( __( 'Невірний ID транзакції.', 'family-budget' ) );
    }

    // [SEC-2] UserFamily JOIN — перевірка права доступу до транзакції.
    $transaction = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT a.*
             FROM {$wpdb->prefix}Amount AS a
             INNER JOIN {$wpdb->prefix}Account AS acc ON a.Account_ID = acc.id
             INNER JOIN {$wpdb->prefix}UserFamily AS uf ON acc.Family_ID = uf.Family_ID
             WHERE a.id = %d AND uf.User_ID = %d",
            $amount_id,
            $user_id
        )
    );

    if ( ! $transaction ) {
        wp_send_json_error( __( 'Транзакцію не знайдено або доступ заборонено.', 'family-budget' ) );
    }

    wp_send_json_success( array(
        'id'          => (int) $transaction->id,
        'type_id'     => (int) $transaction->AmountType_ID,
        'account_id'  => (int) $transaction->Account_ID,
        'category_id' => (int) $transaction->Category_ID,
        'currency_id' => (int) $transaction->Currency_ID,
        'amount'      => (float) $transaction->Amount_Value,
        'note'        => $transaction->Note ?? '',
        'date'        => gmdate( 'Y-m-d', strtotime( $transaction->created_at ) ),
    ) );
}

// ============================================================================
// AJAX: UPDATE AMOUNT — Оновлення транзакції
// ============================================================================

add_action( 'wp_ajax_fb_update_amount', 'fb_ajax_update_amount' );

/**
 * AJAX: Оновлення всіх полів транзакції.
 *
 * [SEC-3] Перевіряє доступ через UserFamily JOIN перед UPDATE-запитом.
 *
 * @since  1.0.0
 * @return void Повертає JSON-відповідь.
 */
function fb_ajax_update_amount(): void {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( __( 'Необхідна аутентифікація.', 'family-budget' ) );
    }

    global $wpdb;
    $amount_id = isset( $_POST['amount_id'] ) ? absint( $_POST['amount_id'] ) : 0;
    $user_id   = get_current_user_id();

    if ( ! $amount_id ) {
        wp_send_json_error( __( 'Невірний ID транзакції.', 'family-budget' ) );
    }

    // [SEC-3] UserFamily JOIN — забороняємо редагування чужих транзакцій.
    $has_access = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}Amount AS a
             INNER JOIN {$wpdb->prefix}Account AS acc ON a.Account_ID = acc.id
             INNER JOIN {$wpdb->prefix}UserFamily AS uf ON acc.Family_ID = uf.Family_ID
             WHERE a.id = %d AND uf.User_ID = %d",
            $amount_id,
            $user_id
        )
    );

    if ( ! $has_access ) {
        wp_send_json_error( __( 'Доступ заборонено.', 'family-budget' ) );
    }

    $amount_value = isset( $_POST['amount'] ) ? filter_var( wp_unslash( $_POST['amount'] ), FILTER_VALIDATE_FLOAT ) : false;

    if ( false === $amount_value || $amount_value <= 0 ) {
        wp_send_json_error( __( 'Невірне значення суми.', 'family-budget' ) );
    }

    $date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        $date = current_time( 'Y-m-d' );
    }

    $updated = $wpdb->update(
        $wpdb->prefix . 'Amount',
        array(
            'AmountType_ID' => isset( $_POST['type_id'] )     ? absint( $_POST['type_id'] )     : 1,
            'Account_ID'    => isset( $_POST['account_id'] )  ? absint( $_POST['account_id'] )  : 0,
            'Category_ID'   => isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
            'Currency_ID'   => isset( $_POST['currency_id'] ) ? absint( $_POST['currency_id'] ) : 1,
            'Amount_Value'  => $amount_value,
            'Note'          => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
            'created_at'    => $date . ' ' . gmdate( 'H:i:s' ),
            'updated_at'    => current_time( 'mysql' ),
        ),
        array( 'id' => $amount_id ),
        array( '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s' ),
        array( '%d' )
    );

    if ( false === $updated ) {
        error_log( sprintf(
            'FB Amount Update Error: %s | Amount ID: %d | Користувач: %d',
            $wpdb->last_error,
            $amount_id,
            $user_id
        ) );
        wp_send_json_error( __( 'Помилка оновлення. Спробуйте ще раз.', 'family-budget' ) );
    }

    wp_send_json_success( __( 'Транзакцію успішно оновлено.', 'family-budget' ) );
}

// ============================================================================
// AJAX: FILTER TRANSACTIONS — Фільтрація та пагінація таблиці
// ============================================================================

add_action( 'wp_ajax_fb_filter_transactions', 'fb_ajax_filter_transactions' );

/**
 * AJAX: Фільтрація та пагінація таблиці транзакцій.
 *
 * [PERF-1] Усунуто N+1 запит: has_params визначається через LEFT JOIN + COUNT()
 *           замість окремого get_var() у циклі для кожного рядка.
 * [SEC-6]  LIMIT та OFFSET передаються виключно через $wpdb->prepare().
 *
 * @since  1.0.26.2
 * @return void Виводить HTML-рядки таблиці та пагінацію.
 */
function fb_ajax_filter_transactions(): void {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );

    if ( ! is_user_logged_in() ) {
        wp_die( esc_html__( 'Не авторизовано.', 'family-budget' ) );
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // Санітизація всіх вхідних фільтрів.
    $search      = isset( $_POST['search'] )   ? sanitize_text_field( wp_unslash( $_POST['search'] ) )   : '';
    $date_filter = isset( $_POST['date'] )     ? sanitize_text_field( wp_unslash( $_POST['date'] ) )     : '';
    $type_filter = isset( $_POST['type'] )     ? absint( $_POST['type'] )                                : 0;
    $acc_filter  = isset( $_POST['account'] )  ? absint( $_POST['account'] )                             : 0;
    $cat_filter  = isset( $_POST['category'] ) ? absint( $_POST['category'] )                            : 0;
    $page        = isset( $_POST['page'] )     ? max( 1, absint( $_POST['page'] ) )                      : 1;

    $per_page = 10;
    $offset   = ( $page - 1 ) * $per_page;

    // Будуємо масив WHERE-умов із підготовлених фрагментів.
    $where_clauses = array( '1=1' );

    // Обмеження по родинах поточного користувача (ізоляція даних).
    $where_clauses[] = $wpdb->prepare(
        "acc.Family_ID IN (SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d)",
        $user_id
    );

    if ( ! empty( $search ) ) {
        $where_clauses[] = $wpdb->prepare( 'a.Note LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
    }

    if ( ! empty( $date_filter ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_filter ) ) {
        $where_clauses[] = $wpdb->prepare( 'DATE(a.created_at) = %s', $date_filter );
    }

    if ( $type_filter > 0 ) {
        $where_clauses[] = $wpdb->prepare( 'a.AmountType_ID = %d', $type_filter );
    }

    if ( $acc_filter > 0 ) {
        $where_clauses[] = $wpdb->prepare( 'a.Account_ID = %d', $acc_filter );
    }

    if ( $cat_filter > 0 ) {
        $where_clauses[] = $wpdb->prepare( 'a.Category_ID = %d', $cat_filter );
    }

    $where_sql = implode( ' AND ', $where_clauses );

    // Загальна кількість рядків для розрахунку пагінації.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — $where_sql складається з підготовлених фрагментів.
    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$wpdb->prefix}Amount AS a
         INNER JOIN {$wpdb->prefix}Account AS acc ON a.Account_ID = acc.id
         WHERE {$where_sql}"
    );

    $total_pages = max( 1, ceil( $total / $per_page ) );

    // Основний запит: [PERF-1] has_params через LEFT JOIN, [SEC-6] LIMIT/OFFSET через prepare().
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $transactions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT a.*,
                    acc.Account_Name,
                    cat.Category_Name,
                    cur.Currency_Symbol,
                    at.AmountType_Name,
                    COUNT(cp.id) AS has_params
             FROM {$wpdb->prefix}Amount AS a
             INNER JOIN {$wpdb->prefix}Account AS acc      ON a.Account_ID    = acc.id
             INNER JOIN {$wpdb->prefix}Category AS cat     ON a.Category_ID   = cat.id
             INNER JOIN {$wpdb->prefix}Currency AS cur     ON a.Currency_ID   = cur.id
             INNER JOIN {$wpdb->prefix}AmountType AS at    ON a.AmountType_ID = at.id
             LEFT  JOIN {$wpdb->prefix}CategoryParam AS cp ON cp.Category_ID  = a.Category_ID
             WHERE {$where_sql}
             GROUP BY a.id
             ORDER BY a.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        )
    );

    if ( empty( $transactions ) ) {
        echo '<tr><td colspan="7" class="fb-empty-state">' .
            esc_html__( 'Транзакцій не знайдено', 'family-budget' ) .
            '</td></tr>';
    } else {
        foreach ( $transactions as $t ) {
            $color     = '#dc3545';
            $row_class = 'fb-transaction-expense';

            if ( 'Дохід' === $t->AmountType_Name ) {
                $color     = '#28a745';
                $row_class = 'fb-transaction-income';
            } elseif ( 'Переказ' === $t->AmountType_Name ) {
                $color     = '#007bff';
                $row_class = 'fb-transaction-transfer';
            }

            echo '<tr class="' . esc_attr( $row_class ) . '">';
            echo '<td><small>' . esc_html( gmdate( 'd.m', strtotime( $t->created_at ) ) ) . '</small></td>';
            echo '<td><span class="fb-type-badge" style="color:' . esc_attr( $color ) . ';">' . esc_html( $t->AmountType_Name ) . '</span></td>';
            echo '<td><small>' . esc_html( $t->Account_Name ) . '</small></td>';
            echo '<td><strong>' . esc_html( $t->Category_Name ) . '</strong></td>';
            echo '<td><small>' . esc_html( $t->Note ) . '</small></td>';
            echo '<td class="fb-amount-col"><strong style="color:' . esc_attr( $color ) . ';">' .
                 esc_html( number_format( (float) $t->Amount_Value, 2, '.', ' ' ) ) . ' ' .
                 esc_html( $t->Currency_Symbol ) .
                 '</strong></td>';
            echo '<td class="fb-actions-col">';

            echo '<button type="button" class="fb-edit-btn" data-transaction-id="' . absint( $t->id ) . '" title="' . esc_attr__( 'Редагувати', 'family-budget' ) . '">📝</button>';

            // Кнопка параметрів — лише для категорій, що мають параметри.
            if ( $t->has_params > 0 ) {
                echo '<button type="button" class="fb-edit-params-btn"'
                   . ' data-transaction-id="' . absint( $t->id ) . '"'
                   . ' data-category-id="' . absint( $t->Category_ID ) . '"'
                   . ' title="' . esc_attr__( 'Параметри', 'family-budget' ) . '">⚙️</button>';
            }

            echo '<a href="' . esc_url( wp_nonce_url(
                add_query_arg( 'delete_amount', $t->id ),
                'delete_amount_' . $t->id,
                '_wpnonce'
            ) ) . '" class="fb-delete-btn"'
               . ' data-confirm="' . esc_attr__( 'Ви впевнені що хочете видалити цю транзакцію?', 'family-budget' ) . '">'
               . '🗑️</a>';

            echo '</td>';
            echo '</tr>';
        }
    }

    // Рядок пагінації.
    echo '<tr class="fb-pagination-row"><td colspan="7"><div class="fb-pagination">';

    if ( $page > 1 ) {
        echo '<button class="fb-page-btn fb-prev-page" data-page="' . ( $page - 1 ) . '">← ' . esc_html__( 'Попередня', 'family-budget' ) . '</button>';
    } else {
        echo '<button class="fb-page-btn" disabled>← ' . esc_html__( 'Попередня', 'family-budget' ) . '</button>';
    }

    echo '<span class="fb-page-info">' . sprintf(
        esc_html__( 'Сторінка %1$d з %2$d', 'family-budget' ),
        $page,
        $total_pages
    ) . '</span>';

    if ( $page < $total_pages ) {
        echo '<button class="fb-page-btn fb-next-page" data-page="' . ( $page + 1 ) . '">' . esc_html__( 'Наступна', 'family-budget' ) . ' →</button>';
    } else {
        echo '<button class="fb-page-btn" disabled>' . esc_html__( 'Наступна', 'family-budget' ) . ' →</button>';
    }

    echo '</div></td></tr>';
    wp_die();
}

// ============================================================================
// AJAX: GET CATEGORY PARAMS — Отримання параметрів категорії
// ============================================================================

add_action( 'wp_ajax_fb_get_category_params', 'fb_ajax_get_category_params' );

/**
 * AJAX: Отримання параметрів категорії для форми редагування.
 *
 * [SEC-4] UserFamily JOIN — забороняємо доступ до параметрів чужих транзакцій.
 *
 * @since  1.0.26.2
 * @return void Повертає JSON-відповідь із масивом параметрів.
 */
function fb_ajax_get_category_params(): void {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( __( 'Необхідна аутентифікація.', 'family-budget' ) );
    }

    global $wpdb;
    $amount_id = isset( $_POST['amount_id'] ) ? absint( $_POST['amount_id'] ) : 0;
    $user_id   = get_current_user_id();

    if ( ! $amount_id ) {
        wp_send_json_error( __( 'Невірний ID транзакції.', 'family-budget' ) );
    }

    // [SEC-4] Перевірка доступу через UserFamily JOIN.
    $has_access = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}Amount AS a
             INNER JOIN {$wpdb->prefix}Account AS acc ON a.Account_ID = acc.id
             INNER JOIN {$wpdb->prefix}UserFamily AS uf ON acc.Family_ID = uf.Family_ID
             WHERE a.id = %d AND uf.User_ID = %d",
            $amount_id,
            $user_id
        )
    );

    if ( ! $has_access ) {
        wp_send_json_error( __( 'Доступ заборонено.', 'family-budget' ) );
    }

    $params = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT cp.*,
                    pt.ParameterType_Name,
                    (
                        SELECT ap.AmountParam_Value
                        FROM {$wpdb->prefix}AmountParam AS ap
                        WHERE ap.Amount_ID = %d AND ap.CategoryParam_ID = cp.id
                        LIMIT 1
                    ) AS current_value
             FROM {$wpdb->prefix}CategoryParam AS cp
             INNER JOIN {$wpdb->prefix}ParameterType AS pt ON cp.ParameterType_ID = pt.id
             INNER JOIN {$wpdb->prefix}Amount AS a ON a.Category_ID = cp.Category_ID
             WHERE a.id = %d
             ORDER BY cp.CategoryParam_Order ASC",
            $amount_id,
            $amount_id
        )
    );

    if ( empty( $params ) ) {
        wp_send_json_error( __( 'Параметри для цієї категорії не знайдені.', 'family-budget' ) );
    }

    wp_send_json_success( $params );
}

// ============================================================================
// AJAX: UPDATE CATEGORY PARAMS — Оновлення параметрів категорії
// ============================================================================

add_action( 'wp_ajax_fb_update_category_params', 'fb_ajax_update_category_params' );

/**
 * AJAX: Оновлення параметрів категорії для транзакції.
 *
 * [SEC-5] UserFamily JOIN — забороняємо запис у параметри чужих транзакцій.
 * Стратегія: DELETE всіх існуючих + INSERT нових значень.
 *
 * @since  1.0.26.2
 * @return void Повертає JSON-відповідь.
 */
function fb_ajax_update_category_params(): void {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( __( 'Необхідна аутентифікація.', 'family-budget' ) );
    }

    global $wpdb;
    $amount_id = isset( $_POST['amount_id'] ) ? absint( $_POST['amount_id'] ) : 0;
    $params    = ( isset( $_POST['params'] ) && is_array( $_POST['params'] ) ) ? $_POST['params'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $user_id   = get_current_user_id();

    if ( ! $amount_id || empty( $params ) ) {
        wp_send_json_error( __( 'Невалідні дані.', 'family-budget' ) );
    }

    // [SEC-5] Перевірка доступу через UserFamily JOIN.
    $has_access = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}Amount AS a
             INNER JOIN {$wpdb->prefix}Account AS acc ON a.Account_ID = acc.id
             INNER JOIN {$wpdb->prefix}UserFamily AS uf ON acc.Family_ID = uf.Family_ID
             WHERE a.id = %d AND uf.User_ID = %d",
            $amount_id,
            $user_id
        )
    );

    if ( ! $has_access ) {
        wp_send_json_error( __( 'Доступ заборонено.', 'family-budget' ) );
    }

    // Видаляємо старі параметри перед вставкою нових.
    $wpdb->delete(
        $wpdb->prefix . 'AmountParam',
        array( 'Amount_ID' => $amount_id ),
        array( '%d' )
    );

    foreach ( $params as $param_id => $param_value ) {
        $param_value = trim( sanitize_text_field( wp_unslash( (string) $param_value ) ) );
        if ( '' === $param_value ) {
            continue;
        }

        $wpdb->insert(
            $wpdb->prefix . 'AmountParam',
            array(
                'Amount_ID'         => $amount_id,
                'CategoryParam_ID'  => absint( $param_id ),
                'AmountParam_Value' => $param_value,
                'created_at'        => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s' )
        );
    }

    wp_send_json_success( __( 'Параметри успішно оновлено.', 'family-budget' ) );
}

// ============================================================================
// IMPORT — Імпорт транзакцій з файлів
// ============================================================================

/*
 * [FIX-C] Реєстрація AJAX-хуків імпорту безпосередньо в amount.php.
 *
 * Попередня архітектура покладалась виключно на FB_Import::register_hooks()
 * у class-fb-import.php. Але якщо той файл підключається тільки через shortcode,
 * під час AJAX-запиту (admin-ajax.php) клас взагалі не завантажується →
 * WordPress повертає "0" → JS отримує невалідний JSON → мовчазний збій.
 *
 * Тепер хуки реєструються тут гарантовано, навіть якщо клас ще не завантажений.
 * Самі обробники lazy-завантажують клас перед виконанням.
 */
add_action( 'wp_ajax_fb_import_upload', 'fb_ajax_import_upload_handler' );
add_action( 'wp_ajax_fb_import_chunk',  'fb_ajax_import_chunk_handler' );
// Фоновий cron для синхронізації курсів — реєструємо тут бо amount.php завантажується завжди.
add_action( 'fb_cron_sync_currency_rates', 'fb_cron_sync_currency_rates_handler' );

/**
 * Cron-обгортка: синхронізація курсів у фоні.
 *
 * @since  3.1.0
 * @param  int $family_id ID родини.
 * @return void
 */
function fb_cron_sync_currency_rates_handler( int $family_id ): void {
    if ( ! class_exists( 'FB_Import' ) ) {
        require_once __DIR__ . '/class-fb-import.php';
    }
    if ( class_exists( 'FB_Import' ) ) {
        FB_Import::cron_sync_rates( $family_id );
    }
}

/**
 * AJAX-обгортка для FB_Import::ajax_upload().
 *
 * Гарантує доступність класу перед делегуванням виклику.
 *
 * @since  3.1.0
 * @return void
 */
function fb_ajax_import_upload_handler(): void {
    error_log( '[FB Import] ajax_upload called at ' . current_time( 'Y-m-d H:i:s' ) );

    if ( ! class_exists( 'FB_Import' ) ) {
        // Явне підключення файлу класу — він у тій самій директорії що й amount.php.
        require_once __DIR__ . '/class-fb-import.php';
    }

    if ( ! class_exists( 'FB_Import' ) ) {
        error_log( '[FB Import] FATAL: class FB_Import not found after require_once' );
        wp_send_json_error( array( 'message' => 'Клас FB_Import не знайдено.' ) );
    }

    FB_Import::ajax_upload();
}

/**
 * AJAX-обгортка для FB_Import::ajax_chunk().
 *
 * @since  3.1.0
 * @return void
 */
function fb_ajax_import_chunk_handler(): void {
    error_log( '[FB Import] ajax_chunk called at ' . current_time( 'Y-m-d H:i:s' ) );

    if ( ! class_exists( 'FB_Import' ) ) {
        require_once __DIR__ . '/class-fb-import.php';
    }

    if ( ! class_exists( 'FB_Import' ) ) {
        error_log( '[FB Import] FATAL: class FB_Import not found after require_once' );
        wp_send_json_error( array( 'message' => 'Клас FB_Import не знайдено.' ) );
    }

    FB_Import::ajax_chunk();
}

add_action( 'template_redirect', 'fb_handle_xls_import' );

/**
 * Обробка імпорту файлу транзакцій (підтримує CSV).
 *
 * Перевіряє nonce, права доступу, тип та розмір файлу (max 10MB).
 * Після успішного імпорту робить редирект із кількістю завантажених транзакцій.
 *
 * @since  1.0.20.0
 * @return void
 */
function fb_handle_xls_import(): void {
    if ( ! isset( $_POST['fb_action'] ) || 'import_xls' !== $_POST['fb_action'] ) {
        return;
    }

    if ( ! isset( $_POST['fb_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fb_import_nonce'] ) ), 'fb_import_xls' ) ) {
        wp_die(
            esc_html__( 'Помилка перевірки безпеки.', 'family-budget' ),
            esc_html__( 'Помилка безпеки', 'family-budget' ),
            array( 'response' => 403 )
        );
    }

    if ( ! current_user_can( 'family_member' ) && ! current_user_can( 'manage_options' ) ) {
        wp_die(
            esc_html__( 'У вас немає прав на імпорт транзакцій.', 'family-budget' ),
            esc_html__( 'Доступ заборонено', 'family-budget' ),
            array( 'response' => 403 )
        );
    }

    if ( empty( $_FILES['xls_file'] ) || UPLOAD_ERR_OK !== $_FILES['xls_file']['error'] ) {
        wp_safe_redirect( add_query_arg( 'import_error', 'upload_failed', wp_get_referer() ) );
        exit;
    }

    $file      = $_FILES['xls_file'];
    $extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

    if ( ! in_array( $extension, array( 'csv' ), true ) ) {
        wp_safe_redirect( add_query_arg( 'import_error', 'invalid_type', wp_get_referer() ) );
        exit;
    }

    if ( $file['size'] > 10 * 1024 * 1024 ) {
        wp_safe_redirect( add_query_arg( 'import_error', 'file_too_large', wp_get_referer() ) );
        exit;
    }

    global $wpdb;
    $user_id   = get_current_user_id();
    $family_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d LIMIT 1",
            $user_id
        )
    );

    if ( ! $family_id ) {
        wp_die(
            esc_html__( "Немає сім'ї прив'язаної до вашого акаунту. Створіть або приєднайтеся до сім'ї спочатку.", 'family-budget' ),
            esc_html__( "Сім'ю не знайдено", 'family-budget' ),
            array( 'back_link' => true )
        );
    }

    if ( ! class_exists( 'FB_Import' ) ) {
        wp_safe_redirect( add_query_arg( 'import_error', 'class_missing', wp_get_referer() ) );
        exit;
    }

    try {
        $result = FB_Import::process_xls_file( $file, $family_id );

        if ( $result['success'] ) {
            wp_safe_redirect( add_query_arg( 'import_success', absint( $result['imported'] ), wp_get_referer() ) );
        } else {
            wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( $result['message'] ), wp_get_referer() ) );
        }
    } catch ( Exception $e ) {
        error_log( sprintf( 'FB Import Exception: %s', $e->getMessage() ) );
        wp_safe_redirect( add_query_arg( 'import_error', 'exception', wp_get_referer() ) );
    }

    exit;
}

// ============================================================================
// AJAX: BALANCE — Розрахунок та відображення балансу
// ============================================================================

add_action( 'wp_ajax_fb_get_main_balance', 'fb_ajax_get_main_balance' );

/**
 * AJAX: Розрахунок та виведення основного балансу родини.
 *
 * Формула: Баланс = Доходи − Витрати − Перекази.
 * Використовує єдиний JOIN-запит без N+1.
 *
 * @since  1.0.0
 * @return void Виводить HTML-блок з балансом.
 */
function fb_ajax_get_main_balance(): void {
    if ( ! is_user_logged_in() ) {
        echo '<p class="fb-error">' . esc_html__( 'Будь ласка, увійдіть.', 'family-budget' ) . '</p>';
        wp_die();
    }

    global $wpdb;
    $user_id = get_current_user_id();

    $amounts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT a.Amount_Value, at.AmountType_Name
             FROM {$wpdb->prefix}Amount AS a
             INNER JOIN {$wpdb->prefix}Account AS acc   ON a.Account_ID   = acc.id
             INNER JOIN {$wpdb->prefix}AmountType AS at ON a.AmountType_ID = at.id
             INNER JOIN {$wpdb->prefix}UserFamily AS uf ON acc.Family_ID   = uf.Family_ID
             WHERE uf.User_ID = %d",
            $user_id
        )
    );

    $income = $expense = $transfer = 0.0;

    foreach ( $amounts as $amount ) {
        switch ( $amount->AmountType_Name ) {
            case 'Дохід':
                $income += (float) $amount->Amount_Value;
                break;
            case 'Витрата':
                $expense += (float) $amount->Amount_Value;
                break;
            case 'Переказ':
                $transfer += (float) $amount->Amount_Value;
                break;
        }
    }

    $balance = $income - $expense - $transfer;
    $color   = $balance >= 0 ? '#28a745' : '#dc3545';
    ?>
    <div class="fb-balance-widget">
        <div class="fb-balance-value" style="color:<?php echo esc_attr( $color ); ?>;">
            <?php echo esc_html( number_format( $balance, 2, '.', ' ' ) ); ?> ₴
        </div>
        <div class="fb-balance-updated">
            <?php printf( esc_html__( 'Оновлено: %s', 'family-budget' ), esc_html( current_time( 'H:i' ) ) ); ?>
        </div>
        <div class="fb-balance-breakdown">
            <div class="fb-balance-income">
                <strong style="color:#28a745;">
                    <?php printf( esc_html__( 'Доходи: +%s ₴', 'family-budget' ), esc_html( number_format( $income, 2 ) ) ); ?>
                </strong>
            </div>
            <div class="fb-balance-expense">
                <strong style="color:#dc3545;">
                    <?php printf( esc_html__( 'Витрати: -%s ₴', 'family-budget' ), esc_html( number_format( $expense, 2 ) ) ); ?>
                </strong>
            </div>
            <div class="fb-balance-transfer">
                <strong style="color:#007bff;">
                    <?php printf( esc_html__( 'Перекази: -%s ₴', 'family-budget' ), esc_html( number_format( $transfer, 2 ) ) ); ?>
                </strong>
            </div>
        </div>
    </div>
    <?php
    wp_die();
}

// ============================================================================
// AJAX: LOAD CAT PARAMS — Форма динамічних параметрів категорії
// ============================================================================

add_action( 'wp_ajax_fb_load_cat_params', 'fb_ajax_load_cat_params' );

/**
 * AJAX: Завантаження полів динамічних параметрів категорії для форми створення.
 *
 * Повертає HTML-поля залежно від типу параметра: число, дата або текст.
 *
 * @since  1.0.0
 * @return void Виводить HTML-поля параметрів.
 */
function fb_ajax_load_cat_params(): void {
    if ( ! isset( $_POST['cat_id'] ) ) {
        wp_die();
    }

    global $wpdb;
    $category_id = absint( $_POST['cat_id'] );

    $params = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT cp.*, pt.ParameterType_Name
             FROM {$wpdb->prefix}CategoryParam AS cp
             INNER JOIN {$wpdb->prefix}ParameterType AS pt ON cp.ParameterType_ID = pt.id
             WHERE cp.Category_ID = %d
             ORDER BY cp.CategoryParam_Order ASC",
            $category_id
        )
    );

    if ( empty( $params ) ) {
        wp_die();
    }

    foreach ( $params as $param ) {
        $type       = strtolower( $param->ParameterType_Name );
        $name       = esc_attr( $param->CategoryParam_Name );
        $field_id   = 'dyn_params_' . absint( $param->id );
        $field_name = 'dyn_params[' . absint( $param->id ) . ']';

        if ( 'число' === $type ) {
            printf(
                '<input type="number" name="%1$s" id="%2$s" placeholder="%3$s" step="0.01" class="fb-param-input" aria-label="%3$s">',
                esc_attr( $field_name ),
                esc_attr( $field_id ),
                $name
            );
        } elseif ( 'дата' === $type ) {
            printf(
                '<input type="date" name="%1$s" id="%2$s" class="fb-param-input" aria-label="%3$s">',
                esc_attr( $field_name ),
                esc_attr( $field_id ),
                $name
            );
        } else {
            printf(
                '<input type="text" name="%1$s" id="%2$s" placeholder="%3$s" class="fb-param-input" aria-label="%3$s">',
                esc_attr( $field_name ),
                esc_attr( $field_id ),
                $name
            );
        }
    }

    wp_die();
}

// ============================================================================
// SHORTCODE: RENDER UI — Головний інтерфейс бюджету
// ============================================================================

/**
 * Рендер головного інтерфейсу управління бюджетом (шорткод [fb_budget]).
 *
 * Макет — дві колонки:
 *  - Ліва (sidebar): Імпорт → Форма додавання транзакції → Баланс.
 *  - Права (main):   Фільтри → Таблиця транзакцій.
 *
 * Весь JavaScript підключається зовнішнім файлом js/amount.js через wp_enqueue_script.
 *
 * @since  1.0.0
 * @return string HTML-виведення сторінки бюджету.
 */
function fb_render_budget_interface(): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="fb-notice fb-notice-error">' .
            esc_html__( 'Будь ласка, увійдіть для доступу до інтерфейсу бюджету.', 'family-budget' ) .
            '</div>';
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // Підзапит для обмеження вибірки родинами поточного користувача.
    $family_sql = $wpdb->prepare(
        "(SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d)",
        $user_id
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — $family_sql містить підготовлені значення.
    $types = $wpdb->get_results(
        "SELECT id, AmountType_Name FROM {$wpdb->prefix}AmountType ORDER BY id ASC"
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $accs = $wpdb->get_results(
        "SELECT id, Account_Name FROM {$wpdb->prefix}Account
         WHERE Family_ID IN {$family_sql}
         ORDER BY Account_Order ASC"
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    // [SCHEMA-v2] Family_ID перенесено з Category до CategoryType — вибірка через JOIN.
    $cats = $wpdb->get_results(
        "SELECT c.id, c.Category_Name
         FROM {$wpdb->prefix}Category AS c
         INNER JOIN {$wpdb->prefix}CategoryType AS ct ON ct.id = c.CategoryType_ID
         WHERE ct.Family_ID IN {$family_sql}
         ORDER BY c.Category_Order ASC"
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    // [SCHEMA-v2] Family_ID та Currency_Primary перенесені до CurrencyFamily.
    $curs = $wpdb->get_results(
        "SELECT c.id, c.Currency_Symbol
         FROM {$wpdb->prefix}Currency AS c
         INNER JOIN {$wpdb->prefix}CurrencyFamily AS cf ON cf.Currency_ID = c.id
         WHERE cf.Family_ID IN {$family_sql}
         ORDER BY cf.CurrencyFamily_Primary DESC, c.id ASC"
    );

    ob_start();

    /*
     * [FIX] Priority 99 — виводимо JS ПІСЛЯ wp_print_footer_scripts (priority 20).
     * wp_localize_script виводить fbAmountData разом зі скриптом на priority 20.
     * При priority 10 (default) наш <script> виконувався ДО визначення fbAmountData
     * → "fbAmountData не знайдено" → повна тиша.
     */
    add_action(
        'wp_footer',
        static function () {
            if ( ! is_user_logged_in() ) {
                return;
            }
            ?>
            <script>
            /* FB Import v3.2 — чанковий AJAX-імпорт транзакцій */
            ;(function ($) {
                'use strict';

                if ( typeof fbAmountData === 'undefined' ) {
                    console.error('[FB Import] CRITICAL: fbAmountData not found. Script loaded before wp_localize_script?');
                    return;
                }

                console.log('[FB Import] fbAmountData OK:', fbAmountData.ajax_url);

                var FBImport = {
                    ajaxUrl  : fbAmountData.ajax_url,
                    nonce    : fbAmountData.nonce,
                    token    : null,
                    total    : 0,
                    offset   : 0,
                    inserted : 0,
                    errors   : 0,

                    init: function () {
                        $(document).on( 'click', '#fb-import-btn', function (e) {
                            e.preventDefault();
                            e.stopPropagation();
                            FBImport.start();
                        });
                    },

                    showOverlay: function ( text ) {
                        $('#fb-import-overlay-text').text( text );
                        $('#fb-import-overlay').addClass( 'fb-overlay-visible' );
                        $('body').css( 'overflow', 'hidden' );
                    },

                    hideOverlay: function () {
                        $('#fb-import-overlay').removeClass( 'fb-overlay-visible' );
                        $('body').css( 'overflow', '' );
                    },

                    updateProgress: function ( processed ) {
                        var pct = FBImport.total > 0 ? Math.round( processed / FBImport.total * 100 ) : 0;
                        $('#fb-import-bar').css( 'width', pct + '%' );
                        $('#fb-import-status').text(
                            'Оброблено: ' + processed + ' / ' + FBImport.total +
                            ' (' + pct + '%)  |  Додано: ' + FBImport.inserted +
                            '  |  Помилок: ' + FBImport.errors
                        );
                        FBImport.showOverlay( 'Імпорт... ' + pct + '% (' + processed + ' / ' + FBImport.total + ')' );
                    },

                    start: function () {
                        var fileInput = document.getElementById('fb-import-file');

                        if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
                            alert( 'Оберіть CSV-файл для імпорту.' );
                            return;
                        }

                        FBImport.token    = null;
                        FBImport.total    = 0;
                        FBImport.offset   = 0;
                        FBImport.inserted = 0;
                        FBImport.errors   = 0;

                        var fd = new FormData();
                        fd.append( 'action',   'fb_import_upload' );
                        fd.append( 'security', FBImport.nonce );
                        fd.append( 'xls_file', fileInput.files[0] );

                        $('#fb-import-progress').show();
                        $('#fb-import-bar').css({ 'width': '0%', 'background': '#0073aa' });
                        $('#fb-import-status').text( 'Завантаження файлу...' );
                        FBImport.showOverlay( 'Завантаження файлу на сервер...' );

                        console.log('[FB Import] Uploading file...');

                        $.ajax({
                            url         : FBImport.ajaxUrl,
                            method      : 'POST',
                            data        : fd,
                            processData : false,
                            contentType : false,
                            success: function ( resp ) {
                                console.log('[FB Import] Upload response:', resp);
                                if ( ! resp.success ) {
                                    FBImport.hideOverlay();
                                    alert( 'Помилка завантаження: ' + ( resp.data ? resp.data.message : JSON.stringify(resp) ) );
                                    return;
                                }
                                FBImport.token  = resp.data.token;
                                FBImport.total  = resp.data.total_rows;
                                FBImport.offset = 0;
                                console.log('[FB Import] Upload OK. Token:', FBImport.token, 'Rows:', FBImport.total);
                                FBImport.processChunk();
                            },
                            error: function ( xhr, status, err ) {
                                FBImport.hideOverlay();
                                console.error('[FB Import] Upload AJAX error:', status, err, xhr.responseText);
                                alert( 'Помилка підключення до сервера: ' + status );
                            }
                        });
                    },

                    processChunk: function () {
                        console.log('[FB Import] Processing chunk at offset:', FBImport.offset);
                        $.post(
                            FBImport.ajaxUrl,
                            {
                                action   : 'fb_import_chunk',
                                security : FBImport.nonce,
                                token    : FBImport.token,
                                offset   : FBImport.offset,
                            },
                            function ( resp ) {
                                console.log('[FB Import] Chunk response:', resp);
                                if ( ! resp.success ) {
                                    FBImport.hideOverlay();
                                    alert( 'Помилка чанку: ' + ( resp.data ? resp.data.message : JSON.stringify(resp) ) );
                                    return; // зупиняємо петлю
                                }
                                var d = resp.data;
                                FBImport.offset   = d.next_offset;
                                FBImport.inserted = d.total_imported;
                                FBImport.errors   = d.total_errors;
                                FBImport.updateProgress( d.processed );

                                if ( d.is_done ) {
                                    FBImport.onDone();
                                } else {
                                    setTimeout( function() { FBImport.processChunk(); }, 100 );
                                }
                            }
                        ).fail(function ( xhr ) {
                            /*
                             * [FIX-LOOP] 500 на останньому чанку (sync_rates timeout).
                             * Дані вже в БД — показуємо успіх з попередженням.
                             * НЕ робимо retry — це зупиняє нескінченну петлю.
                             */
                            var isLastChunk = ( FBImport.offset >= FBImport.total );
                            console.error('[FB Import] Chunk error. isLastChunk:', isLastChunk, xhr.status, xhr.responseText.substring(0,200));

                            FBImport.hideOverlay();

                            if ( isLastChunk ) {
                                // Всі дані вставлено, 500 через фонову синхронізацію курсів.
                                $( '#fb-import-bar' ).css({ 'width': '100%', 'background': '#46b450' });
                                $( '#fb-import-status' ).html(
                                    '✅ <strong>Імпорт завершено!</strong> ' +
                                    'Додано: ' + FBImport.inserted + ' | ' +
                                    'Помилок: ' + FBImport.errors +
                                    ' <span style="color:#e67e22">(курси валют синхронізуються у фоні)</span>'
                                );
                            } else {
                                // Справжня помилка в середині імпорту.
                                $( '#fb-import-bar' ).css('background', '#dc3545');
                                $( '#fb-import-status' ).html(
                                    '❌ <strong>Помилка сервера</strong> (offset ' + FBImport.offset + '). ' +
                                    'Додано: ' + FBImport.inserted + '. Перевірте error_log.'
                                );
                            }

                            var fi = document.getElementById('fb-import-file');
                            if ( fi ) { fi.value = ''; }
                        });
                    },

                    onDone: function () {
                        FBImport.hideOverlay();
                        $('#fb-import-bar').css({ 'width': '100%', 'background': '#46b450' });
                        $('#fb-import-status').html(
                            '✅ <strong>Імпорт завершено!</strong> ' +
                            'Додано: ' + FBImport.inserted + ' | ' +
                            'Помилок: ' + FBImport.errors
                        );
                        var fi = document.getElementById('fb-import-file');
                        if ( fi ) { fi.value = ''; }
                        console.log('[FB Import] DONE. Inserted:', FBImport.inserted, 'Errors:', FBImport.errors);
                    }
                };

                $( document ).ready(function () {
                    FBImport.init();
                    console.log('[FB Import] Initialized. ajaxUrl:', FBImport.ajaxUrl);
                });

            }(jQuery));
            </script>
            <?php
        },
        99  // Priority 99: виконується після wp_print_footer_scripts (priority 20).
    );

    if ( function_exists( 'fb_render_nav' ) ) {
        fb_render_nav();
    }
    ?>

    <div class="fb-budget-wrapper">

        <?php /* ── Системні повідомлення (success / deleted / import) ── */ ?>

        <?php if ( isset( $_GET['success'] ) && '1' === $_GET['success'] ) : ?>
            <div class="fb-notice fb-notice-success" role="alert">
                <?php esc_html_e( 'Транзакцію успішно збережено!', 'family-budget' ); ?>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) : ?>
            <div class="fb-notice fb-notice-info" role="alert">
                <?php esc_html_e( 'Транзакцію видалено.', 'family-budget' ); ?>
            </div>
        <?php endif; ?>

        <?php
        /* Повідомлення про завершення імпорту (fallback для старого POST-методу).
         * При AJAX-імпорті ці параметри не з'являються — статус показується через прогрес-бар. */
        if ( isset( $_GET['import_success'] ) ) : ?>
            <div class="fb-notice fb-notice-success" role="alert">
                <?php
                printf(
                    esc_html__( 'Імпорт завершено: %d транзакцій успішно завантажено.', 'family-budget' ),
                    absint( $_GET['import_success'] )
                );
                ?>
            </div>
        <?php endif; ?>

        <?php if ( isset( $_GET['import_error'] ) ) : ?>
            <div class="fb-notice fb-notice-error" role="alert">
                <?php
                $err_key = sanitize_text_field( wp_unslash( $_GET['import_error'] ) );
                $err_map = array(
                    'upload_failed'  => __( 'Помилка завантаження файлу.', 'family-budget' ),
                    'invalid_type'   => __( 'Непідтримуваний тип файлу. Дозволено: CSV.', 'family-budget' ),
                    'file_too_large' => __( 'Файл завеликий. Максимальний розмір: 10MB.', 'family-budget' ),
                    'class_missing'  => __( 'Помилка конфігурації сервера.', 'family-budget' ),
                    'exception'      => __( 'Виникла непередбачена помилка. Зверніться до адміністратора.', 'family-budget' ),
                );
                echo esc_html( $err_map[ $err_key ] ?? $err_key );
                ?>
            </div>
        <?php endif; ?>

        <div class="fb-budget-container">

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- ЛІВА КОЛОНКА (sidebar)                                        -->
            <!-- [UI-1] Порядок блоків: Імпорт → Форма додавання → Баланс    -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <aside class="fb-budget-sidebar">

                <?php /* ── Блок імпорту транзакцій (AJAX чанковий) ── */ ?>
                <?php if ( current_user_can( 'family_member' ) || current_user_can( 'manage_options' ) ) : ?>
                <div class="fb-import-section">
                    <p class="fb-import-label"><?php esc_html_e( 'Імпорт транзакцій', 'family-budget' ); ?></p>

                    <?php /*
                     * [FIX-B] <form> замінено на <div> — форма без method/action отримує
                     * GET за замовчуванням. amount.js (зовнішній) може зачепити будь-яку форму
                     * і спровокувати перезавантаження сторінки. <div> це унеможливлює.
                     */ ?>
                    <div id="fb-import-form" class="fb-import-form">
                        <div class="fb-import-row">
                            <input type="file" id="fb-import-file" name="xls_file" accept=".csv"
                                   class="fb-file-input"
                                   aria-label="<?php esc_attr_e( 'Виберіть CSV-файл для імпорту', 'family-budget' ); ?>">
                        </div>
                        <button type="button" id="fb-import-btn" class="fb-btn-import">
                            <img src="<?php echo esc_url( FB_PLUGIN_URL . 'img/icon-import.png' ); ?>"
                                 alt="" width="16" height="16" class="fb-btn-icon" aria-hidden="true">
                            <?php esc_html_e( 'import', 'family-budget' ); ?>
                        </button>
                    </div>

                    <?php /* Прогрес-бар (прихований до початку імпорту) */ ?>
                    <div id="fb-import-progress" style="display:none; margin-top:6px;">
                        <div style="height:10px; background:#e0e0e0; border-radius:3px; overflow:hidden;">
                            <div id="fb-import-bar"
                                 style="height:100%; width:0; background:#0073aa; transition:width .3s ease;">
                            </div>
                        </div>
                        <p id="fb-import-status"
                           style="margin:3px 0 0; font-size:11px; color:#555; line-height:1.4;"></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php /* ── Оверлей-блокувальник (керується через FB_Import JS) ── */ ?>
                <?php /*
                 * [FIX-4] Прибрано inline style="display:none" — він конфліктує з jQuery,
                 * яке при fadeIn() встановлює display:block (замість потрібного flex).
                 * Видимість контролюється виключно CSS-класом .fb-overlay-visible.
                 */ ?>
                <div id="fb-import-overlay"
                     role="dialog" aria-modal="true"
                     aria-label="<?php esc_attr_e( 'Імпорт триває', 'family-budget' ); ?>">
                    <div class="fb-overlay-inner">
                        <div class="fb-overlay-spinner" aria-hidden="true"></div>
                        <p id="fb-import-overlay-text">
                            <?php esc_html_e( 'Завантаження...', 'family-budget' ); ?>
                        </p>
                    </div>
                </div>
                <style>
                /* ── FB Import overlay ── */
                #fb-import-overlay {
                    display: none; /* керується через .fb-overlay-visible */
                    position: fixed;
                    inset: 0;
                    background: rgba(0, 0, 0, .55);
                    z-index: 999999;
                    align-items: center;
                    justify-content: center;
                }
                /* [FIX-4] display:flex відновлюється через клас, не через jQuery */
                #fb-import-overlay.fb-overlay-visible {
                    display: flex;
                }
                .fb-overlay-inner {
                    background: #fff;
                    padding: 28px 36px;
                    border-radius: 8px;
                    text-align: center;
                    min-width: 220px;
                    box-shadow: 0 4px 24px rgba(0, 0, 0, .2);
                }
                .fb-overlay-inner p {
                    margin: 12px 0 0;
                    font-size: 13px;
                    color: #333;
                }
                .fb-overlay-spinner {
                    width: 36px;
                    height: 36px;
                    margin: 0 auto;
                    border: 4px solid #e0e0e0;
                    border-top-color: #0073aa;
                    border-radius: 50%;
                    animation: fb-import-spin .8s linear infinite;
                }
                @keyframes fb-import-spin {
                    to { transform: rotate(360deg); }
                }
                </style>

                <!-- ──────────────────────────────────────────────────────── -->
                <!-- ФОРМА ДОДАВАННЯ ТРАНЗАКЦІЇ                              -->
                <!-- [UI-2] Порядок полів: Тип → Рахунок → Категорія →      -->
                <!--         Дата → Примітка → Сума+Валюта (inline) → Save  -->
                <!-- ──────────────────────────────────────────────────────── -->
                <div class="fb-form-card">
                    <form method="POST" class="fb-transaction-form">
                        <?php wp_nonce_field( 'fb_add_amount', 'fb_amt_nonce' ); ?>
                        <input type="hidden" name="fb_action" value="add_amount">

                        <?php /* 1 — Тип транзакції */ ?>
                        <select name="type_id" required class="fb-form-control">
                            <?php foreach ( $types as $type ) : ?>
                                <option value="<?php echo absint( $type->id ); ?>">
                                    <?php echo esc_html( $type->AmountType_Name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php /* 2 — Рахунок */ ?>
                        <select name="acc_id" required class="fb-form-control">
                            <?php foreach ( $accs as $acc ) : ?>
                                <option value="<?php echo absint( $acc->id ); ?>">
                                    <?php echo esc_html( $acc->Account_Name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php /* 3 — Категорія */ ?>
                        <select name="cat_id" id="fb-cat-select" required class="fb-form-control">
                            <option value=""><?php esc_html_e( '— Виберіть категорію —', 'family-budget' ); ?></option>
                            <?php foreach ( $cats as $cat ) : ?>
                                <option value="<?php echo absint( $cat->id ); ?>">
                                    <?php echo esc_html( $cat->Category_Name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php /* 4 — Дата транзакції */ ?>
                        <input type="date" name="date"
                               value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"
                               required class="fb-form-control">

                        <?php /* 5 — Примітка (необов'язкове поле) */ ?>
                        <input type="text" name="note"
                               placeholder="<?php esc_attr_e( "Примітка (необов'язково)", 'family-budget' ); ?>"
                               class="fb-form-control">

                        <?php /* Динамічні параметри категорії (завантажуються AJAX) */ ?>
                        <div id="fb-params-container" class="fb-params-container" aria-live="polite"></div>

                        <?php /* 6+7 — Сума + Валюта в одному рядку */ ?>
                        <div class="fb-form-row-inline">
                            <input type="number" name="val" step="0.01" min="0.01"
                                   placeholder="<?php esc_attr_e( 'Сума', 'family-budget' ); ?>"
                                   required class="fb-form-control fb-amount-input">
                            <select name="cur_id" required class="fb-form-control fb-currency-select">
                                <?php foreach ( $curs as $cur ) : ?>
                                    <option value="<?php echo absint( $cur->id ); ?>">
                                        <?php echo esc_html( $cur->Currency_Symbol ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php /* Кнопка збереження — окремий рядок */ ?>
                        <button type="submit" class="fb-btn-submit fb-btn-full">
                            💾 <?php esc_html_e( 'Зберегти', 'family-budget' ); ?>
                        </button>

                    </form>
                </div>

                <?php /* ── Блок балансу (завантажується AJAX) ── */ ?>
                <div class="fb-card fb-balance-card">
                    <h4><?php esc_html_e( 'Баланс', 'family-budget' ); ?></h4>
                    <div id="fb-balance-loader" class="fb-balance-container">
                        <div class="fb-spinner" role="status">
                            <span class="sr-only"><?php esc_html_e( 'Завантаження балансу...', 'family-budget' ); ?></span>
                        </div>
                    </div>
                </div>

            </aside>
            <?php /* / .fb-budget-sidebar */ ?>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- ПРАВА ЧАСТИНА — Фільтри + Таблиця транзакцій                -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <main class="fb-budget-main">

                <!-- Панель AJAX-фільтрів -->
                <div class="fb-filter-row" role="search"
                     aria-label="<?php esc_attr_e( 'Фільтри транзакцій', 'family-budget' ); ?>">

                    <input type="text" id="fb-search"
                           placeholder="<?php esc_attr_e( '🔍 Пошук по примітці...', 'family-budget' ); ?>"
                           class="fb-filter-control">

                    <input type="date" id="fb-filter-date" class="fb-filter-control"
                           aria-label="<?php esc_attr_e( 'Фільтр по даті', 'family-budget' ); ?>">

                    <select id="fb-filter-type" class="fb-filter-control">
                        <option value=""><?php esc_html_e( 'Всі типи', 'family-budget' ); ?></option>
                        <?php foreach ( $types as $type ) : ?>
                            <option value="<?php echo absint( $type->id ); ?>">
                                <?php echo esc_html( $type->AmountType_Name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="fb-filter-account" class="fb-filter-control">
                        <option value=""><?php esc_html_e( 'Всі рахунки', 'family-budget' ); ?></option>
                        <?php foreach ( $accs as $acc ) : ?>
                            <option value="<?php echo absint( $acc->id ); ?>">
                                <?php echo esc_html( $acc->Account_Name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="fb-filter-category" class="fb-filter-control">
                        <option value=""><?php esc_html_e( 'Всі категорії', 'family-budget' ); ?></option>
                        <?php foreach ( $cats as $cat ) : ?>
                            <option value="<?php echo absint( $cat->id ); ?>">
                                <?php echo esc_html( $cat->Category_Name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php /* / .fb-filter-row */ ?>

                <!-- Таблиця транзакцій (tbody заповнюється через AJAX) -->
                <div class="fb-table-wrapper">
                    <table class="fb-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Дата', 'family-budget' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Рахунок', 'family-budget' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Категорія', 'family-budget' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Примітка', 'family-budget' ); ?></th>
                                <th scope="col" class="fb-amount-col"><?php esc_html_e( 'Сума', 'family-budget' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="fb-transactions-body">
                            <tr>
                                <td colspan="7" class="fb-empty-state">
                                    <div class="fb-spinner" role="status">
                                        <span class="sr-only"><?php esc_html_e( 'Завантаження...', 'family-budget' ); ?></span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </main>
            <?php /* / .fb-budget-main */ ?>

        </div>
        <?php /* / .fb-budget-container */ ?>

    </div>
    <?php /* / .fb-budget-wrapper */ ?>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- МОДАЛЬНЕ ВІКНО: Редагування транзакції                              -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <div id="fb-edit-modal" class="fb-modal" role="dialog"
         aria-modal="true" aria-labelledby="fb-edit-modal-title" aria-hidden="true">
        <div class="fb-modal-overlay"></div>
        <div class="fb-modal-content">
            <h3 id="fb-edit-modal-title" class="fb-modal-title">
                <?php esc_html_e( 'Редагувати транзакцію', 'family-budget' ); ?>
            </h3>
            <form id="fb-edit-form">
                <input type="hidden" id="edit-id">

                <div class="fb-form-field">
                    <label for="edit-type"><?php esc_html_e( 'Тип', 'family-budget' ); ?></label>
                    <select id="edit-type" class="fb-form-control">
                        <?php foreach ( $types as $type ) : ?>
                            <option value="<?php echo absint( $type->id ); ?>"><?php echo esc_html( $type->AmountType_Name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fb-form-field">
                    <label for="edit-account"><?php esc_html_e( 'Рахунок', 'family-budget' ); ?></label>
                    <select id="edit-account" class="fb-form-control">
                        <?php foreach ( $accs as $acc ) : ?>
                            <option value="<?php echo absint( $acc->id ); ?>"><?php echo esc_html( $acc->Account_Name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fb-form-field">
                    <label for="edit-category"><?php esc_html_e( 'Категорія', 'family-budget' ); ?></label>
                    <select id="edit-category" class="fb-form-control">
                        <?php foreach ( $cats as $cat ) : ?>
                            <option value="<?php echo absint( $cat->id ); ?>"><?php echo esc_html( $cat->Category_Name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="fb-form-field">
                    <label for="edit-date"><?php esc_html_e( 'Дата', 'family-budget' ); ?></label>
                    <input type="date" id="edit-date" class="fb-form-control" required>
                </div>

                <div class="fb-form-field">
                    <label for="edit-note"><?php esc_html_e( 'Примітка', 'family-budget' ); ?></label>
                    <input type="text" id="edit-note" class="fb-form-control">
                </div>

                <div class="fb-form-field">
                    <label><?php esc_html_e( 'Сума і валюта', 'family-budget' ); ?></label>
                    <div class="fb-form-row-inline">
                        <input type="number" id="edit-amount" step="0.01" min="0.01"
                               class="fb-form-control fb-amount-input" required>
                        <select id="edit-currency" class="fb-form-control fb-currency-select">
                            <?php foreach ( $curs as $cur ) : ?>
                                <option value="<?php echo absint( $cur->id ); ?>"><?php echo esc_html( $cur->Currency_Symbol ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="fb-modal-actions">
                    <button type="button" id="fb-save-btn" class="fb-btn-submit">
                        <?php esc_html_e( 'Зберегти', 'family-budget' ); ?>
                    </button>
                    <button type="button" id="fb-close-btn" class="fb-btn-cancel">
                        <?php esc_html_e( 'Скасувати', 'family-budget' ); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- МОДАЛЬНЕ ВІКНО: Редагування параметрів категорії                    -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <div id="fb-params-modal" class="fb-modal" role="dialog"
         aria-modal="true" aria-labelledby="fb-params-modal-title" aria-hidden="true">
        <div class="fb-modal-overlay"></div>
        <div class="fb-modal-content">
            <h3 id="fb-params-modal-title" class="fb-modal-title">
                <?php esc_html_e( 'Редагувати параметри категорії', 'family-budget' ); ?>
            </h3>
            <form id="fb-params-form">
                <input type="hidden" id="params-amount-id">
                <div id="fb-params-fields"></div>
                <div class="fb-modal-actions">
                    <button type="button" id="fb-save-params-btn" class="fb-btn-submit">
                        <?php esc_html_e( 'Зберегти параметри', 'family-budget' ); ?>
                    </button>
                    <button type="button" id="fb-close-params-btn" class="fb-btn-cancel">
                        <?php esc_html_e( 'Скасувати', 'family-budget' ); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php
    return ob_get_clean();
}

add_shortcode( 'fb_budget', 'fb_render_budget_interface' );
