<?php
/**
 * Модуль Charts — Візуалізація сімейного бюджету
 *
 * Забезпечує повний цикл роботи з графіками:
 *  - Реєстрація шорткоду [fb_charts]
 *  - Підключення Chart.js та власного JS
 *  - Передача даних через wp_localize_script
 *  - AJAX-обробник для отримання даних діаграми
 *  - Двохрядковий компактний фільтр-бар (мітки + елементи керування)
 *  - Вертикальна стовпчаста діаграма (X: Часові відрізки, Y: Суми)
 *
 * Інтеграція (додати до family-budget.php):
 *   require_once FB_PLUGIN_DIR . 'fb-charts.php';
 *   // шорткод реєструється автоматично через add_shortcode() нижче
 *
 * @package    FamilyBudget
 * @subpackage Modules
 * @version    1.0.27.0
 * @since      1.0.27.0
 * @author     Family Budget Team
 *
 * CHANGELOG v1.0.27.0:
 * =====================
 * [NEW]  Новий модуль Charts — повна реалізація з нуля
 * [SEC]  check_ajax_referer(), UserFamily JOIN, $wpdb->prepare() скрізь
 * [SEC]  absint(), sanitize_text_field(), wp_unslash() для всіх входів
 * [PERF] Єдиний агрегований SQL із COALESCE-конвертацією валюти
 * [PERF] LIMIT 5000 запобігає перевантаженню при великих діапазонах
 * [UX]   Компактний дизайн «один екран» (≤500px загальна висота)
 * [UX]   Мультивибір (Ctrl+клік) для категорій та рахунків
 * [UX]   Динамічне відображення блоку кастомних дат
 * [BUG]  Коректне закриття <optgroup> (умовне, а не завжди)
 * [BUG]  Фільтрація категорій по типу через prop('disabled') — Firefox-сумісно
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Захист від прямого доступу
}
// Допоміжні функції (fb_get_families, fb_user_has_family_access тощо) завантажуються
// централізовано через family-budget.php як перший модуль: fb_function.php.

// ============================================================================
// БЛОК 1: РЕЄСТРАЦІЯ ШОРТКОДУ
// ============================================================================

add_shortcode( 'fb_charts', 'fb_render_charts_module' );

// ============================================================================
// БЛОК 2: AJAX-ОБРОБНИК ДАНИХ ГРАФІКА
// ============================================================================

add_action( 'wp_ajax_fb_get_chart_data', 'fb_ajax_get_chart_data' );

/**
 * AJAX: Отримання агрегованих даних транзакцій для Chart.js
 *
 * Алгоритм роботи:
 *  1. Перевіряє nonce та автентифікацію (403 при невдачі)
 *  2. Санітизує всі вхідні параметри (absint, sanitize_text_field)
 *  3. Підтверджує доступ користувача до родини через UserFamily JOIN
 *  4. Обчислює часовий діапазон за обраним периодом
 *  5. Будує динамічний WHERE-рядок з підготовлених фрагментів
 *  6. Виконує єдиний SQL із конвертацією валюти через COALESCE
 *  7. Форматує результат у структуру Chart.js (labels + datasets)
 *  8. Повертає JSON через wp_send_json_success/error
 *
 * Безпека:
 *  - check_ajax_referer() — CSRF-захист
 *  - UserFamily JOIN — перевірка, що family_id належить поточному користувачу
 *  - $wpdb->prepare() — захист від SQL-ін'єкцій
 *  - absint(), sanitize_text_field(), is_array() — санітизація всіх входів
 *
 * @since  1.0.27.0
 * @return void Виводить JSON і завершує виконання
 */
