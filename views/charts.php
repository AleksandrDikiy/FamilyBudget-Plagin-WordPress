<?php
/**
 * Модуль "Графіки" плагіна Family Budget
 *
 * Структура БД:
 * - wp_Amount       ( Amount_Value, created_at, Account_ID, Category_ID, AmountType_ID )
 * - wp_AmountType   ( id, AmountType_Name: 'Витрата'|'Переказ'|'Дохід' )
 * - wp_Account      ( id, Family_ID, Account_Name, Account_Order, Account_Default )
 * - wp_Category     ( id, Family_ID, CategoryType_ID, Category_Name, Category_Order )
 * - wp_CategoryType ( id, CategoryType_Name: 'Витрати'|'Доходи' )
 * - wp_UserFamily   ( User_ID, Family_ID )
 *
 * ФІЛЬТР "Операції":
 * Фільтрація по c.CategoryType_ID (тип категорії: Витрати/Доходи).
 * Кожна категорія у БД вже прив'язана до свого типу операцій,
 * тому цей JOIN є єдиним та достатнім фільтром.
 *
 * @package    FamilyBudget
 * @subpackage Views\Charts
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================
// РОЗДІЛ 1: РЕЖИМ НАЛАГОДЖЕННЯ
// ============================================================

/**
 * Режим налагодження SQL.
 * true — SQL та параметри повертаються у AJAX-відповідь та лог.
 * Встановіть false у production.
 *
 * @since 1.0.0
 */
if ( ! defined( 'FB_CHARTS_DEBUG' ) ) {
	define( 'FB_CHARTS_DEBUG', true );
}


// ============================================================
// РОЗДІЛ 2: РЕЄСТРАЦІЯ AJAX-ОБРОБНИКІВ
// ============================================================

add_action( 'wp_ajax_fb_charts_get_data',        'fb_charts_ajax_get_data' );
add_action( 'wp_ajax_fb_charts_get_filter_data', 'fb_charts_ajax_get_filter_data' );


// ============================================================
// РОЗДІЛ 3: AJAX-ОБРОБНИКИ
// ============================================================

/**
 * AJAX-обробник: повертає агреговані дані для побудови графіка
 *
 * @since  1.0.0
 * @return void
 */
function fb_charts_ajax_get_data(): void {
	check_ajax_referer( 'fb_charts_nonce', 'security' );

	if (
		! current_user_can( 'fb_admin' ) &&
		! current_user_can( 'fb_user' )  &&
		! current_user_can( 'fb_payment' ) &&
		! current_user_can( 'manage_options' )
	) {
		wp_send_json_error( array( 'message' => 'Доступ заборонено: недостатньо прав.' ) );
	}

	$user_id          = get_current_user_id();
	$family_id        = absint( $_POST['family_id']        ?? 0 );
	$group_by         = sanitize_text_field( wp_unslash( $_POST['group_by']         ?? 'month' ) );
	$category_type_id = absint( $_POST['category_type_id'] ?? 0 );
	$period           = sanitize_text_field( wp_unslash( $_POST['period']           ?? 'last_month' ) );
	$date_begin       = sanitize_text_field( wp_unslash( $_POST['date_begin']       ?? '' ) );
	$date_end         = sanitize_text_field( wp_unslash( $_POST['date_end']         ?? '' ) );
	
	// ДОДАНО: Отримання типу рахунку (AmountType)
	$amount_type_id   = absint( $_POST['amount_type_id']   ?? 0 );

	$categories = isset( $_POST['categories'] )
		? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['categories'] ) ) ) )
		: array();
	$accounts   = isset( $_POST['accounts'] )
		? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['accounts'] ) ) ) )
		: array();

	if ( ! $family_id || ! fb_charts_user_has_family_access( $user_id, $family_id ) ) {
		wp_send_json_error( array( 'message' => 'Доступ до вказаної родини заборонено.' ) );
	}

	$group_by = in_array( $group_by, array( 'day', 'month', 'year' ), true ) ? $group_by : 'month';

	$dates = fb_charts_resolve_period_dates( $period, $date_begin, $date_end );
	if ( is_wp_error( $dates ) ) {
		wp_send_json_error( array( 'message' => $dates->get_error_message() ) );
	}

	// ЗМІНЕНО: Передаємо $amount_type_id у функцію вибірки
	$result = fb_charts_fetch_category_data(
		$family_id,
		$group_by,
		$category_type_id,
		$categories,
		$accounts,
		$dates['start'],
		$dates['end'],
		$amount_type_id
	);

	wp_send_json_success( $result );
}

