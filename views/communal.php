<?php
/**
 * Communal Services Module – "Комуналка"
 *
 * Registers the [fb_communal] shortcode, AJAX endpoints, and all
 * backend helper functions for the Family Budget plugin.
 *
 * @package    FamilyBudget
 * @subpackage Communal
 * @version    1.0.27.3
 * @since      1.0.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SHORTCODE REGISTRATION
// ═══════════════════════════════════════════════════════════════════════════════

add_shortcode( 'fb_communal', 'fb_render_communal_module' );

// ═══════════════════════════════════════════════════════════════════════════════
//  AJAX HOOKS
// ═══════════════════════════════════════════════════════════════════════════════

// [SEC-3] Лише авторизовані запити: wp_ajax_nopriv_* хуки видалено.
// Усі дані комунальних платежів прив'язані до родини конкретного користувача
// і не можуть бути публічними. Захист: check_ajax_referer() + get_current_user_id().
add_action( 'wp_ajax_fb_ajax_communal_get_params',     'fb_ajax_communal_get_params' );
add_action( 'wp_ajax_fb_ajax_communal_get_chart_data', 'fb_ajax_communal_get_chart_data' );

// ═══════════════════════════════════════════════════════════════════════════════
//  INTERNAL HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Resolve the Family_ID for the currently logged-in WordPress user.
 *
 * Returns 0 when the user is not logged in or has no family assignment.
 *
 * @since 1.0.27.0
 * @return int
 */
function fb_communal_get_family_id(): int {
    global $wpdb;

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return 0;
    }

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT Family_ID
               FROM {$wpdb->prefix}UserFamily
              WHERE User_ID = %d
              LIMIT 1",
            $user_id
        )
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  PUBLIC DATA FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Return categories that have at least one non-null AmountParam value.
 *
 * Only these categories are meaningful for the communal chart (they have
 * actual meter readings recorded).
 *
 * @since 1.0.27.0
 *
 * @param int $family_id Family primary key.
 * @return array<object> Objects with properties Category_ID, Category_Name.
 */
function fb_get_categories_have_value( int $family_id ): array {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT   c.id              AS Category_ID,
                      c.Category_Name
               FROM   {$wpdb->prefix}Category      AS c
               JOIN   {$wpdb->prefix}CategoryParam AS p  ON p.Category_ID      = c.id
               JOIN   {$wpdb->prefix}AmountParam   AS a  ON a.CategoryParam_ID = p.id
              WHERE   c.Family_ID = %d
                AND   a.AmountParam_Value IS NOT NULL
           GROUP BY   c.id, c.Category_Name
           ORDER BY   MIN(p.CategoryParam_Order)",
            $family_id
        )
    );
}

/**
 * Return all years for which the family has Amount records.
 *
 * Used to populate the Years multiselect (Filter 2).
 *
 * @since 1.0.27.0
 *
 * @param int $family_id Family primary key.
 * @return string[] Array of four-digit year strings, newest first.
 */
function fb_communal_get_years( int $family_id ): array {
    global $wpdb;

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT   YEAR(a.created_at)   AS y
               FROM   {$wpdb->prefix}Amount   AS a
               JOIN   {$wpdb->prefix}Account  AS t ON t.id = a.Account_ID
              WHERE   t.Family_ID = %d
           GROUP BY   YEAR(a.created_at)
           ORDER BY   y DESC",
            $family_id
        )
    );
}

/**
 * Return all parameters (CategoryParam rows) for a given category.
 *
 * Used to populate the Parameters multiselect (Filter 3) – both on initial
 * load and on AJAX refresh when the user changes the category.
 *
 * @since 1.0.27.0
 *
 * @param int $family_id   Family primary key.
 * @param int $category_id Category primary key.
 * @return array<object> Objects with properties id, CategoryParam_Name.
 */
