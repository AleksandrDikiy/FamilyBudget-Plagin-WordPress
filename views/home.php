<?php
/**
 * Модуль "Головна" — плагін Family Budget.
 *
 * @package FamilyBudget
 */

defined( 'ABSPATH' ) || exit;

/* =============================================================================
 * 1. ІНІЦІАЛІЗАЦІЯ
 * ========================================================================== */

add_shortcode( 'fb_home', 'fb_shortcode_home_interface' );
add_action( 'wp_ajax_fb_ajax_save_onboarding', 'fb_ajax_save_onboarding' );
add_action( 'wp_enqueue_scripts', 'fb_home_enqueue_assets' );

/* =============================================================================
 * 2. ПІДКЛЮЧЕННЯ РЕСУРСІВ
 * ========================================================================== */

/**
 * Підключає CSS та JS модуля лише на сторінці з шорткодом [fb_home].
 * Додає inline-CSS з :has() для скидання layout теми на батьківських елементах.
 *
 * @return void
 */
function fb_home_enqueue_assets(): void {
	global $post;

	if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'fb_home' ) ) {
		return;
	}

	$base = plugin_dir_url( __FILE__ );
	$ver  = defined( 'FB_VERSION' ) ? FB_VERSION : '1.0.0';

	wp_enqueue_style( 'fb-home', $base . 'css/home.css', [], $ver );

	// CSS що скидає layout БАТЬКІВСЬКИХ елементів через :has() (сучасні браузери).
	wp_add_inline_style( 'fb-home', fb_home_get_parent_reset_css() );

	wp_enqueue_script( 'fb-home', $base . 'js/home.js', [ 'jquery' ], $ver, true );

	wp_localize_script(
		'fb-home',
		'fbHome',
		[
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'fb_home_nonce' ),
			'budgetUrl' => esc_url( home_url( '/budget/' ) ),
			'i18n'      => [
				'saving' => __( 'Збереження...', 'family-budget' ),
				'errReq' => __( 'Усі поля є обов\'язковими.', 'family-budget' ),
				'errSrv' => __( 'Помилка сервера. Деталі — консоль браузера (F12).', 'family-budget' ),
			],
		]
	);
}

/**
 * Генерує CSS що скидає layout теми для будь-якого батьківського елемента
 * сторінки що містить форму #fb-home-wrap.
 * Використовує :has() та широкий список відомих класів WP-тем.
 *
 * @return string CSS-рядок.
 */
function fb_home_get_parent_reset_css(): string {
	$font = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

	// Відомі класи-контейнери WordPress-тем.
	$parents = [
		'.entry-content', '.post-content', '.page-content', '.content-area',
		'.site-content', '.wp-block-post-content', '.wp-block-group',
		'.elementor-widget-container', '.elementor-section-wrap',
		'main', 'article', '.hentry', '.singular-content',
		'.post-body', '.article-content', '.content-wrapper',
		'[class*="entry"]', '[class*="content"]', '[class*="post-"]',
	];

	$has_selectors = array_map(
		fn( $sel ) => "body:has(#fb-home-wrap) {$sel}",
		$parents
	);

	$child_selectors = array_map(
		fn( $sel ) => "body:has(#fb-home-wrap) {$sel} > *",
		$parents
	);

	$form_selectors = array_map(
		fn( $sel ) => "body:has(#fb-home-wrap) {$sel} form, body:has(#fb-home-wrap) {$sel} form > div",
		$parents
	);

	return '
/* FB-Home: скидання layout теми на батьківських елементах */
' . implode( ",\n", $has_selectors ) . ' {
	display:              block !important;
	grid-template-columns: unset !important;
	grid-template-rows:    unset !important;
	grid-auto-flow:        unset !important;
	column-count:          1 !important;
	-webkit-column-count:  1 !important;
	columns:               auto !important;
	-webkit-columns:       auto !important;
	font-family:           ' . $font . ' !important;
	letter-spacing:        normal !important;
	word-spacing:          normal !important;
	text-align:            left !important;
}

/* FB-Home: кожна дитина контейнера займає весь рядок */
' . implode( ",\n", $child_selectors ) . ' {
	grid-column:   1 / -1 !important;
	column-span:   all !important;
	-webkit-column-span: all !important;
	break-inside:  avoid !important;
}

/* FB-Home: форма і її діти — суворо block */
' . implode( ",\n", $form_selectors ) . ' {
	display:              block !important;
	grid-template-columns: unset !important;
	column-count:          1 !important;
	float:                 none !important;
	clear:                 both !important;
}

/* FB-Home: label не float, не inline-block від теми */
body:has(#fb-home-wrap) label,
body:has(#fb-home-wrap) form label {
	display:       block !important;
	float:         none !important;
	clear:         both !important;
	width:         100% !important;
	text-align:    left !important;
	font-family:   ' . $font . ' !important;
	letter-spacing: normal !important;
}

/* FB-Home: input — блок, повна ширина */
body:has(#fb-home-wrap) input[type="text"] {
	display:       block !important;
	float:         none !important;
	clear:         both !important;
	width:         100% !important;
	font-family:   ' . $font . ' !important;
	letter-spacing: normal !important;
}

/* FB-Home: виняток — flex-рядок валюти */
#fb-home-wrap .fb-cur-row {
	display:     flex !important;
	flex-flow:   row nowrap !important;
	align-items: stretch !important;
}
#fb-home-wrap .fb-cur-row input[type="text"] {
	width: auto !important;
	clear: none !important;
	float: none !important;
}
';
}