/**
 * AJAX-обробник: повертає залежні дані для фільтрів
 *
 * @since  1.0.0
 * @return void
 */
function fb_charts_ajax_get_filter_data(): void {
	check_ajax_referer( 'fb_charts_nonce', 'security' );

	if (
		! current_user_can( 'fb_admin' ) &&
		! current_user_can( 'fb_user' )  &&
		! current_user_can( 'fb_payment' ) &&
		! current_user_can( 'manage_options' )
	) {
		wp_send_json_error( array( 'message' => 'Доступ заборонено.' ) );
	}

	$user_id   = get_current_user_id();
	$family_id = absint( $_POST['family_id'] ?? 0 );
	$data_type = sanitize_text_field( wp_unslash( $_POST['data_type'] ?? '' ) );

	if ( ! $family_id || ! fb_charts_user_has_family_access( $user_id, $family_id ) ) {
		wp_send_json_error( array( 'message' => 'Доступ заборонено.' ) );
	}

	switch ( $data_type ) {
		case 'categories':
			wp_send_json_success( fb_charts_get_categories( $family_id ) );
			break;
		case 'accounts':
			wp_send_json_success( fb_charts_get_accounts( $family_id ) );
			break;
		// ДОДАНО: підтримка AJAX запиту для Типів рахунку
		case 'amount_types':
			wp_send_json_success( fb_charts_get_amount_types() );
			break;
		default:
			wp_send_json_error( array( 'message' => 'Невідомий data_type: ' . esc_html( $data_type ) ) );
	}
}


// ============================================================
// РОЗДІЛ 4: БІЗНЕС-ЛОГІКА ТА ДОПОМІЖНІ ФУНКЦІЇ
// ============================================================

/**
 * Перевіряє доступ користувача до родини
 *
 * @since  1.0.0
 * @param  int  $user_id
 * @param  int  $family_id
 * @return bool
 */
function fb_charts_user_has_family_access( int $user_id, int $family_id ): bool {
	global $wpdb;

	// WordPress-адміністратор має доступ до всіх родин без запису в UserFamily
	if ( current_user_can( 'manage_options' ) ) {
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}Family WHERE id = %d LIMIT 1",
				$family_id
			)
		);
		return $exists > 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily
			 WHERE User_ID = %d AND Family_ID = %d LIMIT 1",
			$user_id,
			$family_id
		)
	) > 0;
}

/**
 * Повертає ID родини з мінімальним значенням для поточного користувача
 *
 * @since  1.0.0
 * @param  int $user_id
 * @return int
 */
function fb_charts_get_default_family( int $user_id ): int {
	global $wpdb;

	// Шукаємо родину через UserFamily (звичайний користувач)
	$family_id = absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT f.id
				 FROM {$wpdb->prefix}Family f
				 JOIN {$wpdb->prefix}UserFamily u ON u.Family_ID = f.id
				 WHERE u.User_ID = %d
				 ORDER BY f.id ASC LIMIT 1",
				$user_id
			)
		)
	);

	// Фолбек для WordPress-адміністратора: перша родина у таблиці
	if ( ! $family_id && current_user_can( 'manage_options' ) ) {
		$family_id = absint(
			$wpdb->get_var( "SELECT id FROM {$wpdb->prefix}Family ORDER BY id ASC LIMIT 1" )
		);
	}

	return $family_id;
}

/**
 * Повертає список типів операцій з wp_CategoryType
 *
 * Запит: SELECT t.id, t.CategoryType_Name FROM wp_CategoryType t
 *
 * @since  1.0.0
 * @return array Масив об'єктів {id, CategoryType_Name}
 */