function fb_ajax_get_chart_data(): void {
    // -- Безпека: перевірка nonce --
    check_ajax_referer( 'fb_charts_nonce', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => __( 'Необхідна автентифікація.', 'family-budget' ) ), 403 );
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // -- Санітизація скалярних параметрів --
    $family_id     = absint( $_POST['family_id']      ?? 0 );
    $period        = sanitize_text_field( wp_unslash( $_POST['period']        ?? 'current_month' ) );
    $cat_type      = sanitize_text_field( wp_unslash( $_POST['category_type'] ?? 'all' ) );
    $currency_id   = absint( $_POST['currency_id']    ?? 0 );
    $by_days       = ( 'true' === sanitize_text_field( wp_unslash( $_POST['by_days'] ?? 'false' ) ) );
    $date_from_raw = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
    $date_to_raw   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );

    // -- Санітизація масивів ID --
    $category_ids = array();
    if ( ! empty( $_POST['category'] ) && is_array( $_POST['category'] ) ) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $category_ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['category'] ) ) ) );
    }

    $account_ids = array();
    if ( ! empty( $_POST['account'] ) && is_array( $_POST['account'] ) ) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $account_ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['account'] ) ) ) );
    }

    // -- Перевірка наявності family_id --
    if ( ! $family_id ) {
        wp_send_json_error( array( 'message' => __( 'Невірний ID родини.', 'family-budget' ) ), 400 );
    }

    // -- Перевірка доступу до родини: UserFamily JOIN --
    $has_access = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}UserFamily
             WHERE User_ID = %d AND Family_ID = %d",
            $user_id,
            $family_id
        )
    );

    if ( ! $has_access ) {
        wp_send_json_error(
            array( 'message' => __( 'Доступ до цієї родини заборонено.', 'family-budget' ) ),
            403
        );
    }

    // -- Обчислення часового діапазону за обраним периодом --
    [ $date_from, $date_to ] = fb_charts_resolve_date_range( $period, $date_from_raw, $date_to_raw );

    if ( null === $date_from || null === $date_to ) {
        wp_send_json_error( array( 'message' => __( 'Невірний або неповний часовий діапазон.', 'family-budget' ) ), 400 );
    }

    // -- Формат групування для DATE_FORMAT() --
    // По днях:   '%Y-%m-%d'  → мітки 'dd.mm'
    // По місяцях:'%Y-%m'     → мітки 'mm.YYYY'
    $sql_date_format = $by_days ? '%Y-%m-%d' : '%Y-%m';

    // -- Побудова динамічних WHERE-умов (кожен фрагмент — підготовлений) --
    $where_parts = array(
        $wpdb->prepare( 'acc.Family_ID = %d',  $family_id ),
        $wpdb->prepare( 'a.created_at >= %s',  $date_from . ' 00:00:00' ),
        $wpdb->prepare( 'a.created_at <= %s',  $date_to   . ' 23:59:59' ),
    );

    // Фільтр по типу категорії (якщо не 'all')
    if ( 'all' !== $cat_type && ctype_digit( $cat_type ) ) {
        $where_parts[] = $wpdb->prepare( 'c.CategoryType_ID = %d', (int) $cat_type );
    }

    // Фільтр по конкретних категоріях
    // Значення 0 = «all» що надсилається JS (опція value="all" → absint('all') = 0)
    $use_cat_filter = ! empty( $category_ids ) && ! in_array( 0, $category_ids, true );
    if ( $use_cat_filter ) {
        $ph            = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $where_parts[] = $wpdb->prepare( "c.id IN ($ph)", ...$category_ids );
    }

    // Фільтр по конкретних рахунках
    $use_acc_filter = ! empty( $account_ids ) && ! in_array( 0, $account_ids, true );
    if ( $use_acc_filter ) {
        $ph            = implode( ',', array_fill( 0, count( $account_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $where_parts[] = $wpdb->prepare( "acc.id IN ($ph)", ...$account_ids );
    }

    $where_sql = implode( ' AND ', $where_parts );

    // -- Головний SQL-запит з конвертацією валюти --
    //
    // Логіка конвертації:
    //   CurrencyValue зберігає: "1 одиниця валюти = X гривень (₴)"
    //   Конвертація транзакції у цільову валюту:
    //     сума_цілі = сума_джерела × курс_джерела / курс_цілі
    //   COALESCE(..., 1) — якщо курс відсутній, вважаємо 1 (транзакція вже в ₴)
    //
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                DATE_FORMAT( a.created_at, %s )      AS period_key,
                c.id                                  AS cat_id,
                c.Category_Name                       AS cat_name,
                ct.CategoryType_Name                  AS type_name,
                SUM(
                    a.Amount_Value
                    * COALESCE(
                        (
                            SELECT cv_src.CurrencyValue_Rate
                            FROM {$wpdb->prefix}CurrencyValue AS cv_src
                            WHERE cv_src.Currency_ID = a.Currency_ID
                              AND cv_src.CurrencyValue_Date <= DATE( a.created_at )
                            ORDER BY cv_src.CurrencyValue_Date DESC
                            LIMIT 1
                        ), 1
                    )
                    / COALESCE(
                        (
                            SELECT cv_tgt.CurrencyValue_Rate
                            FROM {$wpdb->prefix}CurrencyValue AS cv_tgt
                            WHERE cv_tgt.Currency_ID = %d
                              AND cv_tgt.CurrencyValue_Date <= DATE( a.created_at )
                            ORDER BY cv_tgt.CurrencyValue_Date DESC
                            LIMIT 1
                        ), 1
                    )
                )                                     AS total
             FROM {$wpdb->prefix}Amount       AS a
             JOIN {$wpdb->prefix}Account      AS acc ON acc.id = a.Account_ID
             JOIN {$wpdb->prefix}Category     AS c   ON c.id   = a.Category_ID
             JOIN {$wpdb->prefix}CategoryType AS ct  ON ct.id  = c.CategoryType_ID
             WHERE {$where_sql}
             GROUP  BY period_key, c.id
             ORDER  BY period_key ASC, c.Category_Name ASC
             LIMIT  5000",
            $sql_date_format,
            $currency_id
        )
    );

    // Обробка помилки БД
    if ( null === $rows ) {
        wp_send_json_error(
            array( 'message' => __( 'Помилка запиту до бази даних.', 'family-budget' ) ),
            500
        );
    }

    // Порожні дані — відповідаємо успіхом з порожньою структурою
    if ( empty( $rows ) ) {
        wp_send_json_success( array(
            'labels'   => array(),
            'datasets' => array(),
            'total'    => 0,
            'currency' => fb_charts_get_currency_symbol( $currency_id ),
        ) );
    }

    // Форматуємо для Chart.js і відповідаємо
    $chart_data             = fb_charts_format_for_chartjs( $rows, $by_days );
    $chart_data['currency'] = fb_charts_get_currency_symbol( $currency_id );

    wp_send_json_success( $chart_data );
}