/* =============================================================================
 * 3. БЕЗПЕКА
 * ========================================================================== */

/**
 * Двошарова перевірка AJAX-запиту: авторизація + nonce.
 *
 * @return void
 */
function fb_home_verify_request(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => __( 'Необхідна авторизація.', 'family-budget' ) ], 403 );
	}
	check_ajax_referer( 'fb_home_nonce', 'security' );
}

/* =============================================================================
 * 4. БІЗНЕС-ЛОГІКА
 * ========================================================================== */

/**
 * Повертає кількість родин поточного користувача.
 *
 * @param int $uid Ідентифікатор користувача WordPress.
 * @return int
 */
function fb_home_get_family_count( int $uid ): int {
	global $wpdb;

	return absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(f.id)
				 FROM {$wpdb->prefix}Family AS f
				 JOIN {$wpdb->prefix}UserFamily AS u ON u.Family_ID = f.id
				 WHERE u.User_ID = %d",
				$uid
			)
		)
	);
}

/* =============================================================================
 * 5. ШОРТКОД
 * ========================================================================== */

/**
 * Точка входу шорткоду [fb_home].
 *
 * @return string
 */
function fb_shortcode_home_interface(): string {
	if ( ! is_user_logged_in() ) {
		return '<p style="padding:8px 12px;background:#fff5f5;border:1px solid #feb2b2;color:#c53030;border-radius:7px;font-size:.875rem;">'
			. esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
	}

	$uid = get_current_user_id();

	if ( fb_home_get_family_count( $uid ) > 0 ) {
		$url = esc_url( home_url( '/budget/' ) );
		add_action(
			'wp_footer',
			static function () use ( $url ) {
				printf( '<script>window.location.replace("%s");</script>' . "\n", esc_js( $url ) );
			}
		);
		return '';
	}

	return fb_home_render_form();
}

/* =============================================================================
 * 6. HTML-РЕНДЕРИНГ
 * ========================================================================== */

/**
 * Рендерить онбордингову форму.
 * Кожен елемент має повні inline-стилі для максимальної ізоляції від теми.
 * JS (home.js) додатково фіксує батьків та нащадків.
 *
 * @return string HTML форми.
 */
