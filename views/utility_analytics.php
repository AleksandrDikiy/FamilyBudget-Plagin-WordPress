<?php
/**
 * Модуль аналітики комунальних платежів.
 *
 * @package FamilyBudget
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_fb_utility_analytics_get_chart_data', 'fb_utility_analytics_ajax_get_chart_data' );
add_action( 'wp_ajax_fb_utility_analytics_get_houses', 'fb_utility_analytics_ajax_get_houses' );

/**
 * Повертає ознаку наявності дозволеної ролі для роботи з модулем.
 *
 * @return bool
 */
function fb_utility_analytics_user_can_access(): bool {
	return current_user_can( 'fb_admin' )
		|| current_user_can( 'fb_user' )
		|| current_user_can( 'fb_payment' )
		|| current_user_can( 'manage_options' );
}

/**
 * Виконує двошарову перевірку безпеки для AJAX-запитів модуля.
 *
 * @param string $action Назва nonce-дії.
 * @return void
 */
function fb_utility_analytics_verify_request( string $action = 'fb_utility_analytics_nonce' ): void {
	fb_accounts_verify_request( $action );

	if ( ! fb_utility_analytics_user_can_access() ) {
		wp_send_json_error(
			array( 'message' => __( 'Доступ заборонено.', 'family-budget' ) ),
			403
		);
	}
}

/**
 * Повертає список родин, доступних поточному користувачу.
 *
 * @param int $user_id Ідентифікатор користувача.
 * @return array
 */
function fb_utility_analytics_get_families( int $user_id ): array {
	global $wpdb;

	if ( current_user_can( 'manage_options' ) ) {
		$rows = $wpdb->get_results(
			"SELECT id, Family_Name
			 FROM {$wpdb->prefix}Family
			 ORDER BY Family_Name ASC"
		);

		return is_array( $rows ) ? $rows : array();
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT f.id, f.Family_Name
			 FROM {$wpdb->prefix}Family AS f
			 INNER JOIN {$wpdb->prefix}UserFamily AS uf ON uf.Family_ID = f.id
			 WHERE uf.User_ID = %d
			 ORDER BY f.Family_Name ASC",
			$user_id
		)
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Перевіряє доступ користувача до вказаної родини.
 *
 * @param int $user_id   Ідентифікатор користувача.
 * @param int $family_id Ідентифікатор родини.
 * @return bool
 */
function fb_utility_analytics_user_has_family_access( int $user_id, int $family_id ): bool {
	global $wpdb;

	if ( $family_id <= 0 ) {
		return false;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}Family
				 WHERE id = %d",
				$family_id
			)
		);
	}

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}UserFamily
			 WHERE User_ID = %d AND Family_ID = %d",
			$user_id,
			$family_id
		)
	);
}

/**
 * Повертає список осель, доступних поточному користувачу.
 *
 * @param int $user_id   Ідентифікатор користувача.
 * @param int $family_id Ідентифікатор родини або 0 для всіх.
 * @return array
 */
