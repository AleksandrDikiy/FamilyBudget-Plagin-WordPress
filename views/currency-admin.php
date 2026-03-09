<?php
/**
 * Модуль управління валютами — Family Budget (Admin)
 *
 * Реалізує AJAX-CRUD довідник системних валют (USD, EUR, UAH тощо).
 * Доступний виключно адміністраторам WordPress (capability: manage_options).
 *
 * Структура файлу:
 *  1. Константи та ініціалізація
 *  2. Допоміжні функції безпеки
 *  3. AJAX-обробники (бізнес-логіка)
 *  4. Шорткод + HTML-рендеринг
 *  5. Підключення ресурсів (assets)
 *
 * @package    FamilyBudget
 * @subpackage Modules
 * @version    1.0.0
 * @since      1.3.7
 */

// Захист від прямого доступу до файлу.
defined( 'ABSPATH' ) || exit;

// =============================================================================
// 1. КОНСТАНТИ ТА ІНІЦІАЛІЗАЦІЯ
// =============================================================================

/** @var string Назва таблиці валют без урахування глобального $wpdb->prefix. */
define( 'FB_CURRENCY_TABLE', 'Currency' );

// =============================================================================
// 2. ДОПОМІЖНІ ФУНКЦІЇ БЕЗПЕКИ
// =============================================================================

/**
 * Виконує двошарову перевірку запиту: роль + nonce (CSRF).
 *
 * Призначена для всіх AJAX-обробників цього модуля.
 * При невдалій перевірці негайно повертає JSON-помилку та завершує виконання.
 *
 * @since  1.0.0
 * @return void
 */
function fb_currency_admin_verify_request(): void {
	// Шар 1: Перевірка ролі.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Недостатньо прав доступу.', 'family-budget' ) ), 403 );
	}

	// Шар 2: Перевірка nonce (захист від CSRF).
	check_ajax_referer( 'fb_currency_admin_nonce', 'security' );
}

// =============================================================================
// 3. AJAX-ОБРОБНИКИ (БІЗНЕС-ЛОГІКА)
// =============================================================================

/**
 * AJAX: Зберігає (додає або оновлює) запис валюти.
 *
 * Очікує POST-параметри: security, id (0 = новий), currency_code,
 * currency_name, currency_symbol.
 *
 * @since  1.0.0
 * @return void  Завершує виконання через wp_send_json_*.
 */
function fb_ajax_currency_save(): void {
	fb_currency_admin_verify_request();

	global $wpdb;
	$table = $wpdb->prefix . FB_CURRENCY_TABLE;

	// Санітизація вхідних даних.
	$id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
	$code   = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency_code']   ?? '' ) ) );
	$name   = sanitize_text_field( wp_unslash( $_POST['currency_name']   ?? '' ) );
	$symbol = sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ?? '' ) );

	// Базова валідація.
	if ( '' === $code || '' === $name ) {
		wp_send_json_error( array( 'message' => __( 'Код та назва валюти є обов\'язковими.', 'family-budget' ) ) );
	}

	if ( strlen( $code ) > 10 ) {
		wp_send_json_error( array( 'message' => __( 'Код валюти не може перевищувати 10 символів.', 'family-budget' ) ) );
	}

	$data   = array(
		'Currency_Code'   => $code,
		'Currency_Name'   => $name,
		'Currency_Symbol' => $symbol,
		'updated_at'      => current_time( 'mysql' ),
	);
	$format = array( '%s', '%s', '%s', '%s' );

	if ( $id > 0 ) {
		// Перевірка існування запису.
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) );
		if ( ! $exists ) {
			wp_send_json_error( array( 'message' => __( 'Запис не знайдено.', 'family-budget' ) ) );
		}

		$result = $wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) );
	} else {
		$data['created_at'] = current_time( 'mysql' );
		$format[]           = '%s';
		$result             = $wpdb->insert( $table, $data, $format );
		$id                 = $wpdb->insert_id;
	}

	if ( false === $result ) {
		wp_send_json_error( array( 'message' => __( 'Помилка збереження до бази даних.', 'family-budget' ) ) );
	}

	// Повертаємо збережений запис для оновлення UI без перезавантаження.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

	wp_send_json_success(
		array(
			'message'  => __( 'Валюту збережено.', 'family-budget' ),
			'currency' => $row,
		)
	);
}

add_action( 'wp_ajax_fb_currency_save', 'fb_ajax_currency_save' );

/**
 * AJAX: Видаляє запис валюти.
 *
 * Перед видаленням перевіряє, чи валюта не використовується в таблиці
 * CurrencyFamily (прив'язана до родини).
 *
 * @since  1.0.0
 * @return void  Завершує виконання через wp_send_json_*.
 */
function fb_ajax_currency_delete(): void {
	fb_currency_admin_verify_request();

	global $wpdb;
	$table = $wpdb->prefix . FB_CURRENCY_TABLE;

	$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );

	if ( ! $id ) {
		wp_send_json_error( array( 'message' => __( 'Невірний ідентифікатор.', 'family-budget' ) ) );
	}

	// Перевірка використання у родинах.
	$in_use = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}CurrencyFamily WHERE Currency_ID = %d",
			$id
		)
	);

	if ( $in_use > 0 ) {
		wp_send_json_error(
			array( 'message' => __( 'Неможливо видалити: валюта використовується в активних родинах.', 'family-budget' ) )
		);
	}

	$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

	if ( false === $result ) {
		wp_send_json_error( array( 'message' => __( 'Помилка видалення.', 'family-budget' ) ) );
	}

	wp_send_json_success( array( 'message' => __( 'Валюту видалено.', 'family-budget' ), 'id' => $id ) );
}