function fb_home_render_form(): string {

	$font = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';

	// Базовий блоковий рядок стилів.
	$blk = "display:block;float:none;clear:both;width:100%;box-sizing:border-box;font-family:{$font};letter-spacing:normal;word-spacing:normal;";

	// Поле введення.
	$inp = $blk
		. 'height:44px;padding:0 14px;margin:0;'
		. 'font-size:15px;font-weight:400;'
		. 'color:#1a202c;background:#ffffff;'
		. 'border:1.5px solid #cbd5e1;border-radius:8px;'
		. 'line-height:42px;outline:none;'
		. '-webkit-appearance:none;appearance:none;'
		. 'transition:border-color .15s ease,box-shadow .15s ease;';

	// Лейбл.
	$lbl = $blk
		. 'margin:0 0 5px;padding:0;'
		. 'font-size:15px;font-weight:700;'
		. 'color:#1a202c;line-height:1.3;'
		. 'text-align:left;text-transform:none;';

	// Група поля.
	$grp = $blk . 'margin:0 0 16px;padding:0;';

	ob_start();
	?>
<div style="display:block;width:100%;padding:0 16px;box-sizing:border-box;">
<div id="fb-home-wrap"
	style="<?php echo esc_attr( $blk ); ?>max-width:600px;margin:0 auto;padding:0;
		grid-column:1/-1;-ms-grid-column-span:99;
		column-span:all;-webkit-column-span:all;
		contain:layout style;isolation:isolate;position:relative;">

	<?php /* Scoped CSS: лише :focus / .is-invalid / :hover */ ?>
	<style id="fb-home-scoped">
		#fb-home-wrap { all: initial; display: block !important; max-width: 600px !important; margin: 0 auto !important; }
		#fb-home-wrap * { box-sizing: border-box; }
		#fb-home-wrap h2,
		#fb-home-wrap p,
		#fb-home-wrap form,
		#fb-home-wrap form > div { display: block !important; float: none !important; clear: both !important; width: 100% !important; column-count: 1 !important; grid-template-columns: unset !important; }
		#fb-home-wrap label { display: block !important; float: none !important; clear: both !important; width: 100% !important; text-align: left !important; }
		#fb-home-wrap input[type="text"].fb-inp { display: block !important; float: none !important; clear: both !important; width: 100% !important; }
		#fb-home-wrap .fb-cur-row { display: flex !important; flex-flow: row nowrap !important; align-items: stretch !important; }
		#fb-home-wrap .fb-cur-row input[type="text"].fb-inp { flex: 1 1 auto; width: auto !important; clear: none !important; }
		#fb-home-wrap .fb-cur-row .fb-inp-code { flex: 0 0 74px !important; width: 74px !important; min-width: 74px !important; max-width: 74px !important; }
		#fb-home-wrap .fb-cur-row .fb-inp-sym  { flex: 0 0 52px !important; width: 52px !important; min-width: 52px !important; max-width: 52px !important; }
		#fb-home-wrap input.fb-inp:focus { border-color: #4f6bf4 !important; box-shadow: 0 0 0 3px rgba(79,107,244,.18) !important; background: #fff !important; }
		#fb-home-wrap input.fb-inp.is-invalid { border-color: #e53e3e !important; background: #fff5f5 !important; }
		#fb-home-wrap input.fb-inp.is-invalid:focus { box-shadow: 0 0 0 3px rgba(229,62,62,.18) !important; }
		#fb-notice.err { display:block !important; background:#fff5f5; border:1px solid #feb2b2; border-radius:7px; color:#c53030; padding:9px 14px; margin:0 0 16px; font-size:14px; line-height:1.5; }
		#fb-notice.ok  { display:block !important; background:#f0fff4; border:1px solid #9ae6b4; border-radius:7px; color:#276749; padding:9px 14px; margin:0 0 16px; font-size:14px; line-height:1.5; }
		#fb-save:hover:not(:disabled) { background:#3b56e8 !important; }
		#fb-save:active:not(:disabled) { transform:translateY(1px); }
		#fb-save:disabled { opacity:.65 !important; cursor:not-allowed !important; }
		@media(max-width:480px) {
			#fb-home-wrap .fb-cur-row { flex-wrap:wrap !important; }
			#fb-home-wrap .fb-cur-row .fb-inp-code,
			#fb-home-wrap .fb-cur-row .fb-inp-sym { flex:1 1 calc(50% - 4px) !important; width:auto !important; min-width:0 !important; max-width:none !important; }
		}
	</style>

	<?php /* ── Заголовок ── */ ?>
	<h2 style="<?php echo esc_attr( $blk ); ?>margin:0 0 8px;padding:0;font-size:26px;font-weight:800;color:#1a202c;line-height:1.25;text-align:left;">
		🏠 <?php esc_html_e( 'Налаштування родини', 'family-budget' ); ?>
	</h2>

	<?php /* ── Опис ── */ ?>
	<p style="<?php echo esc_attr( $blk ); ?>margin:0 0 16px;padding:0 0 16px;font-size:15px;color:#4a5568;line-height:1.5;border-bottom:1.5px solid #e2e8f0;">
		<?php esc_html_e( 'Заповніть поля нижче, щоб розпочати облік сімейного бюджету.', 'family-budget' ); ?>
	</p>

	<?php /* ── Повідомлення ── */ ?>
	<div id="fb-notice" style="display:none;" role="alert" aria-live="polite"></div>

	<?php /* ── Форма ── */ ?>
	<form id="fb-form" novalidate style="<?php echo esc_attr( $blk ); ?>margin:0;padding:0;">

		<?php /* Родина */ ?>
		<div style="<?php echo esc_attr( $grp ); ?>">
			<label for="fb_family" style="<?php echo esc_attr( $lbl ); ?>">
				<?php esc_html_e( 'Родина', 'family-budget' ); ?><span style="color:#e53e3e;margin-left:4px;" aria-hidden="true">*</span>
			</label>
			<input type="text" id="fb_family" name="family_name" class="fb-inp" required maxlength="50"
				placeholder="<?php esc_attr_e( 'Наприклад: Сім\'я Коваленко', 'family-budget' ); ?>"
				style="<?php echo esc_attr( $inp ); ?>">
		</div>

		<?php /* Валюта */ ?>
		<div style="<?php echo esc_attr( $grp ); ?>">
			<label for="fb_currency" style="<?php echo esc_attr( $lbl ); ?>">
				<?php esc_html_e( 'Валюта', 'family-budget' ); ?><span style="color:#e53e3e;margin-left:4px;" aria-hidden="true">*</span>
			</label>
			<div class="fb-cur-row" style="display:flex;flex-flow:row nowrap;align-items:stretch;gap:8px;width:100%;float:none;clear:both;margin:0;padding:0;box-sizing:border-box;">
				<input type="text" id="fb_currency" name="currency_name" class="fb-inp" required maxlength="50"
					placeholder="<?php esc_attr_e( 'Назва (Гривня)', 'family-budget' ); ?>"
					style="display:block;flex:1 1 auto;min-width:0;height:44px;padding:0 14px;margin:0;font-size:15px;font-family:<?php echo esc_attr( $font ); ?>;font-weight:400;color:#1a202c;background:#fff;border:1.5px solid #cbd5e1;border-radius:8px;line-height:42px;outline:none;-webkit-appearance:none;appearance:none;box-sizing:border-box;letter-spacing:normal;">
				<input type="text" id="fb_code" name="currency_code" class="fb-inp fb-inp-code" maxlength="3"
					placeholder="<?php esc_attr_e( 'UAH', 'family-budget' ); ?>"
					style="display:block;flex:0 0 74px;width:74px;min-width:74px;max-width:74px;height:44px;padding:0 10px;margin:0;font-size:15px;font-family:<?php echo esc_attr( $font ); ?>;font-weight:400;color:#1a202c;background:#fff;border:1.5px solid #cbd5e1;border-radius:8px;line-height:42px;outline:none;text-align:center;-webkit-appearance:none;appearance:none;box-sizing:border-box;letter-spacing:normal;">
				<input type="text" id="fb_symbol" name="currency_symbol" class="fb-inp fb-inp-sym" maxlength="1"
					placeholder="<?php esc_attr_e( '₴', 'family-budget' ); ?>"
					style="display:block;flex:0 0 52px;width:52px;min-width:52px;max-width:52px;height:44px;padding:0 10px;margin:0;font-size:15px;font-family:<?php echo esc_attr( $font ); ?>;font-weight:400;color:#1a202c;background:#fff;border:1.5px solid #cbd5e1;border-radius:8px;line-height:42px;outline:none;text-align:center;-webkit-appearance:none;appearance:none;box-sizing:border-box;letter-spacing:normal;">
			</div>
		</div>

		<?php /* Рахунок */ ?>
		<div style="<?php echo esc_attr( $grp ); ?>">
			<label for="fb_account" style="<?php echo esc_attr( $lbl ); ?>">
				<?php esc_html_e( 'Рахунок', 'family-budget' ); ?><span style="color:#e53e3e;margin-left:4px;" aria-hidden="true">*</span>
			</label>
			<input type="text" id="fb_account" name="account_name" class="fb-inp" required maxlength="50"
				placeholder="<?php esc_attr_e( 'Наприклад: Готівка', 'family-budget' ); ?>"
				style="<?php echo esc_attr( $inp ); ?>">
		</div>

		<?php /* Категорія */ ?>
		<div style="<?php echo esc_attr( $grp ); ?>margin-bottom:24px;">
			<label for="fb_category" style="<?php echo esc_attr( $lbl ); ?>">
				<?php esc_html_e( 'Категорія', 'family-budget' ); ?><span style="color:#e53e3e;margin-left:4px;" aria-hidden="true">*</span>
			</label>
			<input type="text" id="fb_category" name="category_name" class="fb-inp" required maxlength="50"
				placeholder="<?php esc_attr_e( 'Наприклад: Продукти', 'family-budget' ); ?>"
				style="<?php echo esc_attr( $inp ); ?>">
		</div>

		<?php /* Кнопка */ ?>
		<button type="submit" id="fb-save"
			style="display:inline-flex;align-items:center;justify-content:center;gap:8px;height:50px;padding:0 32px;margin:0;background:#4f6bf4;color:#fff;font-size:16px;font-family:<?php echo esc_attr( $font ); ?>;font-weight:700;line-height:1;letter-spacing:.06em;text-transform:uppercase;border:none;border-radius:8px;cursor:pointer;-webkit-appearance:none;appearance:none;transition:background .15s ease,transform .1s ease;float:none;clear:both;">
			<span style="font-size:18px;line-height:1;letter-spacing:normal;" aria-hidden="true">💾</span>
			<span id="fb-save-label" style="letter-spacing:.06em;"><?php esc_html_e( 'ЗБЕРЕГТИ', 'family-budget' ); ?></span>
		</button>

	</form>
</div>
</div><!-- /fb-centering-outer -->
	<?php
	return ob_get_clean();
}

