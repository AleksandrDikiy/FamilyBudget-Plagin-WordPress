<?php
/**
 * Модуль Валют (Family Budget)
 *
 * Реалізує повний CRUD для управління валютами родини
 * на основі нормалізованої двотабличної архітектури:
 * - {prefix}Currency      — глобальний довідник валют
 * - {prefix}CurrencyFamily — зв'язок валюти з родиною (порядок, головна)
 *
 * Назва файлу: currency.php
 *
 * @package FamilyBudget
 */

// Захист від прямого доступу до файлу.
defined( 'ABSPATH' ) || exit;

// =============================================================================
// ІНІЦІАЛІЗАЦІЯ ТА БЕЗПЕКА
// =============================================================================

/**
 * Двошарова перевірка безпеки: авторизація користувача + перевірка nonce (CSRF).
 *
 * Зупиняє виконання запиту і повертає JSON-помилку, якщо:
 * — користувач не авторизований;
 * — nonce відсутній або невалідний.
 *
 * @param string $action Ім'я дії для перевірки nonce. За замовчуванням 'fb_currency_nonce'.
 * @return void
 */
/**
 * Двошарова перевірка безпеки для AJAX-обробників цього модуля.
 *
 * @since  1.3.4
 * @param  string $action Ім'я nonce-дії WordPress.
 * @return void
 */
function fb_currency_verify_request( string $action = 'fb_currency_nonce' ): void {
	fb_verify_ajax_request( $action );
}

/**
 * Повертає запис із таблиці CurrencyFamily з перевіркою доступу поточного користувача.
 *
 * Виконує JOIN із UserFamily, щоб переконатися, що запис CurrencyFamily
 * дійсно належить родині, до якої має доступ вказаний користувач.
 *
 * @param int $cf_id   ID запису в таблиці {prefix}CurrencyFamily.
 * @param int $user_id ID поточного користувача WordPress.
 * @return object|null Об'єкт запису CurrencyFamily або null, якщо доступ відсутній.
 */
function fb_currency_get_accessible_cf( $cf_id, $user_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

// =============================================================================
// БІЗНЕС-ЛОГІКА / ОТРИМАННЯ ДАНИХ
// =============================================================================

/**
 * Повертає список валют родини(й) для поточного авторизованого користувача.
 *
 * Виконує повний JOIN між таблицями Currency, CurrencyFamily, Family та UserFamily,
 * щоб ізолювати дані за конкретним користувачем. Опціонально фільтрує за родиною.
 *
 * @param int $family_id ID родини для фільтрації. 0 — повернути всі доступні родини.
 * @return array Масив об'єктів із полями: cf_id, Currency_ID, Family_ID,
 *               CurrencyFamily_Primary, CurrencyFamily_Order,
 *               Currency_Name, Currency_Code, Currency_Symbol, Family_Name.
 */
function fb_get_currency_data( $family_id = 0 ) {
	global $wpdb;

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return [];
	}

	$family_id = absint( $family_id );

	$sql = "SELECT
				cf.id            AS cf_id,
				cf.Family_ID,
				cf.Currency_ID,
				cf.CurrencyFamily_Primary,
				cf.CurrencyFamily_Order,
				c.Currency_Name,
				c.Currency_Code,
				c.Currency_Symbol,
				f.Family_Name
			FROM {$wpdb->prefix}CurrencyFamily AS cf
			INNER JOIN {$wpdb->prefix}Currency     AS c  ON c.id        = cf.Currency_ID
			INNER JOIN {$wpdb->prefix}Family        AS f  ON f.id        = cf.Family_ID
			INNER JOIN {$wpdb->prefix}UserFamily    AS uf ON uf.Family_ID = cf.Family_ID
			WHERE uf.User_ID = %d";

	$args = [ $user_id ];

	if ( $family_id > 0 ) {
		$sql   .= ' AND cf.Family_ID = %d';
		$args[] = $family_id;
	}

	$sql .= ' ORDER BY cf.CurrencyFamily_Primary DESC, cf.CurrencyFamily_Order ASC';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
}

// =============================================================================
// ШОРТКОД / HTML-РЕНДЕРИНГ
// =============================================================================