function fb_get_category_param( int $family_id, int $category_id ): array {
    global $wpdb;

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT   p.id,
                      p.CategoryParam_Name
               FROM   {$wpdb->prefix}CategoryParam AS p
              WHERE   p.Family_ID   = %d
                AND   p.Category_ID = %d
           ORDER BY   p.CategoryParam_Order",
            $family_id,
            $category_id
        )
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  AJAX HANDLER – GET PARAMS (Filter 3 population)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * AJAX handler: return CategoryParam rows for the selected category.
 *
 * Expected POST fields:
 *   - nonce       (string) WordPress nonce: 'fb_communal_nonce'
 *   - category_id (int)    ID of the selected category
 *
 * @since 1.0.27.0
 * @return void  Terminates with wp_send_json_success / wp_send_json_error.
 */
function fb_ajax_communal_get_params(): void {
	// [SEC-3] Явна перевірка авторизації після видалення nopriv-хука.
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Необхідна авторизація.', 'family-budget' ) ), 401 );
	}

	check_ajax_referer( 'fb_communal_nonce', 'nonce' );

	$family_id   = fb_communal_get_family_id();
    $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;

    if ( ! $family_id || ! $category_id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'family-budget' ) ] );
    }

    $params = fb_get_category_param( $family_id, $category_id );

    wp_send_json_success( $params );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  AJAX HANDLER – GET CHART DATA
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * AJAX handler: fetch and compute chart data for all active filters.
 *
 * Retrieves raw meter readings from the DB, then computes the period-over-
 * period difference in PHP (equivalent to SQL LAG()) so the code remains
 * compatible with both MySQL 5.7 and 8.0+.
 *
 * Expected POST fields:
 *   - nonce       (string)        WordPress nonce: 'fb_communal_nonce'
 *   - category_id (int)           Selected category ID
 *   - years       (int[]|['all']) Selected years, or ['all'] for every year
 *   - param_ids   (int[])         Selected CategoryParam IDs
 *   - by_month    (0|1)           0 = group by year, 1 = group by month
 *
 * @since 1.0.27.0
 * @return void  Terminates with wp_send_json_success / wp_send_json_error.
 */