/* =============================================================================
 * 7. AJAX-ОБРОБНИК
 * ========================================================================== */

/**
 * Допоміжна функція: виконує ROLLBACK та надсилає JSON-помилку.
 * Використовує $wpdb->last_error для діагностики SQL-проблем.
 *
 * @param string $message Повідомлення для користувача.
 * @param int    $code    HTTP-код відповіді.
 * @return void
 */
function fb_home_rollback_error( string $message, int $code = 500 ): void {
	global $wpdb;
	$db_err = $wpdb->last_error;
	$wpdb->query( 'ROLLBACK' );
	wp_send_json_error(
		[ 'message' => $message . ( $db_err ? ' | DB: ' . $db_err : '' ) ],
		$code
	);
}

/**
 * Зберігає дані онбордингу в єдиній SQL-транзакції.
 *
 * Послідовність:
 *  1. INSERT Family                   → $family_id = $wpdb->insert_id
 *  2. INSERT UserFamily (uid + fid)   → прив'язка користувача
 *  3. INSERT Currency  (fid)
 *  4. INSERT Account   (fid)
 *  5. INSERT Category  (fid)
 *  → COMMIT
 *
 * При будь-якій помилці → ROLLBACK + wp_send_json_error з текстом DB-помилки.
 *
 * @return void
 */