function fb_charts_get_category_types(): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT t.id, t.CategoryType_Name
		 FROM {$wpdb->prefix}CategoryType t
		 ORDER BY t.id ASC"
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * ДОДАНО: Повертає список типів рахунку з wp_AmountType
 * * Запит: SELECT t.id, t.AmountType_Name FROM wp_AmountType t
 *
 * @since  1.0.0
 * @return array Масив об'єктів {id, AmountType_Name}
 */
function fb_charts_get_amount_types(): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT t.id, t.AmountType_Name
		 FROM {$wpdb->prefix}AmountType t
		 ORDER BY t.id ASC"
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Повертає список категорій для родини.
 *
 * [SCHEMA-v2] Family_ID перенесено з таблиці Category до CategoryType,
 * тому фільтрація за родиною виконується через JOIN з CategoryType.
 *
 * Запит:
 * SELECT c.id, c.Category_Name
 * FROM wp_Category c
 * INNER JOIN wp_CategoryType ct ON ct.id = c.CategoryType_ID
 * WHERE ct.Family_ID = %d
 * ORDER BY c.Category_Order ASC
 *
 * @since  1.0.0
 * @param  int   $family_id Ідентифікатор родини.
 * @return array            Масив об'єктів категорій (id, Category_Name).
 */
function fb_charts_get_categories( int $family_id ): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.id, c.Category_Name
			 FROM {$wpdb->prefix}Category AS c
			 INNER JOIN {$wpdb->prefix}CategoryType AS ct ON ct.id = c.CategoryType_ID
			 WHERE ct.Family_ID = %d
			 ORDER BY c.Category_Order ASC",
			$family_id
		)
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Повертає список рахунків для родини
 *
 * Запит:
 * SELECT a.id, a.Account_Name, a.Account_Default
 * FROM wp_Account a
 * WHERE a.Family_ID = %d
 * ORDER BY a.Account_Order, a.Account_Default DESC
 *
 * @since  1.0.0
 * @param  int   $family_id
 * @return array
 */
function fb_charts_get_accounts( int $family_id ): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT a.id, a.Account_Name, a.Account_Default
			 FROM {$wpdb->prefix}Account a
			 WHERE a.Family_ID = %d
			 ORDER BY a.Account_Order ASC, a.Account_Default DESC",
			$family_id
		)
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Обчислює межі дат за типом діапазону
 *
 * @since  1.0.0
 * @param  string $period
 * @param  string $date_begin
 * @param  string $date_end
 * @return array|WP_Error
 */
function fb_charts_resolve_period_dates( string $period, string $date_begin = '', string $date_end = '' ) {
	$now = current_time( 'timestamp' );

	switch ( $period ) {
		case 'current_month':
			return array( 'start' => gmdate( 'Y-m-01', $now ), 'end' => gmdate( 'Y-m-t', $now ) );
		case 'current_year':
			return array( 'start' => gmdate( 'Y-01-01', $now ), 'end' => gmdate( 'Y-12-31', $now ) );
		case 'last_year':
			$y = (int) gmdate( 'Y', $now ) - 1;
			return array( 'start' => "{$y}-01-01", 'end' => "{$y}-12-31" );
		case 'custom':
			if ( ! $date_begin || ! $date_end ) {
				return new WP_Error( 'fb_dates', 'Вкажіть обидві дати.' );
			}
			$ds = DateTime::createFromFormat( 'Y-m-d', $date_begin );
			$de = DateTime::createFromFormat( 'Y-m-d', $date_end );
			if ( ! $ds || ! $de || $ds > $de ) {
				return new WP_Error( 'fb_dates', 'Некоректний діапазон дат.' );
			}
			return array( 'start' => $date_begin, 'end' => $date_end );
		case 'last_month':
		default:
			$first = strtotime( 'first day of last month', $now );
			return array( 'start' => gmdate( 'Y-m-01', $first ), 'end' => gmdate( 'Y-m-t', $first ) );
	}
}