add_shortcode( 'fb_currency', 'fb_shortcode_currency_interface' );

/**
 * Шорткод для відображення інтерфейсу управління валютами.
 *
 * Реєструє та підключає стилі/скрипти через wp_enqueue_*,
 * передає конфігурацію до JS через wp_localize_script.
 *
 * @return string Готова HTML-розмітка інтерфейсу або повідомлення про необхідність входу.
 */
function fb_shortcode_currency_interface() {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
	}

	$families = function_exists( 'fb_get_families' ) ? fb_get_families() : [];

	wp_enqueue_style(
		'fb-currency-css',
        FB_PLUGIN_URL . 'css/currency.css',
		[],
		'1.0.0'
	);
	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_script(
		'fb-currency-js',
        FB_PLUGIN_URL . 'js/currency.js',
		[ 'jquery', 'jquery-ui-sortable' ],
		'1.0.0',
		true
	);
	wp_localize_script(
		'fb-currency-js',
		'fbCurrencyObj',
		[
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fb_currency_nonce' ),
			'confirm'  => esc_html__( 'Ви впевнені, що хочете видалити цю валюту?', 'family-budget' ),
		]
	);

	ob_start();
	?>
	<div class="fb-currency-wrapper">

		<div class="fb-currency-controls">

			<div class="fb-filter-group">
				<select id="fb-filter-currency-family" class="fb-compact-input">
					<option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
					<?php if ( ! empty( $families ) ) : ?>
						<?php foreach ( $families as $f ) : ?>
							<?php
							$f_id   = fb_extract_value( $f, [ 'id', 'ID' ] );
							$f_name = fb_extract_value( $f, [ 'Family_Name', 'name', 'Name' ] );
							?>
							<option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<div id="fb-add-currency-form" class="fb-add-group">
				<select id="fb-new-family-id" class="fb-compact-input">
					<option value="" disabled selected><?php esc_html_e( 'Оберіть родину', 'family-budget' ); ?></option>
					<?php if ( ! empty( $families ) ) : ?>
						<?php foreach ( $families as $f ) : ?>
							<?php
							$f_id   = fb_extract_value( $f, [ 'id', 'ID' ] );
							$f_name = fb_extract_value( $f, [ 'Family_Name', 'name', 'Name' ] );
							?>
							<option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<input
					type="text"
					id="fb-new-currency-name"
					placeholder="<?php esc_attr_e( 'Назва (Гривня)', 'family-budget' ); ?>"
					class="fb-compact-input fb-input-name"
				>
				<input
					type="text"
					id="fb-new-currency-code"
					maxlength="3"
					placeholder="<?php esc_attr_e( 'Код (UAH)', 'family-budget' ); ?>"
					class="fb-compact-input fb-input-code"
				>
				<input
					type="text"
					id="fb-new-currency-symbol"
					maxlength="1"
					placeholder="<?php esc_attr_e( 'Символ (₴)', 'family-budget' ); ?>"
					class="fb-compact-input fb-input-symbol"
				>
				<button id="fb-add-currency-btn" class="fb-btn-primary">
					<?php esc_html_e( 'Додати', 'family-budget' ); ?>
				</button>
			</div>

		</div>

		<div class="fb-currency-table-container">
			<table class="fb-table">
				<thead>
					<tr>
						<th width="30"></th>
						<th><?php esc_html_e( 'Родина', 'family-budget' ); ?></th>
						<th><?php esc_html_e( 'Назва', 'family-budget' ); ?></th>
						<th width="80"><?php esc_html_e( 'Код', 'family-budget' ); ?></th>
						<th width="80" class="text-center"><?php esc_html_e( 'Символ', 'family-budget' ); ?></th>
						<th width="120" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
					</tr>
				</thead>
				<tbody id="fb-currency-tbody"></tbody>
			</table>
		</div>

	</div>
	<?php
	return ob_get_clean();
}

// =============================================================================
// AJAX-ОБРОБНИКИ
// =============================================================================

add_action( 'wp_ajax_fb_load_currencies', 'fb_ajax_load_currencies' );