add_action( 'wp_ajax_fb_currency_delete', 'fb_ajax_currency_delete' );

/**
 * AJAX: Повертає список усіх валют у форматі JSON.
 *
 * Використовується для початкового завантаження таблиці через JS.
 *
 * @since  1.0.0
 * @return void  Завершує виконання через wp_send_json_*.
 */
function fb_ajax_currency_list(): void {
	fb_currency_admin_verify_request();

	global $wpdb;
	$table    = $wpdb->prefix . FB_CURRENCY_TABLE;
	$currency = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );

	wp_send_json_success( array( 'currencies' => $currency ) );
}

add_action( 'wp_ajax_fb_currency_list', 'fb_ajax_currency_list' );

// =============================================================================
// 4. ШОРТКОД + HTML-РЕНДЕРИНГ
// =============================================================================

/**
 * Відображає інтерфейс управління валютами.
 *
 * Виводить компактну форму додавання/редагування та AJAX-таблицю наявних валют.
 * Підключає необхідні стилі та скрипти для даної сторінки.
 * Доступний виключно адміністраторам.
 *
 * @since  1.0.0
 * @return string HTML-розмітка інтерфейсу або повідомлення про відмову в доступі.
 */
function fb_render_currency_admin_interface(): string {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '<p class="fb-access-denied">' . esc_html__( 'Доступ обмежено.', 'family-budget' ) . '</p>';
	}

	// Підключаємо ресурси модуля безпосередньо при рендері шорткоду,
	// щоб не навантажувати ресурсами сторінки, де шорткод відсутній.
	fb_currency_admin_enqueue_assets();

	ob_start();
	?>
	<div class="fb-container fb-currency-admin" id="fb-currency-wrap">

		<h2><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Довідник: Валюти', 'family-budget' ); ?></h2>

		<div id="fb-currency-notice" class="fb-notice" style="display:none;" role="alert" aria-live="polite"></div>

		<?php // ── Форма додавання / редагування ────────────────────────────── ?>
		<div class="fb-card">
			<input type="hidden" id="fb-currency-id" value="0">
			<div class="fb-form-row">
				<input
					type="text"
					id="fb-currency-code"
					placeholder="<?php esc_attr_e( 'Код (USD)', 'family-budget' ); ?>"
					maxlength="10"
					aria-label="<?php esc_attr_e( 'Код валюти', 'family-budget' ); ?>"
				>
				<input
					type="text"
					id="fb-currency-name"
					placeholder="<?php esc_attr_e( 'Назва (Долар США)', 'family-budget' ); ?>"
					aria-label="<?php esc_attr_e( 'Назва валюти', 'family-budget' ); ?>"
				>
				<input
					type="text"
					id="fb-currency-symbol"
					placeholder="<?php esc_attr_e( 'Символ ($)', 'family-budget' ); ?>"
					maxlength="5"
					aria-label="<?php esc_attr_e( 'Символ валюти', 'family-budget' ); ?>"
				>
				<button type="button" id="fb-currency-save" class="fb-btn-save">
					<?php esc_html_e( 'Додати', 'family-budget' ); ?>
				</button>
				<button type="button" id="fb-currency-cancel" class="fb-btn-cancel" style="display:none;">
					<?php esc_html_e( 'Скасувати', 'family-budget' ); ?>
				</button>
			</div>
		</div>

		<?php // ── Таблиця валют ─────────────────────────────────────────────── ?>
		<table class="fb-table" id="fb-currency-table">
			<thead>
				<tr>
					<th style="width:50px;"><?php esc_html_e( 'ID', 'family-budget' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Код', 'family-budget' ); ?></th>
					<th><?php esc_html_e( 'Назва', 'family-budget' ); ?></th>
					<th style="width:70px;"><?php esc_html_e( 'Символ', 'family-budget' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
				</tr>
			</thead>
			<tbody id="fb-currency-tbody">
				<tr id="fb-currency-loading">
					<td colspan="5"><?php esc_html_e( 'Завантаження…', 'family-budget' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<?php
	// Локалізація JS-змінних для модуля.
	wp_localize_script(
		'fb-currency-admin-js',
		'fbCurrencyAdmin',
		array(
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'fb_currency_admin_nonce' ),
			'i18n'        => array(
				'confirm_delete' => __( 'Видалити цю валюту?', 'family-budget' ),
				'btn_update'     => __( 'Оновити', 'family-budget' ),
				'btn_add'        => __( 'Додати', 'family-budget' ),
				'error_required' => __( 'Код та назва є обов\'язковими.', 'family-budget' ),
			),
		)
	);

	return ob_get_clean();
}

add_shortcode( 'fb_currency_admin', 'fb_render_currency_admin_interface' );

// =============================================================================
// 5. ПІДКЛЮЧЕННЯ РЕСУРСІВ (ASSETS)
// =============================================================================

/**
 * Підключає CSS та JS файли модуля управління валютами.
 *
 * Викликається безпосередньо з fb_render_currency_admin_interface(),
 * тобто лише на сторінках, де присутній шорткод [fb_currency_admin].
 *
 * @since  1.0.0
 * @return void
 */
function fb_currency_admin_enqueue_assets(): void {
	// CSS.
	wp_enqueue_style(
		'fb-currency-admin-css',
		FB_PLUGIN_URL . 'css/currency-admin.css',
		array( 'family-budget-styles' ),
		FB_VERSION
	);

	// JS (в footer, щоб не блокувати рендеринг).
	wp_enqueue_script(
		'fb-currency-admin-js',
		FB_PLUGIN_URL . 'js/currency-admin.js',
		array( 'jquery' ),
		FB_VERSION,
		true // in_footer = true.
	);
}