function fb_ajax_communal_get_chart_data(): void {
	// [SEC-3] Явна перевірка авторизації після видалення nopriv-хука.
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Необхідна авторизація.', 'family-budget' ) ), 401 );
	}

    check_ajax_referer( 'fb_communal_nonce', 'nonce' );

    // ── 1. Collect & sanitise inputs ─────────────────────────────────────────

    $family_id   = fb_communal_get_family_id();
    $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
    $by_month    = ! empty( $_POST['by_month'] ) && '1' === (string) $_POST['by_month'];

    // Years array: may contain integer strings or the literal 'all'
    $raw_years = ( isset( $_POST['years'] ) && is_array( $_POST['years'] ) )
        ? $_POST['years']
        : [];
    $all_years = in_array( 'all', $raw_years, true );
    $years     = array_values( array_filter( array_map( 'absint', $raw_years ) ) );

    // Param IDs array
    $raw_params = ( isset( $_POST['param_ids'] ) && is_array( $_POST['param_ids'] ) )
        ? $_POST['param_ids']
        : [];
    $param_ids  = array_values( array_filter( array_map( 'absint', $raw_params ) ) );

    if ( ! $family_id || ! $category_id || empty( $param_ids ) ) {
        wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'family-budget' ) ] );
    }

    global $wpdb;

    // ── 2. Build safe IN-placeholders ────────────────────────────────────────

    $param_ph = implode( ',', array_fill( 0, count( $param_ids ), '%d' ) );

    // ── 3. Optional year filter ───────────────────────────────────────────────

    $year_sql  = '';
    $year_args = [];

    if ( ! $all_years && ! empty( $years ) ) {
        $year_ph   = implode( ',', array_fill( 0, count( $years ), '%d' ) );
        $year_sql  = "AND YEAR(a.created_at) IN ($year_ph)";
        $year_args = $years;
    }

    // ── 4. Assemble query args in correct positional order ───────────────────
    //
    //  Positions:
    //    %d  → $family_id
    //    %d  → $category_id
    //    %d… → $param_ids  (one per placeholder)
    //    %d… → $year_args  (zero or more, only when year filter is active)

    $query_args = array_merge(
        [ $family_id, $category_id ],
        $param_ids,
        $year_args
    );

    // ── 5. Execute query ──────────────────────────────────────────────────────

    $sql = $wpdb->prepare(
    // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        "SELECT   q.id                                         AS param_id,
                  q.CategoryParam_Name                         AS param_name,
                  a.created_at,
                  CAST(p.AmountParam_Value AS DECIMAL(14, 4))  AS meter_value
           FROM   {$wpdb->prefix}AmountParam   AS p
           JOIN   {$wpdb->prefix}Amount        AS a   ON a.id   = p.Amount_ID
           JOIN   {$wpdb->prefix}CategoryParam AS q   ON q.id   = p.CategoryParam_ID
           JOIN   {$wpdb->prefix}Account       AS acc ON acc.id = a.Account_ID
          WHERE   acc.Family_ID   = %d
            AND   q.Category_ID   = %d
            AND   q.id            IN ($param_ph)
                  $year_sql
          ORDER BY q.id, a.created_at ASC",
        // phpcs:enable
        ...$query_args
    );

    $rows = $wpdb->get_results( $sql );

    if ( $wpdb->last_error ) {
        wp_send_json_error( [ 'message' => $wpdb->last_error ] );
    }

    // ── 6. PHP-side LAG: compute period-over-period differences ──────────────
    //
    //  We group rows by param_id and iterate chronologically.
    //  For each row we subtract the previous meter reading (LAG equivalent).
    //  When grouping by year, multiple monthly diffs within the same year are
    //  summed to give the annual total consumption.

    $grouped = [];
    foreach ( $rows as $row ) {
        $grouped[ (int) $row->param_id ][] = $row;
    }

    $period_set = []; // ordered set of all period labels (assoc for dedup)
    $series_map = []; // param_id → [ 'label' => string, 'data' => [ period => float ] ]

    foreach ( $grouped as $pid => $param_rows ) {
        $param_name  = $param_rows[0]->param_name;
        $prev_value  = null;
        $data_points = [];

        foreach ( $param_rows as $r ) {
            $dt     = new DateTimeImmutable( $r->created_at );
            $period = $by_month
                ? $dt->format( 'Y-m' )
                : $dt->format( 'Y' );

            $curr_value = (float) $r->meter_value;

            if ( $prev_value !== null ) {
                $diff = $curr_value - $prev_value;
                // Accumulate: multiple months may collapse into the same year period
                $data_points[ $period ] = ( $data_points[ $period ] ?? 0.0 ) + $diff;
                $period_set[ $period ]  = true;
            }

            $prev_value = $curr_value;
        }

        $series_map[ $pid ] = [
            'label' => $param_name,
            'data'  => $data_points,
        ];
    }

    // Sort periods chronologically (lexicographic works for 'YYYY' and 'YYYY-MM')
    ksort( $period_set );
    $periods = array_keys( $period_set );

    // ── 7. Build Chart.js-compatible datasets ─────────────────────────────────

    // Colour palette (background / border pairs)
    $palette = [
        [ 'rgba(59,130,246,0.75)',  'rgba(59,130,246,1)'   ],
        [ 'rgba(234,88,12,0.75)',   'rgba(234,88,12,1)'    ],
        [ 'rgba(16,185,129,0.75)',  'rgba(16,185,129,1)'   ],
        [ 'rgba(168,85,247,0.75)', 'rgba(168,85,247,1)'   ],
        [ 'rgba(245,158,11,0.75)', 'rgba(245,158,11,1)'   ],
        [ 'rgba(239,68,68,0.75)',  'rgba(239,68,68,1)'    ],
    ];

    $datasets = [];
    $ci       = 0;

    foreach ( $series_map as $series ) {
        // Align dataset values to the global sorted period list
        $values = [];
        foreach ( $periods as $p ) {
            $values[] = isset( $series['data'][ $p ] )
                ? round( $series['data'][ $p ], 2 )
                : 0.0;
        }

        $color      = $palette[ $ci % count( $palette ) ];
        $datasets[] = [
            'label'           => $series['label'],
            'data'            => $values,
            'backgroundColor' => $color[0],
            'borderColor'     => $color[1],
            'borderWidth'     => 1,
            'borderRadius'    => 4,
        ];
        $ci++;
    }

    wp_send_json_success( [
        'labels'   => $periods,
        'datasets' => $datasets,
    ] );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SHORTCODE RENDERER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Render the Communal Services module HTML.
 *
 * Enqueues Chart.js (CDN) and the module's own JS/CSS, pre-fetches the
 * category and year lists for the filter panel, then returns the full
 * two-column layout as a string.
 *
 * @since  1.0.27.0
 * @param  array $atts Shortcode attributes (currently unused).
 * @return string HTML markup.
 */
