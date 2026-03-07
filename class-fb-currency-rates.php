<?php
/**
 * Клас FB_Currency_Rates — Робота з курсами валют НБУ
 *
 * @package    FamilyBudget
 * @subpackage Currency
 * @since      1.0.0
 * @version    2.2.0
 *
 * ЗМІНИ v2.2.0:
 *  [ROOT-FIX] fetch_and_save_rates(): запит тепер використовує Currency_Code
 *             (ISO 4217: USD, EUR...) замість Currency_Symbol ($, €...).
 *             Саме поле Currency_Code порівнюється з кодами НБУ API.
 *             Потребує виконання міграції migration-add-currency-code.sql.
 *  [BUG-FIX]  Виправлено PHP Warning "Array to string conversion" у рядку
 *             логування: count($currencies) замість {$currencies}.
 *  [FIX-1]   URL НБУ будується вручну (add_query_arg ламає ?json).
 *  [FIX-2]   COALESCE(Currency_Primary, 0) <> 1 — NULL-безпечна умова.
 *  [FIX-3]   Transient не кешує порожній масив.
 *  [FIX-4]   Застарілий/порожній Transient видаляється і ігнорується.
 *  [FIX-5]   SSL-fallback при SSL-помилці wp_remote_get.
 *  [DBG]     Логування у wp-content/fb-currency-debug.log.
 *            Активація: define('FB_CURRENCY_DEBUG', true) у wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Клас для роботи з курсами валют НБУ.
 */
class FB_Currency_Rates {

    /**
     * Базова URL НБУ API.
     *
     * @var string
     */
    const API_URL = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json';

    /**
     * Префікс ключа Transient.
     *
     * @var string
     */
    const TRANSIENT_PREFIX = 'fb_nbu_rates_';

    /**
     * Час життя кешу (23 год у секундах).
     *
     * @var int
     */
    const TRANSIENT_TTL = 82800;

    // =========================================================================
    // DEBUG
    // =========================================================================