function fb_utility_analytics_get_houses( int $user_id, int $family_id = 0 ): array {
	global $wpdb;

	$family_id = absint( $family_id );

	if ( current_user_can( 'manage_options' ) ) {
		$sql  = "SELECT DISTINCT h.id,
		                CONCAT(h.houses_city, ', ', h.houses_street, ' ', h.houses_number) AS house_name
		         FROM {$wpdb->prefix}houses AS h";
		$args = array();

		if ( $family_id > 0 ) {
			$sql .= " INNER JOIN {$wpdb->prefix}house_family AS hf ON hf.id_houses = h.id
			          WHERE hf.id_family = %d";
			$args[] = $family_id;
		}

		$sql .= ' ORDER BY house_name ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = empty( $args ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		return is_array( $rows ) ? $rows : array();
	}

	$sql = "SELECT DISTINCT h.id,
	               CONCAT(h.houses_city, ', ', h.houses_street, ' ', h.houses_number) AS house_name
	        FROM {$wpdb->prefix}houses AS h
	        INNER JOIN {$wpdb->prefix}house_family AS hf ON hf.id_houses = h.id
	        INNER JOIN {$wpdb->prefix}UserFamily AS uf ON uf.Family_ID = hf.id_family
	        WHERE uf.User_ID = %d";

	$args = array( $user_id );

	if ( $family_id > 0 ) {
		$sql   .= ' AND hf.id_family = %d';
		$args[] = $family_id;
	}

	$sql .= ' ORDER BY house_name ASC';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Повертає типи особових рахунків для фільтра.
 *
 * @return array
 */
function fb_utility_analytics_get_account_types(): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT id, personal_accounts_type_name
		 FROM {$wpdb->prefix}personal_accounts_type
		 ORDER BY personal_accounts_type_name ASC"
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Нормалізує режим групування.
 *
 * @param string $group_by Вхідне значення.
 * @return string
 */
function fb_utility_analytics_normalize_group_by( string $group_by ): string {
	$allowed = array( 'month', 'quarter', 'year' );

	return in_array( $group_by, $allowed, true ) ? $group_by : 'month';
}

/**
 * Нормалізує значення періоду.
 *
 * @param string $period Вхідне значення.
 * @return string
 */
function fb_utility_analytics_normalize_period( string $period ): string {
	$allowed = array(
		'last_month',
		'current_month',
		'last_quarter',
		'current_quarter',
		'last_year',
		'current_year',
		'custom',
	);

	return in_array( $period, $allowed, true ) ? $period : 'last_month';
}

/**
 * Обчислює межі дат для вибраного періоду.
 *
 * @param string $period     Значення фільтра періоду.
 * @param string $date_from  Дата початку у форматі Y-m-d.
 * @param string $date_to    Дата завершення у форматі Y-m-d.
 * @return array|WP_Error
 */
function fb_utility_analytics_resolve_period_dates( string $period, string $date_from = '', string $date_to = '' ) {
	$timestamp = current_time( 'timestamp' );
	$year      = (int) gmdate( 'Y', $timestamp );
	$month     = (int) gmdate( 'n', $timestamp );

	switch ( $period ) {
		case 'current_month':
			return array(
				'start' => gmdate( 'Y-m-01', $timestamp ),
				'end'   => gmdate( 'Y-m-t', $timestamp ),
			);

		case 'last_quarter':
		case 'current_quarter':
			$current_quarter = (int) ceil( $month / 3 );
			$target_quarter  = 'last_quarter' === $period ? $current_quarter - 1 : $current_quarter;
			$target_year     = $year;

			if ( $target_quarter < 1 ) {
				$target_quarter = 4;
				--$target_year;
			}

			$quarter_start_month = ( ( $target_quarter - 1 ) * 3 ) + 1;
			$quarter_start       = gmmktime( 0, 0, 0, $quarter_start_month, 1, $target_year );
			$quarter_end         = gmmktime( 0, 0, 0, $quarter_start_month + 2, 1, $target_year );

			return array(
				'start' => gmdate( 'Y-m-01', $quarter_start ),
				'end'   => gmdate( 'Y-m-t', $quarter_end ),
			);

		case 'current_year':
			return array(
				'start' => gmdate( 'Y-01-01', $timestamp ),
				'end'   => gmdate( 'Y-12-31', $timestamp ),
			);

		case 'last_year':
			--$year;
			return array(
				'start' => sprintf( '%d-01-01', $year ),
				'end'   => sprintf( '%d-12-31', $year ),
			);

		case 'custom':
			if ( '' === $date_from || '' === $date_to ) {
				return new WP_Error( 'fb_utility_analytics_dates', __( 'Вкажіть обидві дати.', 'family-budget' ) );
			}

			$start = DateTime::createFromFormat( 'Y-m-d', $date_from );
			$end   = DateTime::createFromFormat( 'Y-m-d', $date_to );

			if ( ! $start || ! $end || $start > $end ) {
				return new WP_Error( 'fb_utility_analytics_dates', __( 'Некоректний діапазон дат.', 'family-budget' ) );
			}

			return array(
				'start' => $date_from,
				'end'   => $date_to,
			);

		case 'last_month':
		default:
			$last_month = strtotime( 'first day of last month', $timestamp );
			return array(
				'start' => gmdate( 'Y-m-01', $last_month ),
				'end'   => gmdate( 'Y-m-t', $last_month ),
			);
	}
}

/**
 * Повертає SQL-вирази для групування періодів.
 *
 * @param string $group_by Режим групування.
 * @return array
 */
function fb_utility_analytics_get_group_sql( string $group_by ): array {
	$period_date_sql = "DATE_ADD(MAKEDATE(s.indicators_year, 1), INTERVAL (s.indicators_month - 1) MONTH)";
	$quarter_sql     = 'CEILING(s.indicators_month / 3)';

	switch ( $group_by ) {
		case 'quarter':
			return array(
				'label' => "CONCAT(s.indicators_year, '-Q', {$quarter_sql})",
				'order' => "MIN(DATE_ADD(MAKEDATE(s.indicators_year, 1), INTERVAL (({$quarter_sql} - 1) * 3) MONTH))",
			);

		case 'year':
			return array(
				'label' => 'CAST(s.indicators_year AS CHAR)',
				'order' => "MIN(MAKEDATE(s.indicators_year, 1))",
			);

		case 'month':
		default:
			return array(
				'label' => "CONCAT(s.indicators_year, '-', LPAD(s.indicators_month, 2, '0'))",
				'order' => "MIN({$period_date_sql})",
			);
	}
}

/**
 * Формує масив рядків для відображення графіка.
 *
 * @param int    $user_id         Ідентифікатор користувача.
 * @param int    $family_id       Ідентифікатор родини або 0.
 * @param int    $house_id        Ідентифікатор оселі або 0.
 * @param int    $account_type_id Ідентифікатор типу рахунку або 0.
 * @param string $group_by        Режим групування.
 * @param string $date_from       Дата початку.
 * @param string $date_to         Дата завершення.
 * @return array
 */
function fb_utility_analytics_get_chart_rows(
	int $user_id,
	int $family_id,
	int $house_id,
	int $account_type_id,
	string $group_by,
	string $date_from,
	string $date_to
): array {
	global $wpdb;

	$group_sql = fb_utility_analytics_get_group_sql( $group_by );
	$period_date_sql = "DATE_ADD(MAKEDATE(s.indicators_year, 1), INTERVAL (s.indicators_month - 1) MONTH)";

	$where = array(
		"EXISTS (
			SELECT 1
			FROM {$wpdb->prefix}house_family AS hf
			INNER JOIN {$wpdb->prefix}UserFamily AS uf ON uf.Family_ID = hf.id_family
			WHERE hf.id_houses = h.id
			  AND uf.User_ID = %d
		)",
		"{$period_date_sql} BETWEEN %s AND %s",
	);

	$args = array( $user_id, $date_from, $date_to );

	if ( $family_id > 0 ) {
		$where[] = "EXISTS (
			SELECT 1
			FROM {$wpdb->prefix}house_family AS hf_family
			WHERE hf_family.id_houses = h.id
			  AND hf_family.id_family = %d
		)";
		$args[]  = $family_id;
	}

	if ( $house_id > 0 ) {
		$where[] = 'h.id = %d';
		$args[]  = $house_id;
	}

	if ( $account_type_id > 0 ) {
		$where[] = 't.id = %d';
		$args[]  = $account_type_id;
	}

	$sql = "SELECT {$group_sql['label']} AS period_label,
	               {$group_sql['order']} AS period_sort,
	               h.id AS house_id,
	               CONCAT(h.houses_city, ', ', h.houses_street, ' ', h.houses_number) AS house_name,
	               t.id AS account_type_id,
	               t.personal_accounts_type_name AS account_type_name,
	               ROUND(SUM(s.indicators_consumed), 3) AS total_consumed
	        FROM {$wpdb->prefix}indicators AS s
	        INNER JOIN {$wpdb->prefix}personal_accounts AS p ON p.id = s.id_personal_accounts
	        INNER JOIN {$wpdb->prefix}personal_accounts_type AS t ON t.id = p.id_personal_accounts_type
	        INNER JOIN {$wpdb->prefix}houses AS h ON h.id = p.id_houses
	        WHERE " . implode( ' AND ', $where ) . "
	        GROUP BY period_label, house_id, house_name, account_type_id, account_type_name
	        ORDER BY period_sort ASC, house_name ASC, account_type_name ASC";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $sql, $args );
	$rows     = $wpdb->get_results( $prepared );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Повертає доступний діапазон дат для поточних фільтрів без обмеження періодом.
 *
 * @param int $user_id         Ідентифікатор користувача.
 * @param int $family_id       Ідентифікатор родини або 0.
 * @param int $house_id        Ідентифікатор оселі або 0.
 * @param int $account_type_id Ідентифікатор типу рахунку або 0.
 * @return array|null
 */