/**
 * AJAX: Завантаження та рендеринг рядків таблиці валют.
 *
 * Приймає необов'язковий параметр family_id для фільтрації.
 * Повертає готовий HTML фрагмент для вставки в <tbody>.
 *
 * @return void Надсилає JSON-відповідь із полем 'html'.
 */
function fb_ajax_load_currencies() {
	fb_currency_verify_request();

	$family_id  = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
	$currencies = fb_get_currency_data( $family_id );

	ob_start();

	if ( empty( $currencies ) ) {
		printf(
			'<tr><td colspan="6" class="text-center">%s</td></tr>',
			esc_html__( 'Записів не знайдено', 'family-budget' )
		);
	} else {
		foreach ( $currencies as $curr ) {
			$is_primary  = 1 === (int) $curr->CurrencyFamily_Primary;
			$star_class  = $is_primary ? 'fb-star is-primary' : 'fb-star';
			$family_name = ! empty( $curr->Family_Name ) ? $curr->Family_Name : '—';
			?>
			<tr data-id="<?php echo esc_attr( $curr->cf_id ); ?>">
				<td class="fb-drag-handle">☰</td>
				<td><?php echo esc_html( $family_name ); ?></td>

				<td class="fb-edit-col">
					<span class="fb-text-val fb-name-val"><?php echo esc_html( $curr->Currency_Name ); ?></span>
					<input
						type="text"
						class="fb-input-val fb-name-input hidden fb-compact-input"
						value="<?php echo esc_attr( $curr->Currency_Name ); ?>"
					>
				</td>
				<td class="fb-edit-col">
					<span class="fb-text-val fb-code-val"><?php echo esc_html( $curr->Currency_Code ); ?></span>
					<input
						type="text"
						maxlength="3"
						class="fb-input-val fb-code-input hidden fb-compact-input"
						value="<?php echo esc_attr( $curr->Currency_Code ); ?>"
					>
				</td>
				<td class="fb-edit-col text-center">
					<span class="fb-text-val fb-symbol-val"><?php echo esc_html( $curr->Currency_Symbol ); ?></span>
					<input
						type="text"
						maxlength="1"
						class="fb-input-val fb-symbol-input hidden fb-compact-input text-center"
						value="<?php echo esc_attr( $curr->Currency_Symbol ); ?>"
					>
				</td>
				<td class="fb-actions text-center">
					<span
						class="<?php echo esc_attr( $star_class ); ?>"
						data-action="set_primary"
						title="<?php esc_attr_e( 'Головна', 'family-budget' ); ?>"
					>★</span>
					<span
						class="fb-edit-btn"
						data-action="edit"
						title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>"
					>✎</span>
					<span
						class="fb-save-btn hidden"
						data-action="save"
						title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>"
					>✔</span>
					<span
						class="fb-delete-btn"
						data-action="delete"
						title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>"
					>✖</span>
				</td>
			</tr>
			<?php
		}
	}

	wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

add_action( 'wp_ajax_fb_add_currency', 'fb_ajax_add_currency' );

/**
 * AJAX: Додавання нової валюти.
 *
 * Послідовність операцій:
 * 1. Валідація вхідних даних та перевірка доступу до родини.
 * 2. Вставка запису до глобального довідника {prefix}Currency.
 * 3. Прив'язка валюти до родини через {prefix}CurrencyFamily.
 * У разі збою на кроці 3 — відкочує вставку з кроку 2.
 *
 * @return void Надсилає JSON-відповідь із результатом операції.
 */
function fb_ajax_add_currency() {
	fb_currency_verify_request();
	global $wpdb;

	$user_id   = get_current_user_id();
	$family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
	$name      = isset( $_POST['currency_name'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_name'] ) ) : '';
	$code      = isset( $_POST['currency_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency_code'] ) ) ) : '';
	$symbol    = isset( $_POST['currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ) ) : '';

	// --- Валідація ---
	if ( empty( $name ) ) {
		wp_send_json_error( [ 'message' => "Назва валюти обов'язкова." ] );
	}

	if ( $family_id < 1 ) {
		wp_send_json_error( [ 'message' => 'Оберіть родину.' ] );
	}

	// --- Перевірка доступу до родини ---
	$has_access = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
			$user_id,
			$family_id
		)
	);

	if ( ! $has_access ) {
		wp_send_json_error( [ 'message' => 'Немає доступу до обраної родини.' ] );
	}

	// --- Крок 1: Вставка у глобальний довідник валют ---
	$inserted = $wpdb->insert(
		"{$wpdb->prefix}Currency",
		[
			'Currency_Name'   => $name,
			'Currency_Code'   => $code,
			'Currency_Symbol' => $symbol,
			'Currency_Order'  => 1,
			'created_at'      => current_time( 'mysql' ),
		],
		[ '%s', '%s', '%s', '%d', '%s' ]
	);

	if ( ! $inserted ) {
		wp_send_json_error( [ 'message' => 'Помилка збереження валюти в довідник.' ] );
	}

	$currency_id = (int) $wpdb->insert_id;

	// --- Крок 2: Прив'язка до родини ---
	$max_order = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(CurrencyFamily_Order) FROM {$wpdb->prefix}CurrencyFamily WHERE Family_ID = %d",
			$family_id
		)
	);

	$linked = $wpdb->insert(
		"{$wpdb->prefix}CurrencyFamily",
		[
			'Family_ID'              => $family_id,
			'Currency_ID'            => $currency_id,
			'CurrencyFamily_Primary' => 0,
			'CurrencyFamily_Order'   => $max_order + 1,
			'created_at'             => current_time( 'mysql' ),
		],
		[ '%d', '%d', '%d', '%d', '%s' ]
	);

	if ( ! $linked ) {
		// Відкочуємо "осиротілий" запис довідника при помилці прив'язки.
		$wpdb->delete( "{$wpdb->prefix}Currency", [ 'id' => $currency_id ], [ '%d' ] );
		wp_send_json_error( [ 'message' => "Помилка прив'язки валюти до родини." ] );
	}

	wp_send_json_success( [ 'message' => 'Валюту додано успішно.' ] );
}