function fb_render_communal_module( array $atts = [] ): string {

    $family_id = fb_communal_get_family_id();

    if ( ! $family_id ) {
        return '<p class="fb-communal-error">'
            . esc_html__( 'Please log in to view your communal data.', 'family-budget' )
            . '</p>';
    }

    // ── Enqueue assets ────────────────────────────────────────────────────────

    $plugin_url = plugin_dir_url( __FILE__ );

    wp_enqueue_script(
        'chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
        [],
        '4.4.3',
        true
    );

    wp_enqueue_script(
        'fb-communal-js',
        $plugin_url . 'js/communal.js',
        [ 'jquery', 'chartjs' ],
        '1.0.27.3',
        true
    );

    wp_enqueue_style(
        'fb-communal-css',
        $plugin_url . 'css/communal.css',
        [],
        '1.0.27.3'
    );

    // Pass runtime config to JS (nonce, ajaxUrl, familyId)
    wp_localize_script( 'fb-communal-js', 'fbCommunal', [
        'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fb_communal_nonce' ),
        'familyId' => $family_id,
        'i18n'     => [
            'loading'  => __( 'Завантаження…',                     'family-budget' ),
            'noParams' => __( 'Параметрів не знайдено',          'family-budget' ),
            'noData'   => __( 'Немає даних для вибраних фільтрів.','family-budget' ),
            'error'    => __( 'Помилка мережі. Будь ласка, спробуйте ще раз.', 'family-budget' ),
            'byYear'   => __( 'по рокам',                      'family-budget' ),
            'byMonth'  => __( 'по місяцям',                     'family-budget' ),
        ],
    ] );

    // ── Pre-fetch filter data ─────────────────────────────────────────────────

    $categories = fb_get_categories_have_value( $family_id );
    $years      = fb_communal_get_years( $family_id );
    $cur_year   = (int) gmdate( 'Y' );

    // ── Render HTML ───────────────────────────────────────────────────────────

    ob_start();
    ?>
    <div class="fb-communal-wrapper" id="fb-communal-wrapper" role="region"
         aria-label="<?php esc_attr_e( 'Комунальні послуги', 'family-budget' ); ?>">

        <!-- ═══════════════════════════════════════════════════════════════
             LEFT: Filters panel
             ═══════════════════════════════════════════════════════════════ -->
        <aside class="fb-communal-filters" id="fb-communal-filters">

            <h3 class="fb-communal-filters__heading">
                <?php esc_html_e( 'ФІЛЬТР', 'family-budget' ); ?>
            </h3>

            <!-- ── Filter 1: Category ─────────────────────────────────── -->
            <div class="fb-communal-field">
                <label for="fb-communal-category" class="fb-communal-label">
                    <?php esc_html_e( 'категорії', 'family-budget' ); ?>
                </label>
                <select id="fb-communal-category" class="fb-communal-select"
                        aria-label="<?php esc_attr_e( 'Виберіть категорію', 'family-budget' ); ?>">
                    <?php if ( empty( $categories ) ) : ?>
                        <option value=""><?php esc_html_e( 'Дані недоступні', 'family-budget' ); ?></option>
                    <?php else : ?>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->Category_ID ); ?>">
                                <?php echo esc_html( $cat->Category_Name ); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- ── Filter 2: Years (multiselect) ─────────────────────── -->
            <div class="fb-communal-field">
                <label for="fb-communal-years" class="fb-communal-label">
                    <?php esc_html_e( 'Період / Роки', 'family-budget' ); ?>
                </label>
                <select id="fb-communal-years"
                        class="fb-communal-select fb-communal-select--multi"
                        multiple
                        aria-multiselectable="true"
                        aria-label="<?php esc_attr_e( 'вибрати рік', 'family-budget' ); ?>">
                    <option value="all">
                        <?php esc_html_e( 'усі роки', 'family-budget' ); ?>
                    </option>
                    <?php foreach ( $years as $y ) : ?>
                        <option value="<?php echo esc_attr( $y ); ?>"
                            <?php selected( (int) $y, $cur_year ); ?>>
                            <?php echo esc_html( $y ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="fb-communal-hint">
                    <?php esc_html_e( 'Утримуйте Ctrl / ⌘, щоб вибрати кілька', 'family-budget' ); ?>
                </span>
            </div>

            <!-- ── Filter 3: Parameters (AJAX multiselect) ───────────── -->
            <div class="fb-communal-field">
                <label for="fb-communal-params" class="fb-communal-label">
                    <?php esc_html_e( 'параметри', 'family-budget' ); ?>
                </label>
                <select id="fb-communal-params"
                        class="fb-communal-select fb-communal-select--multi"
                        multiple
                        aria-multiselectable="true"
                        aria-label="<?php esc_attr_e( 'Виберіть параметри', 'family-budget' ); ?>">
                    <option disabled><?php esc_html_e( 'завантаження…', 'family-budget' ); ?></option>
                </select>
                <span class="fb-communal-hint">
                    <?php esc_html_e( 'Утримуйте Ctrl / ⌘, щоб вибрати кілька', 'family-budget' ); ?>
                </span>
            </div>

            <!-- ── Filter 4: By Months toggle ────────────────────────── -->
            <div class="fb-communal-field fb-communal-field--inline">
                <label class="fb-communal-toggle" for="fb-communal-by-month">
                    <input type="checkbox" id="fb-communal-by-month"
                           class="fb-communal-toggle__input"
                           aria-label="<?php esc_attr_e( 'Групувати за місяцями', 'family-budget' ); ?>">
                    <span class="fb-communal-toggle__track" aria-hidden="true">
                        <span class="fb-communal-toggle__thumb"></span>
                    </span>
                    <span class="fb-communal-toggle__text">
                        <?php esc_html_e( 'по місяцям', 'family-budget' ); ?>
                    </span>
                </label>
            </div>

            <!-- ── Apply button ───────────────────────────────────────── -->
            <button type="button" id="fb-communal-apply" class="fb-communal-btn">
                <?php esc_html_e( 'Застосувати', 'family-budget' ); ?>
            </button>

        </aside>

        <!-- ═══════════════════════════════════════════════════════════════
             RIGHT: Chart area
             ═══════════════════════════════════════════════════════════════ -->
        <section class="fb-communal-chart-section" id="fb-communal-chart-section">

            <div class="fb-communal-chart-header">
                <h3 class="fb-communal-chart-title" id="fb-communal-chart-title">
                    <?php esc_html_e( 'Спільне використання', 'family-budget' ); ?>
                </h3>
                <span class="fb-communal-spinner" id="fb-communal-spinner"
                      role="status" aria-label="<?php esc_attr_e( 'Завантаження', 'family-budget' ); ?>"
                      style="display:none;"></span>
            </div>

            <div class="fb-communal-canvas-wrap">
                <canvas id="fb-communal-chart"
                        aria-label="<?php esc_attr_e( 'Стовпчаста діаграма комунального використання', 'family-budget' ); ?>"
                        role="img"></canvas>
            </div>

            <p class="fb-communal-message" id="fb-communal-no-data" style="display:none;"></p>

        </section>

    </div><!-- .fb-communal-wrapper -->
    <?php
    return ob_get_clean();
}