function fb_utility_analytics_get_available_range(
	int $user_id,
	int $family_id,
	int $house_id,
	int $account_type_id
): ?array {
	global $wpdb;
	$period_date_sql = "DATE_ADD(MAKEDATE(s.indicators_year, 1), INTERVAL (s.indicators_month - 1) MONTH)";

	$where = array(
		"EXISTS (
			SELECT 1
			FROM {$wpdb->prefix}house_family AS hf
			INNER JOIN {$wpdb->prefix}UserFamily AS uf ON uf.Family_ID = hf.id_family
			WHERE hf.id_houses = h.id
			  AND uf.User_ID = %d
		)",
	);

	$args = array( $user_id );

	if ( $family_id > 0 ) {
		$where[] = "EXISTS (
			SELECT 1
			FROM {$wpdb->prefix}house_family AS hf_family
			WHERE hf_family.id_houses = h.id
			  AND hf_family.id_family = %d
		)";
		$args[]  = $family_id;
	}

	if ( $house_id > 0 ) {
		$where[] = 'h.id = %d';
		$args[]  = $house_id;
	}

	if ( $account_type_id > 0 ) {
		$where[] = 't.id = %d';
		$args[]  = $account_type_id;
	}

	$sql = "SELECT
				MIN({$period_date_sql}) AS min_date,
				MAX({$period_date_sql}) AS max_date
	        FROM {$wpdb->prefix}indicators AS s
	        INNER JOIN {$wpdb->prefix}personal_accounts AS p ON p.id = s.id_personal_accounts
	        INNER JOIN {$wpdb->prefix}personal_accounts_type AS t ON t.id = p.id_personal_accounts_type
	        INNER JOIN {$wpdb->prefix}houses AS h ON h.id = p.id_houses
	        WHERE " . implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );

	if ( ! $row || empty( $row->min_date ) || empty( $row->max_date ) ) {
		return null;
	}

	return array(
		'min' => (string) $row->min_date,
		'max' => (string) $row->max_date,
	);
}