add_action( 'wp_ajax_fb_set_primary_currency', 'fb_ajax_set_primary_currency' );

/**
 * AJAX: Встановлення головної (основної) валюти для родини.
 *
 * Спочатку скидає прапор CurrencyFamily_Primary = 0 для всіх валют родини,
 * потім встановлює CurrencyFamily_Primary = 1 для обраного запису.
 *
 * @return void Надсилає JSON-відповідь із результатом операції.
 */
function fb_ajax_set_primary_currency() {
	fb_currency_verify_request();
	global $wpdb;

	$cf_id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
	$user_id = get_current_user_id();

	$cf = fb_currency_get_accessible_cf( $cf_id, $user_id );
	if ( ! $cf ) {
		wp_send_json_error( [ 'message' => 'Помилка доступу або запис не знайдено.' ] );
	}

	// Скидаємо "головну" для всіх валют цієї родини.
	$wpdb->update(
		"{$wpdb->prefix}CurrencyFamily",
		[ 'CurrencyFamily_Primary' => 0 ],
		[ 'Family_ID' => (int) $cf->Family_ID ],
		[ '%d' ],
		[ '%d' ]
	);

	// Встановлюємо нову головну валюту.
	$wpdb->update(
		"{$wpdb->prefix}CurrencyFamily",
		[ 'CurrencyFamily_Primary' => 1 ],
		[ 'id' => $cf_id ],
		[ '%d' ],
		[ '%d' ]
	);

	wp_send_json_success();
}

add_action( 'wp_ajax_fb_delete_currency', 'fb_ajax_delete_currency' );

/**
 * AJAX: Видалення валюти родини.
 *
 * Видаляє запис із таблиці CurrencyFamily.
 * Якщо після видалення зв'язку запис у глобальному довіднику Currency
 * більше не використовується жодною родиною — він також видаляється.
 *
 * @return void Надсилає JSON-відповідь із результатом операції.
 */