// ============================================================================
// БЛОК 3: ДОПОМІЖНІ ФУНКЦІЇ БІЗНЕС-ЛОГІКИ
// ============================================================================

/**
 * Обчислення дат початку та кінця для обраного периоду
 *
 * Підтримувані ідентифікатори:
 *  - 'current_month' → перший–останній день поточного місяця
 *  - 'last_month'    → перший–останній день попереднього місяця
 *  - 'current_year'  → 01.01–31.12 поточного року
 *  - 'last_year'     → 01.01–31.12 минулого року
 *  - 'custom'        → використовує $date_from_raw / $date_to_raw
 *
 * @since  1.0.27.0
 * @param  string      $period        Ідентифікатор периоду
 * @param  string      $date_from_raw Рядок початкової дати (лише для 'custom')
 * @param  string      $date_to_raw   Рядок кінцевої дати (лише для 'custom')
 * @return array{0: string|null, 1: string|null} Масив [date_from, date_to] у Y-m-d або [null, null] при помилці
 */
function fb_charts_resolve_date_range( string $period, string $date_from_raw, string $date_to_raw ): array {
    $valid_re = '/^\d{4}-\d{2}-\d{2}$/';

    switch ( $period ) {
        case 'current_month':
            return array( gmdate( 'Y-m-01' ), gmdate( 'Y-m-t' ) );

        case 'last_month':
            $ts = strtotime( 'first day of last month' );
            return array( gmdate( 'Y-m-01', $ts ), gmdate( 'Y-m-t', $ts ) );

        case 'current_year':
            return array( gmdate( 'Y-01-01' ), gmdate( 'Y-12-31' ) );

        case 'last_year':
            $prev_year = (int) gmdate( 'Y' ) - 1;
            return array( "{$prev_year}-01-01", "{$prev_year}-12-31" );

        case 'custom':
            // Обидві дати обов'язкові та мають відповідати формату Y-m-d
            if ( ! preg_match( $valid_re, $date_from_raw ) ||
                 ! preg_match( $valid_re, $date_to_raw ) ) {
                return array( null, null );
            }
            // Якщо дати переставлені — міняємо місцями
            if ( $date_from_raw > $date_to_raw ) {
                return array( $date_to_raw, $date_from_raw );
            }
            return array( $date_from_raw, $date_to_raw );

        default:
            return array( null, null );
    }
}