/**
 * Форматує відповідь для JavaScript-графіка.
 *
 * @param array $rows Результати SQL-запиту.
 * @return array
 */
function fb_utility_analytics_prepare_chart_payload( array $rows ): array {
	$labels         = array();
	$series_map     = array();
	$total_consumed = 0.0;

	foreach ( $rows as $row ) {
		$period_label      = isset( $row->period_label ) ? (string) $row->period_label : '';
		$house_name        = isset( $row->house_name ) ? (string) $row->house_name : '';
		$account_type_name = isset( $row->account_type_name ) ? (string) $row->account_type_name : '';
		$total_value       = isset( $row->total_consumed ) ? (float) $row->total_consumed : 0.0;
		$series_key        = md5( $house_name . '|' . $account_type_name );
		$series_label      = trim( $house_name . ' / ' . $account_type_name, ' /' );

		if ( '' !== $period_label && ! in_array( $period_label, $labels, true ) ) {
			$labels[] = $period_label;
		}

		if ( ! isset( $series_map[ $series_key ] ) ) {
			$series_map[ $series_key ] = array(
				'label'             => $series_label,
				'house_name'        => $house_name,
				'account_type_name' => $account_type_name,
				'points'            => array(),
			);
		}

		$series_map[ $series_key ]['points'][ $period_label ] = $total_value;
		$total_consumed += $total_value;
	}

	$datasets = array();

	foreach ( $series_map as $series ) {
		$dataset_values = array();

		foreach ( $labels as $label ) {
			$dataset_values[] = isset( $series['points'][ $label ] ) ? (float) $series['points'][ $label ] : 0.0;
		}

		$datasets[] = array(
			'label'             => $series['label'],
			'house_name'        => $series['house_name'],
			'account_type_name' => $series['account_type_name'],
			'data'              => $dataset_values,
		);
	}

	return array(
		'labels'          => $labels,
		'datasets'        => $datasets,
		'total_consumed'  => round( $total_consumed, 3 ),
		'periods_count'   => count( $labels ),
		'series_count'    => count( $datasets ),
		'available_range' => null,
	);
}