/**
 * Виконує SQL та повертає транзакції, згруповані по категоріях і часових відрізках
 *
 * Фільтр "Операції" → c.CategoryType_ID: категорія прив'язана до типу (Витрати/Доходи),
 * тому пряма умова `c.CategoryType_ID = %d` коректно розділяє потоки.
 *
 * При FB_CHARTS_DEBUG = true: повертає debug_sql, debug_db_error, debug_params.
 *
 * @since  1.0.0
 * @param  int    $family_id
 * @param  string $group_by         day|month|year
 * @param  int    $category_type_id ID з wp_CategoryType (0 = всі операції)
 * @param  int[]  $categories       Масив ID (порожній = всі)
 * @param  int[]  $accounts         Масив ID (порожній = всі)
 * @param  string $date_start       Дата Y-m-d
 * @param  string $date_end         Дата Y-m-d
 * @param  int    $amount_type_id   ID з wp_AmountType (0 = не застосовувати фільтр) // ДОДАНО
 * @return array { rows, total, debug_sql?, debug_db_error?, debug_params? }
 */
function fb_charts_fetch_category_data(
	int $family_id,
	string $group_by,
	int $category_type_id,
	array $categories,
	array $accounts,
	string $date_start,
	string $date_end,
	int $amount_type_id = 0
): array {
	global $wpdb;

	/*
	 * ВИПРАВЛЕННЯ DATE_FORMAT:
	 * $date_expr будується як whitelist-константа ПОЗА межами $wpdb->prepare().
	 * WordPress 5.3+ хешує символи '%' всередині рядка що передається в prepare(),
	 * навіть якщо вони не є плейсхолдерами — '%%Y-%%m' ставало '{hash}Y-{hash}m'.
	 * Безпека: $group_by пройшло whitelist-валідацію вище, пряма інтерполяція безпечна.
	 */
	switch ( $group_by ) {
		case 'day':
			$date_expr = "DATE_FORMAT(a.created_at, '%Y-%m-%d')";
			break;
		case 'year':
			$date_expr = "YEAR(a.created_at)";
			break;
		default: // month
			$date_expr = "DATE_FORMAT(a.created_at, '%Y-%m')";
	}

	// WHERE-умови з плейсхолдерами — тільки ця частина проходить через prepare()
	$where = array(
		'acc.Family_ID = %d',
		'DATE(a.created_at) BETWEEN %s AND %s',
	);
	$args  = array( $family_id, $date_start, $date_end );

	// Фільтр "Операції" — пряма фільтрація по CategoryType_ID.
	// Категорія у БД вже прив'язана до типу (Витрати/Доходи) через c.CategoryType_ID.
	if ( $category_type_id > 0 ) {
		$where[] = 'c.CategoryType_ID = %d';
		$args[]  = $category_type_id;
	}

	// ДОДАНО: Обов'язкове обмеження по "Тип рахунку" (AmountType_ID)
	if ( $amount_type_id > 0 ) {
		$where[] = 'a.AmountType_ID = %d';
		$args[]  = $amount_type_id;
	}

	// Фільтр по конкретних категоріях (whitelist absint)
	if ( ! empty( $categories ) ) {
		$ph      = implode( ',', array_fill( 0, count( $categories ), '%d' ) );
		$where[] = "a.Category_ID IN ({$ph})";
		$args    = array_merge( $args, $categories );
	}

	// Фільтр по конкретних рахунках (whitelist absint)
	if ( ! empty( $accounts ) ) {
		$ph      = implode( ',', array_fill( 0, count( $accounts ), '%d' ) );
		$where[] = "a.Account_ID IN ({$ph})";
		$args    = array_merge( $args, $accounts );
	}

	/*
	 * Лише WHERE проходить через prepare() — він містить змінні від користувача.
	 * SELECT і FROM будуються з whitelist-значень ($date_expr, $wpdb->prefix).
	 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	 */
	$where_prepared = $wpdb->prepare( implode( ' AND ', $where ), ...$args );

	$sql = "SELECT {$date_expr}         AS period_label,
	               a.Category_ID        AS cat_id,
	               c.Category_Name      AS cat_name,
	               SUM(a.Amount_Value)  AS total_amount
	        FROM {$wpdb->prefix}Amount a
	        JOIN {$wpdb->prefix}Account acc ON acc.id = a.Account_ID
	        JOIN {$wpdb->prefix}Category c  ON c.id   = a.Category_ID
	        WHERE {$where_prepared}
	        GROUP BY period_label, a.Category_ID, c.Category_Name
	        ORDER BY period_label ASC, c.Category_Order ASC, c.Category_Name ASC";
	// phpcs:enable

	$rows     = $wpdb->get_results( $sql );
	$db_error = $wpdb->last_error;

	$result = array();
	$total  = 0.0;

	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$amount  = round( (float) $row->total_amount, 2 );
			$total  += $amount;
			$result[] = array(
				'period'   => esc_html( $row->period_label ),
				'cat_id'   => (int) $row->cat_id,
				'cat_name' => esc_html( $row->cat_name ),
				'amount'   => $amount,
			);
		}
	}

	$response = array(
		'rows'  => $result,
		'total' => round( $total, 2 ),
	);

	if ( FB_CHARTS_DEBUG ) {
		$response['debug_sql']      = $sql;
		$response['debug_db_error'] = $db_error ?: null;
		$response['debug_params']   = array(
			'family_id'        => $family_id,
			'group_by'         => $group_by,
			'category_type_id' => $category_type_id,
			'amount_type_id'   => $amount_type_id, // ДОДАНО
			'date_start'       => $date_start,
			'date_end'         => $date_end,
			'categories'       => $categories,
			'accounts'         => $accounts,
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions
		error_log( '[FB Charts SQL] ' . $sql );
		if ( $db_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( '[FB Charts DB Error] ' . $db_error );
		}
	}

	return $response;
}