/**
 * Форматування рядків БД у структуру Chart.js
 *
 * Формат результату:
 * {
 *   labels   : ['dd.mm', ...] або ['mm.YYYY', ...] — відсортовані periods,
 *   datasets : [
 *     {
 *       label           : 'Назва категорії',
 *       data            : [1200.00, 0, 800.00, ...],  // по одному значенню на period
 *       backgroundColor : '#4e79a7',
 *       borderColor     : '#4e79a7',
 *       borderWidth     : 1,
 *       borderRadius    : 3
 *     }, ...
 *   ],
 *   total    : 12345.67
 * }
 *
 * @since  1.0.27.0
 * @param  array  $rows    Рядки з БД: period_key, cat_id, cat_name, type_name, total
 * @param  bool   $by_days true — формат dd.mm; false — формат mm.YYYY
 * @return array  Структура даних для Chart.js
 */
function fb_charts_format_for_chartjs( array $rows, bool $by_days ): array {
    // Палітра кольорів для до 20 датасетів
    $palette = array(
        '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f',
        '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac',
        '#4dc9f6', '#f67019', '#f53794', '#537bc4', '#acc236',
        '#166a8f', '#00a950', '#58595b', '#8549ba', '#e8a838',
    );

    // Збираємо унікальні periods та категорії, зберігаючи порядок появи
    $period_keys  = array();
    $categories   = array(); // cat_id => ['name' => ..., 'type_name' => ...]

    foreach ( $rows as $row ) {
        if ( ! in_array( $row->period_key, $period_keys, true ) ) {
            $period_keys[] = $row->period_key;
        }
        if ( ! isset( $categories[ $row->cat_id ] ) ) {
            $categories[ $row->cat_id ] = array(
                'name'      => $row->cat_name,
                'type_name' => $row->type_name,
            );
        }
    }

    // Форматуємо мітки осі X для відображення
    $labels = array_map( static function ( string $pk ) use ( $by_days ): string {
        return $by_days
            ? gmdate( 'd.m', strtotime( $pk ) )            // '14.03'
            : gmdate( 'm.Y', strtotime( $pk . '-01' ) );   // '03.2025'
    }, $period_keys );

    // Будуємо матрицю [cat_id][period_key] = total для швидкої вибірки
    $matrix      = array();
    $grand_total = 0.0;

    foreach ( $rows as $row ) {
        $matrix[ $row->cat_id ][ $row->period_key ] = (float) $row->total;
        $grand_total += (float) $row->total;
    }

    // Формуємо датасети (один датасет = одна категорія)
    $datasets  = array();
    $color_idx = 0;

    foreach ( array_keys( $categories ) as $cat_id ) {
        // Дані: для кожного period_key значення або 0
        $data = array();
        foreach ( $period_keys as $pk ) {
            $data[] = round( $matrix[ $cat_id ][ $pk ] ?? 0.0, 2 );
        }

        $color      = $palette[ $color_idx % count( $palette ) ];
        $datasets[] = array(
            'label'           => esc_html( $categories[ $cat_id ]['name'] ),
            'data'            => $data,
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'borderWidth'     => 1,
            'borderRadius'    => 3,
        );
        $color_idx++;
    }

    return array(
        'labels'   => $labels,
        'datasets' => $datasets,
        'total'    => round( $grand_total, 2 ),
    );
}