function fb_ajax_delete_currency() {
	fb_currency_verify_request();
	global $wpdb;

	$cf_id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
	$user_id = get_current_user_id();

	$cf = fb_currency_get_accessible_cf( $cf_id, $user_id );
	if ( ! $cf ) {
		wp_send_json_error( [ 'message' => 'Помилка доступу або запис не знайдено.' ] );
	}

	$currency_id = (int) $cf->Currency_ID;

	// Видаляємо зв'язок валюта ↔ родина.
	$deleted = $wpdb->delete(
		"{$wpdb->prefix}CurrencyFamily",
		[ 'id' => $cf_id ],
		[ '%d' ]
	);

	if ( ! $deleted ) {
		wp_send_json_error( [ 'message' => 'Неможливо видалити валюту.' ] );
	}

	// Очищуємо "осиротілий" запис глобального довідника.
	$still_used = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}CurrencyFamily WHERE Currency_ID = %d",
			$currency_id
		)
	);

	if ( ! $still_used ) {
		$wpdb->delete( "{$wpdb->prefix}Currency", [ 'id' => $currency_id ], [ '%d' ] );
	}

	wp_send_json_success();
}

add_action( 'wp_ajax_fb_edit_currency', 'fb_ajax_edit_currency' );

/**
 * AJAX: Збереження результатів inline-редагування валюти.
 *
 * Ідентифікація рядка виконується за ID запису CurrencyFamily (cf_id).
 * Оновлення застосовується до запису глобального довідника Currency.
 * Доступ перевіряється через ланцюг CurrencyFamily → UserFamily.
 *
 * @return void Надсилає JSON-відповідь із результатом операції.
 */
function fb_ajax_edit_currency() {
	fb_currency_verify_request();
	global $wpdb;

	$cf_id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
	$user_id = get_current_user_id();
	$name    = isset( $_POST['name'] )   ? sanitize_text_field( wp_unslash( $_POST['name'] ) )   : '';
	$code    = isset( $_POST['code'] )   ? strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ) ) )   : '';
	$symbol  = isset( $_POST['symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['symbol'] ) ) : '';

	if ( empty( $name ) ) {
		wp_send_json_error( [ 'message' => 'Назва не може бути порожньою.' ] );
	}

	$cf = fb_currency_get_accessible_cf( $cf_id, $user_id );
	if ( ! $cf ) {
		wp_send_json_error( [ 'message' => 'Помилка доступу або запис не знайдено.' ] );
	}

	$updated = $wpdb->update(
		"{$wpdb->prefix}Currency",
		[
			'Currency_Name'   => $name,
			'Currency_Code'   => $code,
			'Currency_Symbol' => $symbol,
		],
		[ 'id' => (int) $cf->Currency_ID ],
		[ '%s', '%s', '%s' ],
		[ '%d' ]
	);

	if ( false === $updated ) {
		wp_send_json_error( [ 'message' => 'Помилка збереження змін.' ] );
	}

	wp_send_json_success();
}

add_action( 'wp_ajax_fb_reorder_currencies', 'fb_ajax_reorder_currencies' );

/**
 * AJAX: Збереження нового порядку відображення валют після drag & drop.
 *
 * Приймає масив ID записів CurrencyFamily у потрібному порядку
 * та оновлює поле CurrencyFamily_Order для кожного з них.
 * Перед кожним оновленням виконується перевірка доступу користувача.
 *
 * @return void Надсилає JSON-відповідь із результатом операції.
 */
function fb_ajax_reorder_currencies() {
	fb_currency_verify_request();
	global $wpdb;

	if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
		wp_send_json_error( [ 'message' => 'Невірні дані для сортування.' ] );
	}

	$user_id = get_current_user_id();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$order = array_map( 'absint', wp_unslash( $_POST['order'] ) );

	foreach ( $order as $index => $cf_id ) {
		if ( ! $cf_id ) {
			continue;
		}

		// Перевірка доступу перед оновленням кожного запису.
		if ( ! fb_currency_get_accessible_cf( $cf_id, $user_id ) ) {
			continue;
		}

		$wpdb->update(
			"{$wpdb->prefix}CurrencyFamily",
			[ 'CurrencyFamily_Order' => $index + 1 ],
			[ 'id' => $cf_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	wp_send_json_success();
}