    /**
     * Записує рядок у debug-лог wp-content/fb-currency-debug.log.
     * Активний лише якщо define('FB_CURRENCY_DEBUG', true) у wp-config.php.
     *
     * @since  2.1.0
     * @param  string $msg Повідомлення для запису.
     * @return void
     */
    private static function dbg( string $msg ): void {
        if ( ! defined( 'FB_CURRENCY_DEBUG' ) || ! FB_CURRENCY_DEBUG ) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents(
            WP_CONTENT_DIR . '/fb-currency-debug.log',
            '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $msg . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Отримує масив курсів всіх валют НБУ на задану дату.
     *
     * Перевіряє Transient-кеш перед HTTP-запитом.
     * [FIX-3][FIX-4] Порожній масив ніколи не кешується і не повертається з кешу.
     *
     * @since  2.0.0
     * @param  string|null $date Дата Y-m-d. null — поточна дата сервера.
     * @return array<string,float> ['USD'=>43.14, 'EUR'=>50.89, ...] або [] при помилці.
     */
    public static function get_rates_for_date( ?string $date = null ): array {
        $date          = $date ?: current_time( 'Y-m-d' );
        $transient_key = self::TRANSIENT_PREFIX . $date;

        self::dbg( "========================================" );
        self::dbg( "get_rates_for_date() START | date={$date}" );

        // [FIX-4] Повертаємо лише непорожній кеш.
        $cached = get_transient( $transient_key );
        if ( ! empty( $cached ) && is_array( $cached ) ) {
            self::dbg( 'CACHE HIT: ' . count( $cached ) . ' курсів.' );
            return $cached;
        }

        if ( false !== $cached ) {
            self::dbg( 'CACHE STALE: порожній Transient — видаляємо.' );
            delete_transient( $transient_key );
        } else {
            self::dbg( 'CACHE MISS.' );
        }

        // [FIX-1] Будуємо URL вручну — add_query_arg() ламає ?json → ?json=.
        $api_date = str_replace( '-', '', $date );
        $api_url  = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json&date=' . $api_date;

        self::dbg( "HTTP REQUEST: {$api_url}" );

        $args = array(
            'timeout'     => 20,
            'httpversion' => '1.1',
            'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'sslverify'   => true,
        );

        $response = wp_remote_get( $api_url, $args );

        // [FIX-5] SSL-fallback.
        if ( is_wp_error( $response ) ) {
            self::dbg( 'HTTP WP_ERROR: ' . $response->get_error_message() . ' — retry sslverify=false' );
            $args['sslverify'] = false;
            $response          = wp_remote_get( $api_url, $args );

            if ( is_wp_error( $response ) ) {
                $err = $response->get_error_message();
                self::dbg( "HTTP FATAL: {$err}" );
                error_log( "[FB_Currency_Rates] API недоступний: {$err} | дата={$date}" );
                return array();
            }
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );

        self::dbg( "HTTP RESPONSE: code={$http_code}, bytes=" . strlen( $body ) );

        if ( 200 !== $http_code ) {
            error_log( "[FB_Currency_Rates] HTTP {$http_code} | дата={$date}" );
            return array();
        }

        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || empty( $data ) ) {
            self::dbg( 'JSON decode FAILED: ' . json_last_error_msg() );
            error_log( "[FB_Currency_Rates] Невалідний JSON | дата={$date}" );
            return array();
        }

        // Будуємо масив: ISO-код → курс.
        $rates = array();
        foreach ( $data as $item ) {
            if ( isset( $item['cc'], $item['rate'] ) && is_string( $item['cc'] ) ) {
                $rates[ strtoupper( trim( $item['cc'] ) ) ] = (float) $item['rate'];
            }
        }

        self::dbg( 'Курсів розпізнано: ' . count( $rates )
            . ' | USD=' . ( $rates['USD'] ?? 'n/a' )
            . ' | EUR=' . ( $rates['EUR'] ?? 'n/a' )
        );

        if ( empty( $rates ) ) {
            error_log( "[FB_Currency_Rates] Порожній масив після парсингу | дата={$date}" );
            return array();
        }

        // [FIX-3] Кешуємо лише непорожній результат.
        set_transient( $transient_key, $rates, self::TRANSIENT_TTL );
        self::dbg( "CACHE SET: {$transient_key}" );

        return $rates;
    }

    /**
     * Завантажує курси НБУ та зберігає у wp_CurrencyValue.
     *
     * [ROOT-FIX] Використовує поле Currency_Code (ISO: USD, EUR...) для
     * порівняння з кодами НБУ API. Currency_Symbol ($, €) для цього непридатний.
     * Потребує виконання міграції migration-add-currency-code.sql.
     *
     * @since  1.0.0
     * @param  string|null $date Дата Y-m-d. null — поточна дата.
     * @return bool true — збережено ≥1 курс; false — помилка або немає валют.
     */
    public static function fetch_and_save_rates( ?string $date = null ): bool {
        global $wpdb;

        $date = $date ?: current_time( 'Y-m-d' );

        self::dbg( "========================================" );
        self::dbg( "fetch_and_save_rates() START | date={$date}" );

        // 1. Отримуємо курси НБУ (з кешу або API).
        $nbu_rates = self::get_rates_for_date( $date );

        if ( empty( $nbu_rates ) ) {
            self::dbg( 'ABORT: get_rates_for_date() повернув порожній масив.' );
            return false;
        }

        // 2. Вибираємо НЕ-основні валюти.
        // [SCHEMA-v2] Поле CurrencyFamily_Primary тепер у таблиці CurrencyFamily,
        //             а не в Currency. Беремо DISTINCT записи з довідника Currency,
        //             які в жодній родині не позначені як основна валюта,
        //             і мають заповнений Currency_Code.
        $currencies = $wpdb->get_results(
            "SELECT DISTINCT c.id, c.Currency_Symbol, c.Currency_Code
             FROM {$wpdb->prefix}Currency AS c
             INNER JOIN {$wpdb->prefix}CurrencyFamily AS cf ON cf.Currency_ID = c.id
             WHERE COALESCE(cf.CurrencyFamily_Primary, 0) <> 1
               AND c.Currency_Code <> ''"
        );

        // [BUG-FIX] count() замість {$currencies} — виправляємо Array to string conversion.
        self::dbg( 'DB: знайдено ' . count( (array) $currencies ) . ' НЕ-основних валют.' );

        if ( $wpdb->last_error ) {
            self::dbg( 'DB ERROR: ' . $wpdb->last_error );
            error_log( '[FB_Currency_Rates] DB помилка при виборці валют: ' . $wpdb->last_error );
        }

        if ( empty( $currencies ) ) {
            self::dbg( 'ABORT: немає НЕ-основних валют з заповненим Currency_Code.' );
            error_log( '[FB_Currency_Rates] Немає валют для синхронізації. Перевірте поле Currency_Code.' );
            return false;
        }

        foreach ( $currencies as $cur ) {
            self::dbg( "  → id={$cur->id}, symbol='{$cur->Currency_Symbol}', code='{$cur->Currency_Code}'" );
        }

        // 3. Зберігаємо курси до БД.
        $saved = 0;

        foreach ( $currencies as $cur ) {
            // [ROOT-FIX] Порівнюємо Currency_Code (USD, EUR...) з ключами масиву НБУ.
            $iso_code = strtoupper( trim( $cur->Currency_Code ) );

            if ( empty( $iso_code ) ) {
                self::dbg( "  SKIP id={$cur->id} '{$cur->Currency_Symbol}': Currency_Code порожній." );
                error_log( "[FB_Currency_Rates] Currency_Code порожній для id={$cur->id} '{$cur->Currency_Symbol}'. Заповніть поле у БД." );
                continue;
            }

            if ( ! isset( $nbu_rates[ $iso_code ] ) ) {
                self::dbg( "  SKIP '{$iso_code}': відсутній у відповіді НБУ." );
                error_log( "[FB_Currency_Rates] ISO-код '{$iso_code}' відсутній у НБУ | дата={$date}" );
                continue;
            }

            $rate = (float) $nbu_rates[ $iso_code ];

            if ( $rate <= 0 ) {
                self::dbg( "  SKIP '{$iso_code}': курс {$rate} <= 0." );
                continue;
            }

            $result = $wpdb->replace(
                $wpdb->prefix . 'CurrencyValue',
                array(
                    'Currency_ID'        => (int) $cur->id,
                    'CurrencyValue_Rate' => $rate,
                    'CurrencyValue_Date' => $date,
                    'created_at'         => current_time( 'mysql' ),
                ),
                array( '%d', '%f', '%s', '%s' )
            );

            if ( false !== $result ) {
                $saved++;
                self::dbg( "  SAVED '{$iso_code}': id={$cur->id}, rate={$rate}, date={$date}" );
            } else {
                self::dbg( "  DB REPLACE FAILED '{$iso_code}': " . $wpdb->last_error );
                error_log( "[FB_Currency_Rates] DB помилка для '{$iso_code}': {$wpdb->last_error}" );
            }
        }

        // [BUG-FIX] count() замість прямого масиву у рядку лога.
        self::dbg( "fetch_and_save_rates() DONE | saved={$saved}/" . count( (array) $currencies ) );

        return $saved > 0;
    }

    /**
     * Отримує курс конкретної валюти до UAH з БД або НБУ.
     *
     * @since  1.0.0
     * @param  int         $currency_id ID валюти у wp_Currency.
     * @param  string|null $date        Дата Y-m-d.
     * @return float Курс або 0.0 при помилці.
     */
    public static function get_rate( int $currency_id, ?string $date = null ): float {
        global $wpdb;

        $date = $date ?: current_time( 'Y-m-d' );

        $rate = $wpdb->get_var( $wpdb->prepare(
            "SELECT CurrencyValue_Rate
             FROM {$wpdb->prefix}CurrencyValue
             WHERE Currency_ID = %d AND CurrencyValue_Date = %s
             LIMIT 1",
            $currency_id,
            $date
        ) );

        if ( null !== $rate && (float) $rate > 0 ) {
            return (float) $rate;
        }

        self::fetch_and_save_rates( $date );

        $rate = $wpdb->get_var( $wpdb->prepare(
            "SELECT CurrencyValue_Rate
             FROM {$wpdb->prefix}CurrencyValue
             WHERE Currency_ID = %d AND CurrencyValue_Date = %s
             LIMIT 1",
            $currency_id,
            $date
        ) );

        return $rate ? (float) $rate : 0.0;
    }

    /**
     * Конвертує суму між двома валютами через UAH.
     *
     * @since  1.0.0
     * @param  float       $amount      Сума.
     * @param  int         $from_id     ID валюти-джерела.
     * @param  int         $to_id       ID валюти-призначення.
     * @param  string|null $date        Дата Y-m-d.
     * @param  array       $rates_cache Попередньо завантажений кеш [currency_id => rate].
     * @return float Конвертована сума.
     */
    public static function convert(
        float $amount,
        int $from_id,
        int $to_id,
        ?string $date = null,
        array $rates_cache = array()
    ): float {
        if ( $from_id === $to_id ) {
            return $amount;
        }

        $rate_from = $rates_cache[ $from_id ] ?? self::get_rate( $from_id, $date );
        $rate_to   = $rates_cache[ $to_id ]   ?? self::get_rate( $to_id, $date );

        if ( ! $rate_from || ! $rate_to ) {
            return $amount;
        }

        return ( $amount * $rate_from ) / $rate_to;
    }

    /**
     * Видаляє Transient-кеш курсів для заданої дати.
     *
     * @since  2.0.0
     * @param  string|null $date Дата Y-m-d. null — поточна дата.
     * @return bool Результат видалення.
     */
    public static function invalidate_cache( ?string $date = null ): bool {
        $date = $date ?: current_time( 'Y-m-d' );
        return delete_transient( self::TRANSIENT_PREFIX . $date );
    }
}