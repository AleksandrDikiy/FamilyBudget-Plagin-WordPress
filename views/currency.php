<?php
/**
 * Модуль валют родини.
 * update: 2026-03-27
 * @package FamilyBudget
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'fb_currency', 'fb_shortcode_currency_interface' );

add_action( 'wp_ajax_fb_load_currencies', 'fb_ajax_load_currencies' );
add_action( 'wp_ajax_fb_add_currency', 'fb_ajax_add_currency' );
add_action( 'wp_ajax_fb_set_primary_currency', 'fb_ajax_set_primary_currency' );
add_action( 'wp_ajax_fb_delete_currency', 'fb_ajax_delete_currency' );
add_action( 'wp_ajax_fb_reorder_currencies', 'fb_ajax_reorder_currencies' );

/**
 * Двошарова перевірка безпеки для AJAX-запитів модуля валют.
 *
 * @param string $action Назва nonce-дії.
 * @return void
 */
function fb_currency_verify_request( string $action = 'fb_currency_nonce' ): void {
	fb_verify_ajax_request( $action );
}

/**
 * Повертає список записів CurrencyFamily, доступних поточному користувачу.
 *
 * @param int $family_id Ідентифікатор родини або 0 для всіх.
 * @return array
 */
function fb_currency_get_rows( int $family_id = 0 ): array {
	global $wpdb;

	$user_id   = get_current_user_id();
	$family_id = absint( $family_id );

	if ( ! $user_id ) {
		return array();
	}

	$sql = "SELECT
				cf.id,
				cf.Family_ID,
				cf.Currency_ID,
				cf.CurrencyFamily_Primary,
				cf.CurrencyFamily_Order,
				c.Currency_Name,
				c.Currency_Symbol,
				c.Currency_Code,
				f.Family_Name
			FROM {$wpdb->prefix}CurrencyFamily AS cf
			INNER JOIN {$wpdb->prefix}Currency AS c ON c.id = cf.Currency_ID
			INNER JOIN {$wpdb->prefix}Family AS f ON f.id = cf.Family_ID
			WHERE cf.Family_ID IN (
				SELECT Family_ID
				FROM {$wpdb->prefix}UserFamily
				WHERE User_ID = %d
			)";

	$args = array( $user_id );

	if ( $family_id > 0 ) {
		$sql   .= ' AND cf.Family_ID = %d';
		$args[] = $family_id;
	}

	$sql .= ' ORDER BY cf.Family_ID ASC, cf.CurrencyFamily_Primary DESC, cf.CurrencyFamily_Order ASC, c.Currency_Name ASC';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

	return is_array( $rows ) ? $rows : array();
}

/**
 * Повертає запис CurrencyFamily із перевіркою доступу поточного користувача.
 *
 * @param int $cf_id   Ідентифікатор запису CurrencyFamily.
 * @param int $user_id Ідентифікатор користувача.
 * @return object|null
 */
function fb_currency_get_accessible_cf( int $cf_id, int $user_id ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT cf.*
			 FROM {$wpdb->prefix}CurrencyFamily AS cf
			 INNER JOIN {$wpdb->prefix}UserFamily AS uf ON uf.Family_ID = cf.Family_ID
			 WHERE cf.id = %d AND uf.User_ID = %d
			 LIMIT 1",
			$cf_id,
			$user_id
		)
	);
}

/**
 * Перевіряє доступ користувача до вказаної родини.
 *
 * @param int $family_id Ідентифікатор родини.
 * @param int $user_id   Ідентифікатор користувача.
 * @return bool
 */
function fb_currency_user_has_family_access( int $family_id, int $user_id ): bool {
	global $wpdb;

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}UserFamily
			 WHERE Family_ID = %d AND User_ID = %d",
			$family_id,
			$user_id
		)
	);
}

/**
 * Перевіряє, чи вже прив'язана валюта до родини.
 *
 * @param int $family_id   Ідентифікатор родини.
 * @param int $currency_id Ідентифікатор валюти.
 * @param int $exclude_id  Ідентифікатор запису, який треба виключити.
 * @return bool
 */