// ============================================================
// РОЗДІЛ 5: HTML-РЕНДЕРИНГ СТОРІНКИ
// ============================================================

/**
 * Рендерить HTML модуля "Графіки"
 *
 * Дати завжди видимі — disabled за замовчуванням,
 * активуються тільки при виборі "Довільний" у полі "Період".
 *
 * @since  1.0.0
 * @return void
 */
function fb_charts_render_page(): void {
	$user_id = get_current_user_id();

	$default_family_id = fb_charts_get_default_family( $user_id );
	if ( ! $default_family_id ) {
		echo '<div class="fb-cht-notice">Немає доступних родин.</div>';
		return;
	}

	// fb_get_families() може повертати порожній масив для WP-адміна без запису в UserFamily.
	// Фолбек: отримуємо всі родини напряму.
	$user_families = fb_get_families( $user_id );
	if ( empty( $user_families ) && current_user_can( 'manage_options' ) ) {
		global $wpdb;
		$user_families = $wpdb->get_results(
			"SELECT id, Family_Name FROM {$wpdb->prefix}Family ORDER BY id ASC"
		);
	}
	
	$category_types     = fb_charts_get_category_types();
	$initial_categories = fb_charts_get_categories( $default_family_id );
	$initial_accounts   = fb_charts_get_accounts( $default_family_id );
	$default_ct_id      = ! empty( $category_types ) ? absint( $category_types[0]->id ) : 0;

	// ДОДАНО: Отримуємо Типи рахунку та визначаємо дефолтний ("витрата")
	$amount_types = fb_charts_get_amount_types();
	$default_amount_type_id = 0;
	if ( ! empty( $amount_types ) ) {
		foreach ( $amount_types as $at ) {
			if ( mb_strtolower( trim( $at->AmountType_Name ) ) === 'витрата' || $default_amount_type_id === 0 ) {
				$default_amount_type_id = (int) $at->id;
				// Якщо знайшли "витрата", зупиняємо пошук
				if ( mb_strtolower( trim( $at->AmountType_Name ) ) === 'витрата' ) {
					break;
				}
			}
		}
	}

	wp_enqueue_style( 'fb-charts-css', FB_PLUGIN_URL . 'css/charts.css', array(), FB_VERSION );

	wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', array(), '4.4.4', true );

	wp_enqueue_script( 'fb-charts-js', FB_PLUGIN_URL . 'js/charts.js', array( 'jquery', 'chartjs' ), FB_VERSION, true );

	wp_localize_script(
		'fb-charts-js',
		'fbChartsConfig',
		array(
			'ajaxUrl'           => esc_url( admin_url( 'admin-ajax.php' ) ),
			'security'          => wp_create_nonce( 'fb_charts_nonce' ),
			'defaultFamilyId'   => $default_family_id,
			'defaultCtId'       => $default_ct_id,
			'categoryTypes'     => $category_types,
			'initialCategories' => $initial_categories,
			'initialAccounts'   => $initial_accounts,
			'debug'             => FB_CHARTS_DEBUG,
			'i18n'              => array(
				'loading'     => 'Завантаження...',
				'noData'      => 'Дані за вказаний період відсутні',
				'errorLoad'   => 'Помилка завантаження даних',
				'allSelected' => 'Всі',
				'nSelected'   => 'обрано',
				'total'       => 'Загалом',
			),
		)
	);
	?>
	<div class="fb-cht-module" id="fb-cht-module">

		<div class="fb-cht-filters" id="fb-cht-filters">

			<?php /* Родина */ ?>
			<div class="fb-cht-fc">
				<span class="fb-cht-lbl"><?php esc_html_e( 'Родина', 'family-budget' ); ?></span>
				<select class="fb-cht-sel" id="fb-family" name="family_id">
					<?php foreach ( (array) $user_families as $fam ) : ?>
						<option value="<?php echo esc_attr( $fam->id ); ?>"
							<?php selected( (int) $fam->id, $default_family_id ); ?>>
							<?php echo esc_html( $fam->Family_Name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php /* Операції — з wp_CategoryType */ ?>
			<div class="fb-cht-fc">
				<span class="fb-cht-lbl"><?php esc_html_e( 'Операції', 'family-budget' ); ?></span>
				<select class="fb-cht-sel" id="fb-category-type" name="category_type_id">
					<?php foreach ( $category_types as $ct ) : ?>
						<option value="<?php echo esc_attr( $ct->id ); ?>"
							<?php selected( (int) $ct->id, $default_ct_id ); ?>>
							<?php echo esc_html( $ct->CategoryType_Name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php /* ДОДАНО: Тип рахунку (перед пунктом "Рахунки") */ ?>
			<div class="fb-cht-fc">
				<span class="fb-cht-lbl"><?php esc_html_e( 'Тип рахунку', 'family-budget' ); ?></span>
				<select class="fb-cht-sel" id="fb-amount-type" name="amount_type_id">
					<?php foreach ( $amount_types as $at ) : ?>
						<option value="<?php echo esc_attr( $at->id ); ?>"
							<?php selected( (int) $at->id, $default_amount_type_id ); ?>>
							<?php echo esc_html( $at->AmountType_Name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php /* Рахунки (мультивибір) */ ?>
			<div class="fb-cht-fc">
				<span class="fb-cht-lbl"><?php esc_html_e( 'Рахунки', 'family-budget' ); ?></span>
				<div class="fb-ms" id="fb-ms-accounts" data-name="accounts">
					<button type="button" class="fb-ms__btn" aria-haspopup="listbox" aria-expanded="false">
						<span class="fb-ms__lbl"><?php esc_html_e( 'Всі', 'family-budget' ); ?></span>
						<span class="fb-ms__arr" aria-hidden="true">&#9660;</span>
					</button>
					<div class="fb-ms__drop" role="listbox" aria-multiselectable="true"></div>
				</div>
			</div>

            <?php /* Категорії (мультивибір) */ ?>
            <div class="fb-cht-fc">
                <span class="fb-cht-lbl"><?php esc_html_e( 'Категорії', 'family-budget' ); ?></span>
                <div class="fb-ms" id="fb-ms-categories" data-name="categories">
                    <button type="button" class="fb-ms__btn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="fb-ms__lbl"><?php esc_html_e( 'Всі', 'family-budget' ); ?></span>
                        <span class="fb-ms__arr" aria-hidden="true">&#9660;</span>
                    </button>
                    <div class="fb-ms__drop" role="listbox" aria-multiselectable="true"></div>
                </div>
            </div>

            <?php /* Групування */ ?>
            <div class="fb-cht-fc">
                <span class="fb-cht-lbl"><?php esc_html_e( 'Групування', 'family-budget' ); ?></span>
                <select class="fb-cht-sel" id="fb-group-by" name="group_by">
                    <option value="day"><?php esc_html_e( 'По днях', 'family-budget' ); ?></option>
                    <option value="month" selected="selected"><?php esc_html_e( 'По місяцях', 'family-budget' ); ?></option>
                    <option value="year"><?php esc_html_e( 'По роках', 'family-budget' ); ?></option>
                </select>
            </div>

            <?php /* Період */ ?>
			<div class="fb-cht-fc">
				<span class="fb-cht-lbl"><?php esc_html_e( 'Період', 'family-budget' ); ?></span>
				<select class="fb-cht-sel" id="fb-period" name="period">
					<option value="current_month"><?php esc_html_e( 'Поточний місяць', 'family-budget' ); ?></option>
					<option value="last_month" selected="selected"><?php esc_html_e( 'Минулий місяць', 'family-budget' ); ?></option>
					<option value="current_year"><?php esc_html_e( 'Поточний рік', 'family-budget' ); ?></option>
					<option value="last_year"><?php esc_html_e( 'Минулий рік', 'family-budget' ); ?></option>
					<option value="custom"><?php esc_html_e( 'Довільний', 'family-budget' ); ?></option>
				</select>
			</div>

			<?php
			/*
			 * Поля дат — завжди видимі.
			 * За замовчуванням disabled (не є "Довільний").
			 * JS активує їх при виборі "Довільний" у полі "Період".
			 */
			?>
			<div class="fb-cht-fc fb-cht-fc--dates">
				<span class="fb-cht-lbl"><?php esc_html_e( 'Від — До', 'family-budget' ); ?></span>
				<div class="fb-dates-row">
					<input class="fb-cht-date" type="date"
						id="fb-date-begin" name="date_begin"
						value="<?php echo esc_attr( gmdate( 'Y-m-01' ) ); ?>"
						disabled>
					<span class="fb-dates-sep">–</span>
					<input class="fb-cht-date" type="date"
						id="fb-date-end" name="date_end"
						value="<?php echo esc_attr( gmdate( 'Y-m-t' ) ); ?>"
						disabled>
				</div>
			</div>

			<?php /* Кнопка "Оновити" */ ?>
			<button type="button" class="fb-cht-apply" id="fb-apply-btn">
				<?php esc_html_e( 'Оновити', 'family-budget' ); ?>
			</button>

		</div><?php /* .fb-cht-filters */ ?>

		<?php /* Область графіка */ ?>
		<div class="fb-cht-area" id="fb-cht-area">
			<canvas id="fb-budget-chart" role="img"
				aria-label="<?php esc_attr_e( 'Графік бюджету', 'family-budget' ); ?>">
			</canvas>

			<div class="fb-cht-footer" id="fb-chart-footer" aria-live="polite"></div>

			<div class="fb-cht-overlay" id="fb-chart-overlay" aria-live="polite">
				<span class="fb-cht-spinner" id="fb-chart-spinner" aria-hidden="true"></span>
				<span class="fb-cht-msg" id="fb-chart-msg"></span>
			</div>
		</div>

		<?php /* ЗМІНЕНО: Блок SQL-налагодження (перенесено нижче графіка, додано колір та перевірку ролей) */ ?>
		<?php if ( FB_CHARTS_DEBUG && ( current_user_can( 'administrator' ) || current_user_can( 'fb_admin' ) ) ) : ?>
		<div class="fb-cht-debug" id="fb-cht-debug" style="display:none; color: #000;">
			<strong>SQL:</strong><pre id="fb-debug-sql" style="color: #000;"></pre>
			<strong>DB Error:</strong><pre id="fb-debug-error" style="color: #000;"></pre>
			<strong>Params:</strong><pre id="fb-debug-params" style="color: #000;"></pre>
		</div>
		<?php endif; ?>

	</div><?php /* .fb-cht-module */ ?>
	<?php
}