/**
 * Отримання символу валюти за її ID
 *
 * @since  1.0.27.0
 * @param  int    $currency_id ID запису в таблиці Currency
 * @return string Символ (напр. '₴', '$') або порожній рядок
 */
function fb_charts_get_currency_symbol( int $currency_id ): string {
    if ( ! $currency_id ) {
        return '';
    }

    global $wpdb;
    $symbol = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT Currency_Symbol FROM {$wpdb->prefix}Currency WHERE id = %d LIMIT 1",
            $currency_id
        )
    );

    return $symbol ? esc_html( $symbol ) : '';
}

// ============================================================================
// БЛОК 4: ШОРТКОД — РЕНДЕРИНГ ІНТЕРФЕЙСУ [fb_charts]
// ============================================================================

/**
 * Рендеринг модуля графіків (шорткод [fb_charts])
 *
 * Виводить:
 *  Рядок 1 (мітки): Тип категорій / Категорії / Рахунки / Валюта / Період / По днях
 *  Рядок 2 (контроли): відповідні select / multiselect / checkbox
 *  Блок кастомних дат: прихований за замовчуванням, з'являється при «Вказати вручну»
 *  Канвас Chart.js з оверлеєм завантаження/помилки
 *
 * Скрипти підключаються тут (не глобально), щоб Chart.js завантажувався
 * лише на сторінках з шорткодом [fb_charts].
 *
 * @since  1.0.27.0
 * @return string HTML виведення шорткоду
 */