function fb_currency_family_has_currency( int $family_id, int $currency_id, int $exclude_id = 0 ): bool {
	global $wpdb;

	$sql  = "SELECT COUNT(*)
	         FROM {$wpdb->prefix}CurrencyFamily
	         WHERE Family_ID = %d AND Currency_ID = %d";
	$args = array( $family_id, $currency_id );

	if ( $exclude_id > 0 ) {
		$sql   .= ' AND id <> %d';
		$args[] = $exclude_id;
	}

	return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
}

/**
 * Перевіряє наявність транзакцій по валюті в межах родини.
 *
 * @param int $family_id   Ідентифікатор родини.
 * @param int $currency_id Ідентифікатор валюти.
 * @return bool
 */
function fb_currency_has_transactions( int $family_id, int $currency_id ): bool {
	global $wpdb;

	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}Amount AS a
			 INNER JOIN {$wpdb->prefix}Account AS acc ON acc.id = a.Account_ID
			 WHERE a.Currency_ID = %d AND acc.Family_ID = %d",
			$currency_id,
			$family_id
		)
	);
}

/**
 * Повертає HTML-рядки таблиці валют.
 *
 * @param array $rows Рядки CurrencyFamily.
 * @return string
 */
function fb_currency_render_rows_html( array $rows ): string {
	ob_start();

	if ( empty( $rows ) ) {
		?>
		<tr>
			<td colspan="7" class="fb-currency-empty">
				<?php esc_html_e( 'Для вибраних родин валюти ще не додані.', 'family-budget' ); ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	foreach ( $rows as $row ) :
		$is_primary = (int) $row->CurrencyFamily_Primary === 1;
		?>
		<tr data-id="<?php echo esc_attr( $row->id ); ?>" data-family-id="<?php echo esc_attr( $row->Family_ID ); ?>">
			<td class="fb-currency-drag" title="<?php esc_attr_e( 'Перетягніть для зміни порядку', 'family-budget' ); ?>">⋮⋮</td>
			<td><?php echo esc_html( $row->Family_Name ); ?></td>
			<td>
				<div class="fb-currency-name"><?php echo esc_html( $row->Currency_Name ); ?></div>
			</td>
			<td class="text-center"><?php echo esc_html( $row->Currency_Code ); ?></td>
			<td class="text-center"><?php echo esc_html( $row->Currency_Symbol ); ?></td>
			<td class="text-center">
				<span class="fb-currency-badge <?php echo $is_primary ? 'is-primary' : 'is-secondary'; ?>">
					<?php echo $is_primary ? esc_html__( 'Основна', 'family-budget' ) : esc_html__( 'Додаткова', 'family-budget' ); ?>
				</span>
			</td>
			<td class="text-center">
				<div class="fb-currency-actions">
					<button
						type="button"
						class="fb-currency-action fb-currency-primary"
						data-action="set-primary"
						title="<?php esc_attr_e( 'Зробити основною', 'family-budget' ); ?>"
						<?php disabled( $is_primary ); ?>
					>
						★
					</button>
					<button
						type="button"
						class="fb-currency-action fb-currency-delete"
						data-action="delete"
						title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>"
					>
						🗑
					</button>
				</div>
			</td>
		</tr>
		<?php
	endforeach;

	return (string) ob_get_clean();
}

/**
 * Рендерить шорткод модуля валют.
 *
 * @return string
 */
function fb_shortcode_currency_interface(): string {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
	}

	$families  = function_exists( 'fb_get_families' ) ? fb_get_families() : array();
	$catalog   = function_exists( 'fb_get_currencies' ) ? fb_get_currencies( 0 ) : array();
	$css_ver   = file_exists( FB_PLUGIN_DIR . 'css/currency.css' ) ? (string) filemtime( FB_PLUGIN_DIR . 'css/currency.css' ) : FB_VERSION;
	$js_ver    = file_exists( FB_PLUGIN_DIR . 'js/currency.js' ) ? (string) filemtime( FB_PLUGIN_DIR . 'js/currency.js' ) : FB_VERSION;

	wp_enqueue_style(
		'fb-currency-css',
		FB_PLUGIN_URL . 'css/currency.css',
		array(),
		$css_ver
	);

	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_script(
		'fb-currency-js',
		FB_PLUGIN_URL . 'js/currency.js',
		array( 'jquery', 'jquery-ui-sortable' ),
		$js_ver,
		true
	);

	wp_localize_script(
		'fb-currency-js',
		'fbCurrencyObj',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fb_currency_nonce' ),
			'i18n'     => array(
				'confirm_delete' => __( 'Ви впевнені, що хочете видалити валюту з родини?', 'family-budget' ),
				'load_error'     => __( 'Помилка завантаження даних.', 'family-budget' ),
				'server_error'   => __( "Помилка з'єднання з сервером.", 'family-budget' ),
				'select_family'  => __( 'Оберіть родину.', 'family-budget' ),
				'select_currency' => __( 'Оберіть валюту.', 'family-budget' ),
				'added'          => __( 'Валюту успішно додано.', 'family-budget' ),
			),
		)
	);

	ob_start();
	?>
	<div class="fb-currency-module" id="fb-currency-module">
		<div class="fb-currency-toolbar">
			<div class="fb-currency-filter">
				<label class="fb-currency-label" for="fb-currency-filter-family"><?php esc_html_e( 'Родина', 'family-budget' ); ?></label>
				<select id="fb-currency-filter-family" class="fb-currency-select">
					<option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
					<?php foreach ( (array) $families as $family ) : ?>
						<?php
						$family_id   = fb_extract_value( $family, array( 'id', 'ID' ) );
						$family_name = fb_extract_value( $family, array( 'Family_Name', 'name', 'Name' ) );
						?>
						<option value="<?php echo esc_attr( $family_id ); ?>"><?php echo esc_html( $family_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<form id="fb-currency-add-form" class="fb-currency-add">
				<div class="fb-currency-field">
					<label class="fb-currency-label" for="fb-currency-family-id"><?php esc_html_e( 'Родина', 'family-budget' ); ?></label>
					<select id="fb-currency-family-id" name="family_id" class="fb-currency-select" required>
						<option value=""><?php esc_html_e( 'Оберіть родину', 'family-budget' ); ?></option>
						<?php foreach ( (array) $families as $family ) : ?>
							<?php
							$family_id   = fb_extract_value( $family, array( 'id', 'ID' ) );
							$family_name = fb_extract_value( $family, array( 'Family_Name', 'name', 'Name' ) );
							?>
							<option value="<?php echo esc_attr( $family_id ); ?>"><?php echo esc_html( $family_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="fb-currency-field fb-currency-field--wide">
					<label class="fb-currency-label" for="fb-currency-catalog-id"><?php esc_html_e( 'Валюта', 'family-budget' ); ?></label>
					<select id="fb-currency-catalog-id" name="currency_id" class="fb-currency-select" required>
						<option value=""><?php esc_html_e( 'Оберіть валюту', 'family-budget' ); ?></option>
						<?php foreach ( $catalog as $currency ) : ?>
							<?php
							$currency_id     = fb_extract_value( $currency, array( 'id', 'ID' ) );
							$currency_name   = fb_extract_value( $currency, array( 'name', 'Currency_Name' ) );
							$currency_code   = fb_extract_value( $currency, array( 'code', 'Currency_Code' ) );
							$currency_symbol = fb_extract_value( $currency, array( 'symbol', 'Currency_Symbol' ) );
							?>
							<option value="<?php echo esc_attr( $currency_id ); ?>">
								<?php
								echo esc_html(
									$currency_name . ' (' . $currency_code . ') ' . $currency_symbol
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<button type="submit" class="fb-currency-submit">
					<?php esc_html_e( 'Додати', 'family-budget' ); ?>
				</button>
			</form>
		</div>

		<div class="fb-currency-meta">
			<div class="fb-currency-status" id="fb-currency-status" aria-live="polite"></div>
		</div>

		<div class="fb-currency-table-wrap">
			<table class="fb-currency-table">
				<thead>
					<tr>
						<th width="42"></th>
						<th><?php esc_html_e( 'Родина', 'family-budget' ); ?></th>
						<th><?php esc_html_e( 'Валюта', 'family-budget' ); ?></th>
						<th width="90" class="text-center"><?php esc_html_e( 'Код', 'family-budget' ); ?></th>
						<th width="90" class="text-center"><?php esc_html_e( 'Символ', 'family-budget' ); ?></th>
						<th width="120" class="text-center"><?php esc_html_e( 'Статус', 'family-budget' ); ?></th>
						<th width="120" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
					</tr>
				</thead>
				<tbody id="fb-currency-tbody">
					<tr>
						<td colspan="7" class="fb-currency-empty"><?php esc_html_e( 'Завантаження...', 'family-budget' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * AJAX: Повертає HTML-рядки таблиці валют.
 *
 * @return void
 */
function fb_ajax_load_currencies(): void {
	fb_currency_verify_request();

	$family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
	$rows      = fb_currency_get_rows( $family_id );

	wp_send_json_success(
		array(
			'html'  => fb_currency_render_rows_html( $rows ),
			'count' => count( $rows ),
		)
	);
}

/**
 * AJAX: Додає валюту до родини через таблицю CurrencyFamily.
 *
 * @return void
 */
function fb_ajax_add_currency(): void {
	fb_currency_verify_request();

	global $wpdb;

	$user_id     = get_current_user_id();
	$family_id   = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
	$currency_id = isset( $_POST['currency_id'] ) ? absint( wp_unslash( $_POST['currency_id'] ) ) : 0;

	if ( $family_id < 1 ) {
		wp_send_json_error( array( 'message' => __( 'Оберіть родину.', 'family-budget' ) ) );
	}

	if ( $currency_id < 1 ) {
		wp_send_json_error( array( 'message' => __( 'Оберіть валюту.', 'family-budget' ) ) );
	}

	if ( ! fb_currency_user_has_family_access( $family_id, $user_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Немає доступу до обраної родини.', 'family-budget' ) ) );
	}

	$currency_exists = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}Currency WHERE id = %d",
			$currency_id
		)
	);

	if ( ! $currency_exists ) {
		wp_send_json_error( array( 'message' => __( 'Валюту не знайдено.', 'family-budget' ) ) );
	}

	if ( fb_currency_family_has_currency( $family_id, $currency_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Ця валюта вже додана до родини.', 'family-budget' ) ) );
	}

	$max_order = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE(MAX(CurrencyFamily_Order), 0)
			 FROM {$wpdb->prefix}CurrencyFamily
			 WHERE Family_ID = %d",
			$family_id
		)
	);

	$family_currency_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}CurrencyFamily
			 WHERE Family_ID = %d",
			$family_id
		)
	);

	$inserted = $wpdb->insert(
		"{$wpdb->prefix}CurrencyFamily",
		array(
			'Family_ID'              => $family_id,
			'Currency_ID'            => $currency_id,
			'CurrencyFamily_Primary' => 0 === $family_currency_count ? 1 : 0,
			'CurrencyFamily_Order'   => $max_order + 1,
			'created_at'             => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%d', '%d', '%s' )
	);

	if ( ! $inserted ) {
		wp_send_json_error( array( 'message' => __( 'Не вдалося додати валюту до родини.', 'family-budget' ) ) );
	}

	wp_send_json_success( array( 'message' => __( 'Валюту успішно додано.', 'family-budget' ) ) );
}