/**
 * Повертає базову конфігурацію періоду для форми.
 *
 * @return array
 */
function fb_utility_analytics_get_default_period_config(): array {
	$dates = fb_utility_analytics_resolve_period_dates( 'last_month' );

	if ( is_wp_error( $dates ) ) {
		return array(
			'period'    => 'last_month',
			'date_from' => '',
			'date_to'   => '',
		);
	}

	return array(
		'period'    => 'last_month',
		'date_from' => $dates['start'],
		'date_to'   => $dates['end'],
	);
}

/**
 * AJAX-обробник повернення списку осель для поточної родини.
 *
 * @return void
 */
function fb_utility_analytics_ajax_get_houses(): void {
	fb_utility_analytics_verify_request();

	$user_id   = get_current_user_id();
	$family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;

	if ( $family_id > 0 && ! fb_utility_analytics_user_has_family_access( $user_id, $family_id ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Доступ до вибраної родини заборонено.', 'family-budget' ) ),
			403
		);
	}

	wp_send_json_success(
		array(
			'houses' => fb_utility_analytics_get_houses( $user_id, $family_id ),
		)
	);
}

/**
 * AJAX-обробник повернення агрегованих даних для графіка.
 *
 * @return void
 */
function fb_utility_analytics_ajax_get_chart_data(): void {
	fb_utility_analytics_verify_request();

	$user_id         = get_current_user_id();
	$family_id       = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
	$house_id        = isset( $_POST['house_id'] ) ? absint( wp_unslash( $_POST['house_id'] ) ) : 0;
	$account_type_id = isset( $_POST['account_type_id'] ) ? absint( wp_unslash( $_POST['account_type_id'] ) ) : 0;
	$group_by        = isset( $_POST['group_by'] ) ? sanitize_text_field( wp_unslash( $_POST['group_by'] ) ) : 'month';
	$period          = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'last_month';
	$date_from       = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
	$date_to         = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';

	if ( $family_id > 0 && ! fb_utility_analytics_user_has_family_access( $user_id, $family_id ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Доступ до вибраної родини заборонено.', 'family-budget' ) ),
			403
		);
	}

	if ( $house_id > 0 ) {
		$allowed_houses = wp_list_pluck( fb_utility_analytics_get_houses( $user_id, $family_id ), 'id' );
		if ( ! in_array( $house_id, array_map( 'absint', $allowed_houses ), true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Доступ до вибраної оселі заборонено.', 'family-budget' ) ),
				403
			);
		}
	}

	$group_by = fb_utility_analytics_normalize_group_by( $group_by );
	$period   = fb_utility_analytics_normalize_period( $period );
	$dates    = fb_utility_analytics_resolve_period_dates( $period, $date_from, $date_to );

	if ( is_wp_error( $dates ) ) {
		wp_send_json_error(
			array( 'message' => $dates->get_error_message() ),
			400
		);
	}

	$rows = fb_utility_analytics_get_chart_rows(
		$user_id,
		$family_id,
		$house_id,
		$account_type_id,
		$group_by,
		$dates['start'],
		$dates['end']
	);
	$payload                    = fb_utility_analytics_prepare_chart_payload( $rows );
	$payload['requested_range'] = array(
		'start' => $dates['start'],
		'end'   => $dates['end'],
	);

	if ( empty( $payload['labels'] ) ) {
		$payload['available_range'] = fb_utility_analytics_get_available_range(
			$user_id,
			$family_id,
			$house_id,
			$account_type_id
		);
	}

	wp_send_json_success( $payload );
}

/**
 * Друкує JavaScript-конфігурацію та підключає footer-скрипт модуля.
 *
 * @return void
 */