function fb_ajax_save_onboarding(): void {

	// 1. Безпека: авторизація + nonce.
	fb_home_verify_request();

	// 2. Вмикаємо відображення помилок БД для діагностики.
	global $wpdb;
	$wpdb->show_errors();

	// 3. Санітизація вхідних даних.
	$family_name     = sanitize_text_field( wp_unslash( $_POST['family_name']     ?? '' ) );
	$currency_name   = sanitize_text_field( wp_unslash( $_POST['currency_name']   ?? '' ) );
	$currency_code   = sanitize_text_field( wp_unslash( $_POST['currency_code']   ?? '' ) );
	$currency_symbol = sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ?? '' ) );
	$account_name    = sanitize_text_field( wp_unslash( $_POST['account_name']    ?? '' ) );
	$category_name   = sanitize_text_field( wp_unslash( $_POST['category_name']   ?? '' ) );

	// 4. Валідація обов'язкових полів.
	$required = [
		'family_name'   => $family_name,
		'currency_name' => $currency_name,
		'account_name'  => $account_name,
		'category_name' => $category_name,
	];

	foreach ( $required as $field => $val ) {
		if ( '' === $val ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %s — назва поля */
						__( 'Поле "%s" є обов\'язковим.', 'family-budget' ),
						$field
					),
					'field' => $field,
				],
				422
			);
		}
	}

	$uid = get_current_user_id();
	$now = current_time( 'mysql' );

	// 5. Перевірка: родина вже існує.
	if ( fb_home_get_family_count( $uid ) > 0 ) {
		wp_send_json_error(
			[ 'message' => __( 'Родина для цього користувача вже існує.', 'family-budget' ) ],
			409
		);
	}

	// =========================================================
	// ТРАНЗАКЦІЯ
	// =========================================================
	$wpdb->query( 'START TRANSACTION' );

	// --- КРОК 1: Family ---
	$res = $wpdb->insert(
		$wpdb->prefix . 'Family',
		[
			'Family_Name' => $family_name,
			'created_at'  => $now,
			'updated_at'  => $now,
		],
		[ '%s', '%s', '%s' ]
	);

	if ( ! $res ) {
		fb_home_rollback_error( __( 'Помилка: не вдалося створити родину.', 'family-budget' ) );
	}

	$family_id = (int) $wpdb->insert_id;

	if ( $family_id <= 0 ) {
		fb_home_rollback_error( __( 'Помилка: не отримано ID нової родини.', 'family-budget' ) );
	}

	// --- КРОК 2: UserFamily — прив'язка користувача до родини ---
	$res = $wpdb->insert(
		$wpdb->prefix . 'UserFamily',
		[
			'User_ID'   => $uid,
			'Family_ID' => $family_id,
            'created_at'       => $now,
		],
		[ '%d', '%d', '%s' ]
	);

	if ( ! $res ) {
		fb_home_rollback_error(
			sprintf(
				__( 'Помилка: не вдалося прив\'язати користувача (uid=%d) до родини (fid=%d).', 'family-budget' ),
				$uid,
				$family_id
			)
		);
	}

	// --- КРОК 3: Currency ---
	$res = $wpdb->insert(
		$wpdb->prefix . 'Currency',
		[
			'Family_ID'        => $family_id,
			'Currency_Name'    => $currency_name,
			'Currency_Code'    => $currency_code,
			'Currency_Symbol'  => $currency_symbol,
			'Currency_Primary' => 1,
			'Currency_Order'   => 1,
			'created_at'       => $now,
		],
		[ '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
	);

	if ( ! $res ) {
		fb_home_rollback_error( __( 'Помилка: не вдалося зберегти валюту.', 'family-budget' ) );
	}

	// --- КРОК 4: Account ---
	$res = $wpdb->insert(
		$wpdb->prefix . 'Account',
		[
			'Family_ID'       => $family_id,
			'AccountType_ID'  => 1,
			'Account_Name'    => $account_name,
			'Account_Order'   => 1,
			'Account_Default' => 1,
			'created_at'      => $now,
			'updated_at'      => $now,
		],
		[ '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
	);

	if ( ! $res ) {
		fb_home_rollback_error( __( 'Помилка: не вдалося зберегти рахунок.', 'family-budget' ) );
	}

	// --- КРОК 5: Category ---
	$res = $wpdb->insert(
		$wpdb->prefix . 'Category',
		[
			'Family_ID'       => $family_id,
			'CategoryType_ID' => 1,
			'Category_Name'   => $category_name,
			'Category_Order'  => 1,
			'created_at'      => $now,
			'updated_at'      => $now,
		],
		[ '%d', '%d', '%s', '%d', '%s', '%s' ]
	);

	if ( ! $res ) {
		fb_home_rollback_error( __( 'Помилка: не вдалося зберегти категорію.', 'family-budget' ) );
	}

	// =========================================================
	// COMMIT
	// =========================================================
	$wpdb->query( 'COMMIT' );
	$wpdb->hide_errors();

	wp_send_json_success( [
		'message'    => __( 'Родину успішно створено!', 'family-budget' ),
		'budget_url' => esc_url( home_url( '/budget/' ) ),
		'family_id'  => $family_id,
	] );
}