/**
 * AJAX: Встановлює основну валюту родини.
 *
 * @return void
 */
function fb_ajax_set_primary_currency(): void {
	fb_currency_verify_request();

	global $wpdb;

	$cf_id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
	$user_id = get_current_user_id();
	$cf      = fb_currency_get_accessible_cf( $cf_id, $user_id );

	if ( ! $cf ) {
		wp_send_json_error( array( 'message' => __( 'Запис не знайдено або доступ заборонено.', 'family-budget' ) ) );
	}

	$wpdb->update(
		"{$wpdb->prefix}CurrencyFamily",
		array( 'CurrencyFamily_Primary' => 0 ),
		array( 'Family_ID' => (int) $cf->Family_ID ),
		array( '%d' ),
		array( '%d' )
	);

	$updated = $wpdb->update(
		"{$wpdb->prefix}CurrencyFamily",
		array( 'CurrencyFamily_Primary' => 1 ),
		array( 'id' => $cf_id ),
		array( '%d' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		wp_send_json_error( array( 'message' => __( 'Не вдалося оновити основну валюту.', 'family-budget' ) ) );
	}

	wp_send_json_success();
}

/**
 * AJAX: Видаляє валюту родини, якщо по ній немає транзакцій.
 *
 * @return void
 */
function fb_ajax_delete_currency(): void {
	fb_currency_verify_request();

	$cf_id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
	$user_id = get_current_user_id();
	$cf      = fb_currency_get_accessible_cf( $cf_id, $user_id );

	if ( ! $cf ) {
		wp_send_json_error( array( 'message' => __( 'Запис не знайдено або доступ заборонено.', 'family-budget' ) ) );
	}

	if ( fb_currency_has_transactions( (int) $cf->Family_ID, (int) $cf->Currency_ID ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Неможливо видалити валюту, оскільки по ній є транзакції', 'family-budget' ),
			)
		);
	}

	global $wpdb;

	$deleted = $wpdb->delete(
		"{$wpdb->prefix}CurrencyFamily",
		array( 'id' => $cf_id ),
		array( '%d' )
	);

	if ( ! $deleted ) {
		wp_send_json_error( array( 'message' => __( 'Не вдалося видалити валюту.', 'family-budget' ) ) );
	}

	$remaining_primary = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}CurrencyFamily
			 WHERE Family_ID = %d AND CurrencyFamily_Primary = 1",
			(int) $cf->Family_ID
		)
	);

	if ( 0 === $remaining_primary ) {
		$next_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM {$wpdb->prefix}CurrencyFamily
				 WHERE Family_ID = %d
				 ORDER BY CurrencyFamily_Order ASC, id ASC
				 LIMIT 1",
				(int) $cf->Family_ID
			)
		);

		if ( $next_id > 0 ) {
			$wpdb->update(
				"{$wpdb->prefix}CurrencyFamily",
				array( 'CurrencyFamily_Primary' => 1 ),
				array( 'id' => $next_id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	wp_send_json_success();
}

/**
 * AJAX: Оновлює порядок валют у межах родини після drag&drop.
 *
 * @return void
 */
function fb_ajax_reorder_currencies(): void {
	fb_currency_verify_request();

	global $wpdb;

	$user_id = get_current_user_id();
	$order   = isset( $_POST['order'] ) ? (array) wp_unslash( $_POST['order'] ) : array();

	if ( empty( $order ) ) {
		wp_send_json_error( array( 'message' => __( 'Невірні дані сортування.', 'family-budget' ) ) );
	}

	$normalized_order = array_values( array_filter( array_map( 'absint', $order ) ) );
	if ( empty( $normalized_order ) ) {
		wp_send_json_error( array( 'message' => __( 'Невірні дані сортування.', 'family-budget' ) ) );
	}

	$family_id = 0;
	foreach ( $normalized_order as $position => $cf_id ) {
		$cf = fb_currency_get_accessible_cf( $cf_id, $user_id );
		if ( ! $cf ) {
			continue;
		}

		if ( 0 === $family_id ) {
			$family_id = (int) $cf->Family_ID;
		}

		if ( $family_id !== (int) $cf->Family_ID ) {
			continue;
		}

		$wpdb->update(
			"{$wpdb->prefix}CurrencyFamily",
			array( 'CurrencyFamily_Order' => $position + 1 ),
			array( 'id' => $cf_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	wp_send_json_success();
}