function fb_utility_analytics_print_footer_assets(): void {
	static $printed = false;

	if ( $printed || empty( $GLOBALS['fb_utility_analytics_instances'] ) ) {
		return;
	}

	$printed = true;
	$js_version = file_exists( FB_PLUGIN_DIR . 'js/utility_analytics.js' ) ? (string) filemtime( FB_PLUGIN_DIR . 'js/utility_analytics.js' ) : FB_VERSION;
	?>
	<script>
		window.fbUtilityAnalyticsInstances = <?php echo wp_json_encode( $GLOBALS['fb_utility_analytics_instances'] ); ?>;
	</script>
	<script src="<?php echo esc_url( FB_PLUGIN_URL . 'js/utility_analytics.js?ver=' . rawurlencode( $js_version ) ); ?>"></script>
	<?php
}

add_action( 'wp_footer', 'fb_utility_analytics_print_footer_assets', 30 );

/**
 * Шорткод модуля аналітики комунальних платежів.
 *
 * @return string
 */
function fb_shortcode_utility_analytics_interface(): string {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
	}

	if ( ! fb_utility_analytics_user_can_access() ) {
		return '<p>' . esc_html__( 'У вас немає доступу до цього модуля.', 'family-budget' ) . '</p>';
	}

	$user_id       = get_current_user_id();
	$families      = fb_utility_analytics_get_families( $user_id );
	$houses        = fb_utility_analytics_get_houses( $user_id, 0 );
	$account_types = fb_utility_analytics_get_account_types();
	$default_dates = fb_utility_analytics_get_default_period_config();
	$instance_id   = 'fb-ua-module-' . wp_generate_uuid4();
	$canvas_id     = 'fb-ua-chart-' . wp_generate_uuid4();
	$css_version   = file_exists( FB_PLUGIN_DIR . 'css/utility_analytics.css' ) ? (string) filemtime( FB_PLUGIN_DIR . 'css/utility_analytics.css' ) : FB_VERSION;

	wp_enqueue_style(
		'fb-utility-analytics-css',
		FB_PLUGIN_URL . 'css/utility_analytics.css',
		array(),
		$css_version
	);

	wp_enqueue_script(
		'chartjs',
		'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
		array(),
		'4.4.4',
		true
	);

	$GLOBALS['fb_utility_analytics_instances'][ $instance_id ] = array(
		'rootId'   => $instance_id,
		'canvasId' => $canvas_id,
		'ajaxUrl'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
		'security' => wp_create_nonce( 'fb_utility_analytics_nonce' ),
		'i18n'     => array(
			'loading'     => __( 'Завантаження...', 'family-budget' ),
			'noData'      => __( 'Дані за вибраними фільтрами відсутні.', 'family-budget' ),
			'errorLoad'   => __( 'Помилка завантаження даних.', 'family-budget' ),
			'availableRange' => __( 'Доступні дані є за період', 'family-budget' ),
			'requestedRange' => __( 'У вибраному діапазоні даних немає', 'family-budget' ),
			'total'       => __( 'Загальне споживання', 'family-budget' ),
			'periods'     => __( 'Кількість періодів', 'family-budget' ),
			'series'      => __( 'Кількість серій', 'family-budget' ),
			'consumed'    => __( 'Спожито', 'family-budget' ),
			'house'       => __( 'Оселя', 'family-budget' ),
			'accountType' => __( 'Тип рахунку', 'family-budget' ),
			'update'      => __( 'Оновити', 'family-budget' ),
			'allFamilies' => __( 'Всі родини', 'family-budget' ),
			'allHouses'   => __( 'Всі оселі', 'family-budget' ),
		),
	);

	ob_start();
	?>
	<div class="fb-ua-module" id="<?php echo esc_attr( $instance_id ); ?>">
		<div class="fb-ua-filters">
			<div class="fb-ua-fc">
				<span class="fb-ua-lbl"><?php esc_html_e( 'Родини', 'family-budget' ); ?></span>
				<select class="fb-ua-sel" data-role="family">
					<option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
					<?php foreach ( $families as $family ) : ?>
						<option value="<?php echo esc_attr( $family->id ); ?>"><?php echo esc_html( $family->Family_Name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="fb-ua-fc">
				<span class="fb-ua-lbl"><?php esc_html_e( 'Оселі', 'family-budget' ); ?></span>
				<select class="fb-ua-sel" data-role="house">
					<option value="0"><?php esc_html_e( 'Всі оселі', 'family-budget' ); ?></option>
					<?php foreach ( $houses as $house ) : ?>
						<option value="<?php echo esc_attr( $house->id ); ?>"><?php echo esc_html( $house->house_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="fb-ua-fc">
				<span class="fb-ua-lbl"><?php esc_html_e( 'Тип рахунку', 'family-budget' ); ?></span>
				<select class="fb-ua-sel" data-role="account-type">
					<option value="0"><?php esc_html_e( 'Всі типи', 'family-budget' ); ?></option>
					<?php foreach ( $account_types as $account_type ) : ?>
						<option value="<?php echo esc_attr( $account_type->id ); ?>">
							<?php echo esc_html( $account_type->personal_accounts_type_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="fb-ua-fc">
				<span class="fb-ua-lbl"><?php esc_html_e( 'Групування', 'family-budget' ); ?></span>
				<select class="fb-ua-sel" data-role="group-by">
					<option value="month" selected="selected"><?php esc_html_e( 'За місяцями', 'family-budget' ); ?></option>
					<option value="quarter"><?php esc_html_e( 'За кварталами', 'family-budget' ); ?></option>
					<option value="year"><?php esc_html_e( 'За роками', 'family-budget' ); ?></option>
				</select>
			</div>

			<div class="fb-ua-fc">
				<span class="fb-ua-lbl"><?php esc_html_e( 'Період', 'family-budget' ); ?></span>
				<select class="fb-ua-sel" data-role="period">
					<option value="last_month" selected="selected"><?php esc_html_e( 'Минулий місяць', 'family-budget' ); ?></option>
					<option value="current_month"><?php esc_html_e( 'Поточний місяць', 'family-budget' ); ?></option>
					<option value="last_quarter"><?php esc_html_e( 'Минулий квартал', 'family-budget' ); ?></option>
					<option value="current_quarter"><?php esc_html_e( 'Поточний квартал', 'family-budget' ); ?></option>
					<option value="last_year"><?php esc_html_e( 'Минулий рік', 'family-budget' ); ?></option>
					<option value="current_year"><?php esc_html_e( 'Поточний рік', 'family-budget' ); ?></option>
					<option value="custom"><?php esc_html_e( 'Довільний період', 'family-budget' ); ?></option>
				</select>
			</div>

			<div class="fb-ua-fc fb-ua-fc--dates">
				<span class="fb-ua-lbl"><?php esc_html_e( 'З / По', 'family-budget' ); ?></span>
				<div class="fb-ua-dates-row">
					<input
						class="fb-ua-date"
						type="date"
						data-role="date-from"
						value="<?php echo esc_attr( $default_dates['date_from'] ); ?>"
						disabled
					>
					<span class="fb-ua-dates-sep">-</span>
					<input
						class="fb-ua-date"
						type="date"
						data-role="date-to"
						value="<?php echo esc_attr( $default_dates['date_to'] ); ?>"
						disabled
					>
				</div>
			</div>

			<button type="button" class="fb-ua-apply" data-role="refresh">
				<?php esc_html_e( 'Оновити', 'family-budget' ); ?>
			</button>
		</div>

		<div class="fb-ua-area">
			<div class="fb-ua-status is-hidden" data-role="status">
				<span class="fb-ua-spinner" data-role="status-spinner" aria-hidden="true"></span>
				<span class="fb-ua-msg" data-role="status-message"></span>
			</div>

			<div class="fb-ua-chart-wrap" data-role="chart-wrap">
				<canvas
					id="<?php echo esc_attr( $canvas_id ); ?>"
					class="fb-ua-chart"
					role="img"
					aria-label="<?php esc_attr_e( 'Графік споживання комунальних платежів', 'family-budget' ); ?>"
				></canvas>
			</div>

			<div class="fb-ua-summary" data-role="summary"></div>
		</div>

	</div>
	<?php

	return (string) ob_get_clean();
}