function fb_render_charts_module(): string {
    // -- Автентифікація --
    if ( ! is_user_logged_in() ) {
        return '<div class="fb-notice fb-notice-error">' .
            esc_html__( 'Будь ласка, увійдіть для доступу до графіків.', 'family-budget' ) .
            '</div>';
    }

    $plugin_url = FB_PLUGIN_URL;

    wp_enqueue_style(
        'fb-charts-css',
        $plugin_url . 'css/fb-charts.css',
        [],
        '1.0.27.0'
    );

    // -- Отримання ID поточної родини --
    $family_id = fb_get_current_family_id();

    if ( ! $family_id ) {
        return '<div class="fb-notice fb-notice-error">' .
            esc_html__( "Родину не знайдено. Спочатку створіть або приєднайтеся до родини.", 'family-budget' ) .
            '</div>';
    }

    // -- Дані для фільтрів (з helper-функцій family-budget.php) --
    $category_types = fb_get_all_category_types(); // усі категорії
    $categories     = fb_get_categories( $family_id );
    $accounts       = fb_get_accounts( $family_id );
    $currencies     = fb_get_currencies( $family_id );

    // Знаходимо основну валюту (Currency_Primary = 1)
    // Відповідно до завдання: SELECT c.Currency_Name ... WHERE c.Currency_Primary = 1
    $primary_currency_id = 0;
    foreach ( $currencies as $cur ) {
        if ( ! empty( $cur['Currency_Primary'] ) ) {
            $primary_currency_id = (int) $cur['id'];
            break;
        }
    }
    // Fallback: якщо жодна не позначена як основна — беремо першу
    if ( ! $primary_currency_id && ! empty( $currencies ) ) {
        $primary_currency_id = (int) $currencies[0]['id'];
    }

    // -- Підключення Chart.js (CDN) --
    // Перевіряємо, щоб не перереєструвати, якщо analit.php вже це зробив
    if ( ! wp_script_is( 'chart-js', 'registered' ) && ! wp_script_is( 'chartjs', 'registered' ) ) {
        wp_register_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );
    }
    wp_enqueue_script( 'chart-js' );

    // -- Підключення JS-модуля графіків --
    wp_enqueue_script(
        'fb-charts-js',
        FB_PLUGIN_URL . 'js/fb-charts.js',
        array( 'jquery', 'chart-js' ),
        '1.0.27.0',
        true // Footer: щоб DOM та Chart.js були вже готові
    );

    // -- Передача конфігурації через wp_localize_script --
    wp_localize_script(
        'fb-charts-js',
        'fbChartsData',
        array(
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'fb_charts_nonce' ),
            'familyId'          => $family_id,
            'primaryCurrencyId' => $primary_currency_id,
            'debug'             => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'strings'           => array(
                'loading'    => __( 'Завантаження даних…', 'family-budget' ),
                'noData'     => __( 'Немає даних за вибраний період', 'family-budget' ),
                'error'      => __( 'Помилка завантаження даних', 'family-budget' ),
                'networkErr' => __( "Помилка мережі. Перевірте з'єднання.", 'family-budget' ),
                'total'      => __( 'Загалом:', 'family-budget' ),
            ),
        )
    );

    // -- HTML --
    ob_start();
    ?>

    <div class="fb-charts-wrapper" id="fb-charts-module">
        <div class="fb-charts-card">

            <!-- ══════════════════════════════════════════════
                 РЯДОК 1: МІТКИ ФІЛЬТРІВ
                 aria-hidden щоб скрін-рідери не дублювали
                 мітки вже присутні в aria-label на контролах
                 ══════════════════════════════════════════════ -->
            <div class="fb-charts-filter-bar">
                <div class="fb-charts-filter-row fb-charts-filter-row--labels" aria-hidden="true">
                    <div class="fb-charts-filter-cell">
                        <span class="fb-charts-filter-label"><?php esc_html_e( 'Тип категорій', 'family-budget' ); ?></span>
                    </div>
                    <div class="fb-charts-filter-cell">
                        <span class="fb-charts-filter-label"><?php esc_html_e( 'Категорії', 'family-budget' ); ?></span>
                    </div>
                    <div class="fb-charts-filter-cell">
                        <span class="fb-charts-filter-label"><?php esc_html_e( 'Рахунки', 'family-budget' ); ?></span>
                    </div>
                    <div class="fb-charts-filter-cell">
                        <span class="fb-charts-filter-label"><?php esc_html_e( 'Валюта', 'family-budget' ); ?></span>
                    </div>
                    <div class="fb-charts-filter-cell fb-charts-period-cell">
                        <span class="fb-charts-filter-label"><?php esc_html_e( 'Період', 'family-budget' ); ?></span>
                    </div>
                    <div class="fb-charts-filter-cell fb-charts-filter-cell--narrow">
                        <span class="fb-charts-filter-label"><?php esc_html_e( 'По днях', 'family-budget' ); ?></span>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════
                     РЯДОК 2: ЕЛЕМЕНТИ КЕРУВАННЯ ФІЛЬТРАМИ
                     ══════════════════════════════════════════ -->
                <div class="fb-charts-filter-row fb-charts-filter-row--controls">

                    <!-- Тип категорій (одинарний select) -->
                    <div class="fb-charts-filter-cell">
                        <select id="fb-filter-category-type"
                                name="category_type"
                                class="fb-charts-select"
                                aria-label="<?php esc_attr_e( 'Тип категорій', 'family-budget' ); ?>">
                            <?php foreach ( (array) $category_types as $ct ) : ?>
                                <?php if ( is_array( $ct ) && isset( $ct['id'] ) ) : ?>
                                    <option value="<?php echo esc_attr( $ct['id'] ); ?>">
                                        <?php echo esc_html( $ct['CategoryType_Name'] ?? '' ); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Категорії (мультивибір) -->
                    <div class="fb-charts-filter-cell">
                        <select id="fb-filter-category"
                                name="category[]"
                                class="fb-charts-select fb-charts-multiselect"
                                multiple
                                size="3"
                                aria-label="<?php esc_attr_e( 'Категорії (Ctrl+клік для множинного вибору)', 'family-budget' ); ?>">
                            <option value="all" selected><?php esc_html_e( '— Всі —', 'family-budget' ); ?></option>
                            <?php
                            // [BUG-FIX] Закриваємо <optgroup> лише якщо він вже відкритий
                            $prev_type_name = null;
                            foreach ( $categories as $cat ) :
                                if ( $prev_type_name !== $cat['type_name'] ) :
                                    if ( null !== $prev_type_name ) :
                                        echo '</optgroup>';
                                    endif;
                                    echo '<optgroup label="' . esc_attr( $cat['type_name'] ) . '">';
                                    $prev_type_name = $cat['type_name'];
                                endif;
                                printf(
                                    '<option value="%d" data-type-id="%d">%s</option>',
                                    absint( $cat['id'] ),
                                    absint( $cat['CategoryType_ID'] ),
                                    esc_html( $cat['name'] )
                                );
                            endforeach;
                            if ( null !== $prev_type_name ) :
                                echo '</optgroup>';
                            endif;
                            ?>
                        </select>
                    </div>

                    <!-- Рахунки (мультивибір) -->
                    <div class="fb-charts-filter-cell">
                        <select id="fb-filter-account"
                                name="account[]"
                                class="fb-charts-select fb-charts-multiselect"
                                multiple
                                size="3"
                                aria-label="<?php esc_attr_e( 'Рахунки (Ctrl+клік для множинного вибору)', 'family-budget' ); ?>">
                            <option value="all" selected><?php esc_html_e( '— Всі —', 'family-budget' ); ?></option>
                            <?php foreach ( $accounts as $acc ) : ?>
                                <option value="<?php echo absint( $acc['id'] ); ?>">
                                    <?php echo esc_html( $acc['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Валюта (одинарний select; за замовчуванням — основна родини) -->
                    <div class="fb-charts-filter-cell">
                        <select id="fb-filter-currency"
                                name="currency_id"
                                class="fb-charts-select"
                                aria-label="<?php esc_attr_e( 'Відображати суми у валюті', 'family-budget' ); ?>">
                            <?php foreach ( $currencies as $cur ) : ?>
                                <option value="<?php echo absint( $cur['id'] ); ?>"
                                    <?php selected( (int) $cur['id'], $primary_currency_id ); ?>>
                                    <?php echo esc_html( $cur['name'] . ' (' . $cur['symbol'] . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Період + блок кастомних дат -->
                    <div class="fb-charts-filter-cell fb-charts-period-cell">
                        <select id="fb-filter-period"
                                name="period"
                                class="fb-charts-select"
                                aria-label="<?php esc_attr_e( 'Часовий період', 'family-budget' ); ?>">
                            <option value="current_month" selected>
                                <?php
                                /* translators: %s: місяць і рік, наприклад '03.2025' */
                                printf(
                                    esc_html__( 'Поточний місяць (%s)', 'family-budget' ),
                                    esc_html( gmdate( 'm.Y' ) )
                                );
                                ?>
                            </option>
                            <option value="last_month">
                                <?php
                                printf(
                                    esc_html__( 'Минулий місяць (%s)', 'family-budget' ),
                                    esc_html( gmdate( 'm.Y', strtotime( 'first day of last month' ) ) )
                                );
                                ?>
                            </option>
                            <option value="current_year">
                                <?php
                                printf(
                                    esc_html__( 'Поточний рік (%s)', 'family-budget' ),
                                    esc_html( gmdate( 'Y' ) )
                                );
                                ?>
                            </option>
                            <option value="last_year">
                                <?php
                                $prev_y = (string) ( (int) gmdate( 'Y' ) - 1 );
                                printf(
                                    esc_html__( 'Минулий рік (%s)', 'family-budget' ),
                                    esc_html( $prev_y )
                                );
                                ?>
                            </option>
                            <option value="custom"><?php esc_html_e( 'Вказати вручну…', 'family-budget' ); ?></option>
                        </select>

                        <!-- Блок кастомних дат: прихований за замовчуванням -->
                        <!-- [FIX] Видимість керується класом .is-visible через JS -->
                        <div id="fb-date-range-block"
                             class="fb-charts-date-range"
                             role="group"
                             aria-label="<?php esc_attr_e( 'Кастомний діапазон дат', 'family-budget' ); ?>">
                            <div class="fb-charts-date-group">
                                <label for="fb-filter-date-from" class="fb-charts-filter-label">
                                    <?php esc_html_e( 'З:', 'family-budget' ); ?>
                                </label>
                                <input type="date"
                                       id="fb-filter-date-from"
                                       name="date_from"
                                       class="fb-charts-date-input"
                                       value="<?php echo esc_attr( gmdate( 'Y-m-01' ) ); ?>"
                                       aria-label="<?php esc_attr_e( 'Дата початку кастомного діапазону', 'family-budget' ); ?>">
                            </div>
                            <div class="fb-charts-date-group">
                                <label for="fb-filter-date-to" class="fb-charts-filter-label">
                                    <?php esc_html_e( 'По:', 'family-budget' ); ?>
                                </label>
                                <input type="date"
                                       id="fb-filter-date-to"
                                       name="date_to"
                                       class="fb-charts-date-input"
                                       value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
                                       aria-label="<?php esc_attr_e( 'Дата закінчення кастомного діапазону', 'family-budget' ); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- По днях (чекбокс) -->
                    <div class="fb-charts-filter-cell fb-charts-filter-cell--narrow">
                        <label class="fb-charts-checkbox-label"
                               title="<?php esc_attr_e( 'Розбити по окремих днях (замість місяців)', 'family-budget' ); ?>">
                            <input type="checkbox"
                                   id="fb-filter-by-days"
                                   name="by_days"
                                   value="true"
                                   aria-label="<?php esc_attr_e( 'Аналіз по днях', 'family-budget' ); ?>">
                        </label>
                    </div>

                </div><!-- /fb-charts-filter-row--controls -->
            </div><!-- /fb-charts-filter-bar -->

            <!-- ══════════════════════════════════════════
                 ОБЛАСТЬ ГРАФІКА
                 Загальна висота враховує фільтр ≤500px
                 ══════════════════════════════════════════ -->
            <div class="fb-charts-canvas-wrap"
                 role="img"
                 aria-label="<?php esc_attr_e( 'Стовпчастий графік доходів та витрат', 'family-budget' ); ?>">

                <!-- Оверлей: завантаження / немає даних / помилка -->
                <div id="fb-charts-overlay" class="fb-charts-overlay fb-charts-overlay--loading" aria-live="polite">
                    <div class="fb-spinner" role="status">
                        <span class="sr-only"><?php esc_html_e( 'Завантаження…', 'family-budget' ); ?></span>
                    </div>
                    <p class="fb-charts-overlay-text">
                        <?php esc_html_e( 'Завантаження даних…', 'family-budget' ); ?>
                    </p>
                </div>

                <canvas id="fb-chart-canvas"></canvas>
            </div>

            <!-- Рядок стану: загальна сума, кількість записів -->
            <div id="fb-charts-status" class="fb-charts-status" aria-live="polite" aria-atomic="true"></div>

        </div><!-- /fb-charts-card -->
    </div><!-- /#fb-charts-module -->

    <?php
    return ob_get_clean();
}
