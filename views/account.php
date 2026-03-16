<?php
/**
 * Модуль Рахунків (Family Budget)
 *
 * Реалізує повний CRUD для рахунків родини, включаючи:
 *  - Відображення та AJAX-фільтрацію рахунків
 *  - Прив'язку зовнішнього Monobank ID (account_id, міграція v6)
 *  - Прив'язку рахунку до категорії для авто-розподілу (account_category, міграція v7)
 *  - Inline-редагування назви та MonoID
 *  - Drag & Drop сортування через jQuery UI Sortable
 *
 * @package    FamilyBudget
 * @subpackage Modules
 * @version    1.7.0
 * @since      1.0.0
 *
 * CHANGELOG v1.7.0:
 * ================================================
 * [NEW-1] fb_mask_account_id() — безпечний вивід зовнішнього ID (перші/останні 4 символи).
 * [NEW-2] fb_get_accounts_data() — LEFT JOIN account_category + Category (усуває N+1).
 * [NEW-3] fb_shortcode_accounts() — нові колонки MonoID / Категорія, поле в формі,
 *          модальне вікно прив'язки категорії.
 * [NEW-4] fb_ajax_load_accounts() — рендеринг нових колонок, colspan=7.
 * [NEW-5] fb_ajax_add_account() — збереження account_id.
 * [NEW-6] fb_ajax_edit_account() — збереження account_id, повернення masked_id.
 * [NEW-7] fb_ajax_set_account_category() — збереження/зняття прив'язки категорії.
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// БЕЗПЕКА — Двошарова перевірка запитів
// ============================================================================

/**
 * Двошарова перевірка безпеки для всіх AJAX-обробників модуля «Рахунки».
 *
 * Делегує перевірку до fb_verify_ajax_request() (nonce + роль).
 *
 * @since  1.3.4
 * @param  string $action Ім'я nonce-дії WordPress.
 * @return void
 */
function fb_accounts_verify_request( string $action = 'fb_account_nonce' ): void {
	fb_verify_ajax_request( $action );
}

// ============================================================================
// ДОПОМІЖНІ ФУНКЦІЇ
// ============================================================================

/**
 * Маскує зовнішній Monobank ID рахунку для безпечного відображення.
 *
 * Показує перші 4 та останні 4 символи, приховуючи середину.
 * Рядки ≤ 8 символів відображаються повністю.
 * Порожній рядок → «—».
 *
 * @since  1.7.0
 * @param  string $account_id Повний зовнішній ID рахунку.
 * @return string Замаскований рядок (не екранований — екранування при виводі).
 */
function fb_mask_account_id( string $account_id ): string {
	if ( '' === $account_id ) {
		return '—';
	}
	$len = mb_strlen( $account_id );
	if ( $len <= 8 ) {
		return $account_id;
	}
	return mb_substr( $account_id, 0, 4 ) . '…' . mb_substr( $account_id, -4 );
}

// ============================================================================
// ОТРИМАННЯ ДАНИХ
// ============================================================================

/**
 * Повертає масив рахунків поточного користувача з фільтрацією.
 *
 * [PERF] LEFT JOIN account_category + Category усуває N+1 запит:
 *        mapped_category_id та mapped_category_name отримуються за один SELECT.
 *
 * @since  1.0.0
 * @param  int $family_id Фільтр по родині (0 = всі).
 * @param  int $type_id   Фільтр по типу рахунку (0 = всі).
 * @return array Масив об'єктів рахунків або порожній масив.
 */
function fb_get_accounts_data( int $family_id = 0, int $type_id = 0 ): array {
	global $wpdb;

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return [];
	}

	$family_id = absint( $family_id );
	$type_id   = absint( $type_id );

	// [PERF] Єдиний запит: account_id, mapped_category_id, mapped_category_name
	// через LEFT JOIN — без окремих get_var() у циклі.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — складається з підготовлених фрагментів
	$query = "
		SELECT
			a.*,
			f.Family_Name,
			t.AccountType_Name,
			ac.category_id AS mapped_category_id,
			cat.Category_Name AS mapped_category_name
		FROM {$wpdb->prefix}Account AS a
		INNER JOIN {$wpdb->prefix}Family      AS f   ON f.id        = a.Family_ID
		INNER JOIN {$wpdb->prefix}AccountType AS t   ON t.id        = a.AccountType_ID
		INNER JOIN {$wpdb->prefix}UserFamily  AS u   ON u.Family_ID = f.id
		LEFT  JOIN {$wpdb->prefix}account_category AS ac  ON ac.account_id  = a.id
		LEFT  JOIN {$wpdb->prefix}Category         AS cat ON cat.id          = ac.category_id
		WHERE u.User_ID = %d
	";

	$args = [ $user_id ];

	if ( $family_id > 0 ) {
		$query .= ' AND a.Family_ID = %d';
		$args[] = $family_id;
	}

	if ( $type_id > 0 ) {
		$query .= ' AND a.AccountType_ID = %d';
		$args[] = $type_id;
	}

	$query .= ' ORDER BY a.Account_Default DESC, a.Account_Order DESC';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return (array) $wpdb->get_results( $wpdb->prepare( $query, $args ) );
}

// ============================================================================
// SHORTCODE — Головний шаблон модуля
// ============================================================================

/**
 * Шорткод [fb_accounts] — рендерить повний інтерфейс управління рахунками.
 *
 * Підключає зовнішні CSS/JS, передає категорії та i18n рядки через
 * wp_localize_script. Модальне вікно прив'язки категорії рендериться
 * поза таблицею для коректного позиціонування.
 *
 * @since  1.0.0
 * @return string HTML-вміст шорткоду.
 */
function fb_shortcode_accounts(): string {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Будь ласка, увійдіть в систему.', 'family-budget' ) . '</p>';
	}

	global $wpdb;

	$user_id      = get_current_user_id();
	$families     = function_exists( 'fb_get_families' )         ? fb_get_families()         : [];
	$filter_types = function_exists( 'fb_get_account_type' )     ? fb_get_account_type()     : [];
	$add_types    = function_exists( 'fb_get_all_account_type' ) ? fb_get_all_account_type() : [];

	// Завантажуємо категорії поточного користувача для модального вікна.
	// [SEC] Ізоляція через CategoryType.Family_ID → UserFamily.
	// [FIX] Category не має поля Family_ID — зв'язок тільки через CategoryType.
	$categories = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.id, c.Category_Name
			 FROM {$wpdb->prefix}Category AS c
			 INNER JOIN {$wpdb->prefix}CategoryType AS t ON t.id = c.CategoryType_ID
			 WHERE t.Family_ID IN (
			     SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d
			 )
			 ORDER BY c.Category_Name ASC",
			$user_id
		)
	);

	wp_enqueue_style(
		'fb-account-css',
		FB_PLUGIN_URL . 'css/account.css',
		[],
		'1.7.0'
	);

	wp_enqueue_script( 'jquery-ui-sortable' );

	wp_enqueue_script(
		'fb-account-js',
		FB_PLUGIN_URL . 'js/account.js',
		[ 'jquery', 'jquery-ui-sortable' ],
		'1.7.0',
		true
	);

	// Передаємо дані до JS: AJAX URL, nonce, категорії, i18n.
	wp_localize_script(
		'fb-account-js',
		'fbAccountObj',
		[
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'fb_account_nonce' ),
			'confirm'    => esc_html__( 'Ви впевнені, що хочете видалити цей рахунок?', 'family-budget' ),
			// Масив категорій для динамічного заповнення модального select-у.
			'categories' => array_map(
				static function ( $cat ) {
					return [
						'id'   => absint( $cat->id ),
						'name' => esc_html( $cat->Category_Name ),
					];
				},
				$categories
			),
			'i18n'       => [
				'noCat'      => esc_html__( '— Без категорії —', 'family-budget' ),
				'catError'   => esc_html__( 'Помилка збереження категорії.', 'family-budget' ),
				'netError'   => esc_html__( "Помилка з'єднання з сервером.", 'family-budget' ),
				'emptyName'  => esc_html__( 'Назва рахунку не може бути порожньою.', 'family-budget' ),
				'addError'   => esc_html__( 'Сталася помилка при додаванні.', 'family-budget' ),
				'saveError'  => esc_html__( 'Помилка збереження.', 'family-budget' ),
				'delError'   => esc_html__( 'Помилка видалення.', 'family-budget' ),
				'loadError'  => esc_html__( 'Помилка завантаження даних.', 'family-budget' ),
				'saving'     => esc_html__( 'Збереження…', 'family-budget' ),
			],
		]
	);

	ob_start();
	?>
	<div class="fb-accounts-wrapper">

		<!-- ══ Панель фільтрів + форма додавання ══════════════════════════════ -->
		<div class="fb-accounts-controls">

			<?php /* ── Фільтри ── */ ?>
			<div class="fb-filter-group">
				<select id="fb-filter-family" class="fb-compact-input">
					<option value="0"><?php esc_html_e( 'Всі родини', 'family-budget' ); ?></option>
					<?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
						$f_id   = fb_extract_value( $f, [ 'id', 'ID' ] );
						$f_name = fb_extract_value( $f, [ 'Family_Name', 'name', 'Name' ] );
						?>
						<option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
					<?php endforeach; endif; ?>
				</select>

				<select id="fb-filter-type" class="fb-compact-input">
					<option value="0"><?php esc_html_e( 'Всі типи', 'family-budget' ); ?></option>
					<?php if ( ! empty( $filter_types ) ) : foreach ( $filter_types as $ft ) :
						$ft_id   = fb_extract_value( $ft, [ 'id', 'ID' ] );
						$ft_name = fb_extract_value( $ft, [ 'AccountType_Name', 'name', 'Name' ] );
						?>
						<option value="<?php echo esc_attr( $ft_id ); ?>"><?php echo esc_html( $ft_name ); ?></option>
					<?php endforeach; endif; ?>
				</select>
			</div>
			<?php /* / .fb-filter-group */ ?>

			<?php /* ── Форма додавання рахунку ── */ ?>
			<form id="fb-add-account-form" class="fb-add-group">

				<select name="family_id" required class="fb-compact-input">
					<option value="" disabled selected><?php esc_html_e( 'Оберіть родину', 'family-budget' ); ?></option>
					<?php if ( ! empty( $families ) ) : foreach ( $families as $f ) :
						$f_id   = fb_extract_value( $f, [ 'id', 'ID' ] );
						$f_name = fb_extract_value( $f, [ 'Family_Name', 'name', 'Name' ] );
						?>
						<option value="<?php echo esc_attr( $f_id ); ?>"><?php echo esc_html( $f_name ); ?></option>
					<?php endforeach; endif; ?>
				</select>

				<select name="type_id" required class="fb-compact-input">
					<option value="" disabled selected><?php esc_html_e( 'Оберіть тип', 'family-budget' ); ?></option>
					<?php if ( ! empty( $add_types ) ) : foreach ( $add_types as $at ) :
						$at_id   = fb_extract_value( $at, [ 'id', 'ID' ] );
						$at_name = fb_extract_value( $at, [ 'AccountType_Name', 'name', 'Name', 'title', 'category_name' ] );
						?>
						<option value="<?php echo esc_attr( $at_id ); ?>"><?php echo esc_html( $at_name ); ?></option>
					<?php endforeach; endif; ?>
				</select>

				<input type="text" name="account_name" required
				       placeholder="<?php esc_attr_e( 'Назва рахунку', 'family-budget' ); ?>"
				       class="fb-compact-input">

				<?php /* [NEW-1] Поле MonoID у формі додавання (необов'язкове) */ ?>
				<input type="text" name="account_id"
				       placeholder="<?php esc_attr_e( 'Monobank ID', 'family-budget' ); ?>"
				       class="fb-compact-input fb-compact-mono"
				       maxlength="50"
				       aria-label="<?php esc_attr_e( 'Зовнішній ID рахунку Monobank', 'family-budget' ); ?>">

				<button type="submit" class="fb-btn-primary">
					<?php esc_html_e( 'Додати', 'family-budget' ); ?>
				</button>

			</form>
			<?php /* / #fb-add-account-form */ ?>

		</div>
		<?php /* / .fb-accounts-controls */ ?>

		<!-- ══ Таблиця рахунків ═══════════════════════════════════════════════ -->
		<div class="fb-accounts-table-container">
			<table class="fb-table">
				<thead>
					<tr>
						<th width="30"></th>
						<th><?php esc_html_e( 'Родина', 'family-budget' ); ?></th>
						<th><?php esc_html_e( 'Тип', 'family-budget' ); ?></th>
						<th><?php esc_html_e( 'Назва рахунку', 'family-budget' ); ?></th>
						<th class="fb-col-mono"><?php esc_html_e( 'MonoID', 'family-budget' ); ?></th>
						<th class="fb-col-cat"><?php esc_html_e( 'Категорія', 'family-budget' ); ?></th>
						<th width="120" class="text-center"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
					</tr>
				</thead>
				<tbody id="fb-accounts-tbody">
					<?php /* Заповнюється через AJAX при завантаженні */ ?>
				</tbody>
			</table>
		</div>
		<?php /* / .fb-accounts-table-container */ ?>

	</div>
	<?php /* / .fb-accounts-wrapper */ ?>

	<!-- ══ Модальне вікно: прив'язка категорії до рахунку ════════════════════ -->
	<div id="fb-acc-cat-modal" class="fb-acc-modal" role="dialog"
	     aria-modal="true" aria-labelledby="fb-acc-cat-modal-title" aria-hidden="true">
		<div class="fb-acc-modal-overlay"></div>
		<div class="fb-acc-modal-content">

			<h4 id="fb-acc-cat-modal-title" class="fb-acc-modal-title">
				<?php esc_html_e( "Прив'язати категорію", 'family-budget' ); ?>
			</h4>

			<input type="hidden" id="fb-cat-modal-account-id">

			<div class="fb-acc-modal-field">
				<label for="fb-cat-modal-select">
					<?php esc_html_e( 'Категорія', 'family-budget' ); ?>
				</label>
				<select id="fb-cat-modal-select" class="fb-compact-input fb-cat-modal-select">
					<?php /* Заповнюється через JS з fbAccountObj.categories */ ?>
				</select>
			</div>

			<div class="fb-acc-modal-actions">
				<button type="button" id="fb-cat-modal-save" class="fb-btn-primary">
					<?php esc_html_e( 'Зберегти', 'family-budget' ); ?>
				</button>
				<button type="button" id="fb-cat-modal-close" class="fb-btn-cancel">
					<?php esc_html_e( 'Скасувати', 'family-budget' ); ?>
				</button>
			</div>

		</div>
	</div>
	<?php /* / #fb-acc-cat-modal */ ?>

	<?php
	return ob_get_clean();
}
add_shortcode( 'fb_accounts', 'fb_shortcode_accounts' );

// ============================================================================
// AJAX: LOAD ACCOUNTS — Завантаження таблиці
// ============================================================================

add_action( 'wp_ajax_fb_load_accounts', 'fb_ajax_load_accounts' );

/**
 * AJAX: Завантаження HTML-рядків таблиці рахунків із фільтрацією.
 *
 * [PERF] Використовує fb_get_accounts_data() з LEFT JOIN — без N+1.
 * [NEW-4] Рендерить нові колонки MonoID (маскований) та Категорія.
 *
 * @since  1.0.0
 * @return void Повертає JSON з полем html.
 */
function fb_ajax_load_accounts(): void {
	fb_accounts_verify_request();

	$family_id = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
	$type_id   = isset( $_POST['type_id'] )   ? absint( wp_unslash( $_POST['type_id'] ) )   : 0;

	$accounts = fb_get_accounts_data( $family_id, $type_id );

	ob_start();

	if ( empty( $accounts ) ) {
		// colspan=7 відповідає кількості нових колонок таблиці.
		echo '<tr><td colspan="7" class="text-center">'
			. esc_html__( 'Записів не знайдено', 'family-budget' )
			. '</td></tr>';
	} else {
		foreach ( $accounts as $acc ) {
			$is_default   = 1 === (int) $acc->Account_Default;
			$star_class   = $is_default ? 'fb-star is-default' : 'fb-star';
			$family_name  = ! empty( $acc->Family_Name )      ? $acc->Family_Name      : '—';
			$type_name    = ! empty( $acc->AccountType_Name ) ? $acc->AccountType_Name : '—';

			// [NEW-1] Маскуємо зовнішній MonoID.
			$raw_mono     = $acc->account_id ?? '';
			$masked_mono  = fb_mask_account_id( $raw_mono );

			// [NEW-2] Прив'язана категорія (якщо є).
			$cat_id       = absint( $acc->mapped_category_id ?? 0 );
			$cat_name     = ! empty( $acc->mapped_category_name ) ? $acc->mapped_category_name : '';
			?>
			<tr data-id="<?php echo esc_attr( $acc->id ); ?>"
			    data-category-id="<?php echo esc_attr( $cat_id ); ?>">

				<td class="fb-drag-handle" aria-hidden="true">☰</td>

				<td><?php echo esc_html( $family_name ); ?></td>

				<td><?php echo esc_html( $type_name ); ?></td>

				<?php /* Назва рахунку: текст / inline-input (toggle) */ ?>
				<td class="fb-name-col">
					<span class="fb-acc-name-text"><?php echo esc_html( $acc->Account_Name ); ?></span>
					<input type="text" class="fb-acc-name-input hidden"
					       value="<?php echo esc_attr( $acc->Account_Name ); ?>"
					       aria-label="<?php esc_attr_e( 'Назва рахунку', 'family-budget' ); ?>">
				</td>

				<?php /* [NEW-1] MonoID: маскований текст / повний inline-input (toggle) */ ?>
				<td class="fb-mono-col">
					<span class="fb-acc-mono-text fb-mono-badge"
					      title="<?php echo '' !== $raw_mono ? esc_attr__( 'Натисніть ✎ щоб редагувати', 'family-budget' ) : ''; ?>">
						<?php echo esc_html( $masked_mono ); ?>
					</span>
					<input type="text" class="fb-acc-mono-input hidden"
					       value="<?php echo esc_attr( $raw_mono ); ?>"
					       placeholder="<?php esc_attr_e( 'Monobank ID', 'family-budget' ); ?>"
					       maxlength="50"
					       aria-label="<?php esc_attr_e( 'Зовнішній ID Monobank', 'family-budget' ); ?>">
				</td>

				<?php /* [NEW-2] Прив'язана категорія + кнопка ⚙️ */ ?>
				<td class="fb-cat-col">
					<span class="fb-acc-cat-name">
						<?php echo '' !== $cat_name ? esc_html( $cat_name ) : '<span class="fb-cat-empty">—</span>'; ?>
					</span>
				</td>

				<?php /* Дії */ ?>
				<td class="fb-actions text-center">
					<span class="<?php echo esc_attr( $star_class ); ?>"
					      title="<?php esc_attr_e( 'Головна', 'family-budget' ); ?>">★</span>

					<span class="fb-edit-btn"
					      title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">✎</span>

					<span class="fb-save-btn hidden"
					      title="<?php esc_attr_e( 'Зберегти', 'family-budget' ); ?>">✔</span>

					<?php /* [NEW-3] Кнопка прив'язки категорії */ ?>
					<span class="fb-cat-btn"
					      title="<?php esc_attr_e( 'Категорія', 'family-budget' ); ?>"
					      aria-label="<?php esc_attr_e( 'Прив\'язати категорію', 'family-budget' ); ?>">⚙️</span>

					<span class="fb-delete-btn"
					      title="<?php esc_attr_e( 'Видалити', 'family-budget' ); ?>">🗑</span>
				</td>

			</tr>
			<?php
		}
	}

	$html = ob_get_clean();
	wp_send_json_success( [ 'html' => $html ] );
}

// ============================================================================
// AJAX: ADD ACCOUNT — Додавання рахунку
// ============================================================================

add_action( 'wp_ajax_fb_add_account', 'fb_ajax_add_account' );

/**
 * AJAX: Додавання нового рахунку.
 *
 * [NEW-5] Зберігає account_id (зовнішній Monobank ID) якщо переданий.
 * [SEC]  Перевірка доступу до родини через UserFamily перед INSERT.
 *
 * @since  1.0.0
 * @return void Повертає JSON-відповідь.
 */
function fb_ajax_add_account(): void {
	fb_accounts_verify_request();
	global $wpdb;

	$user_id    = get_current_user_id();
	$family_id  = isset( $_POST['family_id'] )    ? absint( wp_unslash( $_POST['family_id'] ) )                             : 0;
	$type_id    = isset( $_POST['type_id'] )      ? absint( wp_unslash( $_POST['type_id'] ) )                               : 0;
	$name       = isset( $_POST['account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['account_name'] ) )             : '';
	$account_id = isset( $_POST['account_id'] )   ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) )               : '';

	// [SEC] Перевірка: чи belongs поточний user до зазначеної родини.
	$has_access = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
		$user_id,
		$family_id
	) );

	if ( ! $has_access ) {
		wp_send_json_error( [ 'message' => __( 'Немає доступу до обраної родини.', 'family-budget' ) ] );
	}

	if ( '' === $name ) {
		wp_send_json_error( [ 'message' => __( "Назва рахунку обов'язкова.", 'family-budget' ) ] );
	}

	// Визначаємо наступний порядковий номер у родині.
	$max_order = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT MAX(Account_Order) FROM {$wpdb->prefix}Account WHERE Family_ID = %d",
		$family_id
	) );
	$new_order = $max_order + 1;

	$insert_data   = [
		'Family_ID'      => $family_id,
		'AccountType_ID' => $type_id,
		'Account_Name'   => $name,
		'Account_Order'  => $new_order,
		'created_at'     => current_time( 'mysql' ),
		'updated_at'     => current_time( 'mysql' ),
	];
	$insert_format = [ '%d', '%d', '%s', '%d', '%s', '%s' ];

	// [NEW-5] Зберігаємо account_id лише якщо не порожній.
	if ( '' !== $account_id ) {
		$insert_data['account_id']   = $account_id;
		$insert_format[]             = '%s';
	}

	$result = $wpdb->insert(
		$wpdb->prefix . 'Account',
		$insert_data,
		$insert_format
	);

	if ( false === $result ) {
		error_log( sprintf(
			'FB Account Insert Error: %s | User: %d',
			$wpdb->last_error,
			$user_id
		) );
		wp_send_json_error( [ 'message' => __( 'Помилка бази даних. Спробуйте ще раз.', 'family-budget' ) ] );
	}

	wp_send_json_success( [ 'message' => __( 'Рахунок додано успішно.', 'family-budget' ) ] );
}

// ============================================================================
// AJAX: EDIT ACCOUNT — Inline-редагування назви + MonoID
// ============================================================================

add_action( 'wp_ajax_fb_edit_account', 'fb_ajax_edit_account' );

/**
 * AJAX: Оновлення назви та/або зовнішнього MonoID рахунку.
 *
 * [NEW-6] Приймає account_id (MonoID), зберігає в БД.
 *         Повертає masked_id для оновлення відображення без перезавантаження.
 * [SEC]   Перевірка доступу через UserFamily JOIN перед UPDATE.
 *
 * @since  1.0.0
 * @return void Повертає JSON-відповідь з masked_id.
 */
function fb_ajax_edit_account(): void {
	fb_accounts_verify_request();
	global $wpdb;

	$id         = isset( $_POST['id'] )         ? absint( wp_unslash( $_POST['id'] ) )                           : 0;
	$name       = isset( $_POST['name'] )       ? sanitize_text_field( wp_unslash( $_POST['name'] ) )            : '';
	$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) )      : null;
	$user_id    = get_current_user_id();

	if ( ! $id ) {
		wp_send_json_error( [ 'message' => __( 'Невірний ID рахунку.', 'family-budget' ) ] );
	}

	if ( '' === $name ) {
		wp_send_json_error( [ 'message' => __( 'Назва не може бути порожньою.', 'family-budget' ) ] );
	}

	// [SEC] Перевірка доступу через UserFamily.
	$account = $wpdb->get_row( $wpdb->prepare(
		"SELECT Family_ID FROM {$wpdb->prefix}Account WHERE id = %d",
		$id
	) );

	if ( ! $account ) {
		wp_send_json_error( [ 'message' => __( 'Рахунок не знайдено.', 'family-budget' ) ] );
	}

	$has_access = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
		$user_id,
		$account->Family_ID
	) );

	if ( ! $has_access ) {
		wp_send_json_error( [ 'message' => __( 'Доступ заборонено.', 'family-budget' ) ] );
	}

	// Формуємо дані для оновлення.
	$update_data   = [
		'Account_Name' => $name,
		'updated_at'   => current_time( 'mysql' ),
	];
	$update_format = [ '%s', '%s' ];

	// account_id: null означає "поле не передане — не змінюємо".
	// Порожній рядок означає "очистити MonoID".
	if ( null !== $account_id ) {
		$update_data['account_id'] = '' !== $account_id ? $account_id : null;
		$update_format[]           = '%s';
	}

	$result = $wpdb->update(
		$wpdb->prefix . 'Account',
		$update_data,
		[ 'id' => $id ],
		$update_format,
		[ '%d' ]
	);

	if ( false === $result ) {
		error_log( sprintf(
			'FB Account Update Error: %s | ID: %d | User: %d',
			$wpdb->last_error,
			$id,
			$user_id
		) );
		wp_send_json_error( [ 'message' => __( 'Помилка оновлення. Спробуйте ще раз.', 'family-budget' ) ] );
	}

	// [NEW-6] Повертаємо замаскований MonoID для миттєвого оновлення в DOM.
	$display_mono = null !== $account_id ? fb_mask_account_id( $account_id ) : null;

	wp_send_json_success( [
		'masked_id' => $display_mono,
	] );
}

// ============================================================================
// AJAX: SET ACCOUNT CATEGORY — Прив'язка категорії до рахунку
// ============================================================================

add_action( 'wp_ajax_fb_set_account_category', 'fb_ajax_set_account_category' );

/**
 * AJAX: Зберігає або видаляє прив'язку рахунку до категорії (таблиця account_category).
 *
 * Стратегія: $wpdb->replace() для INSERT/UPDATE (UNIQUE KEY по account_id).
 *            Якщо category_id = 0 — видаляємо запис (скасовуємо прив'язку).
 * [SEC]  Перевірка доступу до рахунку через UserFamily JOIN.
 *
 * @since  1.7.0
 * @return void Повертає JSON-відповідь з category_name.
 */
function fb_ajax_set_account_category(): void {
	fb_accounts_verify_request();
	global $wpdb;

	$account_db_id = isset( $_POST['id'] )          ? absint( wp_unslash( $_POST['id'] ) )          : 0;
	$category_id   = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;
	$user_id       = get_current_user_id();

	if ( ! $account_db_id ) {
		wp_send_json_error( [ 'message' => __( 'Невірний ID рахунку.', 'family-budget' ) ] );
	}

	// [SEC] Перевіряємо: рахунок існує та belongs до родини користувача.
	$account = $wpdb->get_row( $wpdb->prepare(
		"SELECT Family_ID FROM {$wpdb->prefix}Account WHERE id = %d",
		$account_db_id
	) );

	if ( ! $account ) {
		wp_send_json_error( [ 'message' => __( 'Рахунок не знайдено.', 'family-budget' ) ] );
	}

	$has_access = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
		$user_id,
		$account->Family_ID
	) );

	if ( ! $has_access ) {
		wp_send_json_error( [ 'message' => __( 'Доступ заборонено.', 'family-budget' ) ] );
	}

	if ( $category_id > 0 ) {
		// INSERT або UPDATE (UNIQUE KEY unique_account — account_id є унікальним).
		$result = $wpdb->replace(
			$wpdb->prefix . 'account_category',
			[
				'account_id'  => $account_db_id,
				'category_id' => $category_id,
			],
			[ '%d', '%d' ]
		);

		if ( false === $result ) {
			error_log( sprintf(
				'FB account_category replace error: %s | account: %d | cat: %d',
				$wpdb->last_error,
				$account_db_id,
				$category_id
			) );
			wp_send_json_error( [ 'message' => __( 'Помилка збереження: ', 'family-budget' ) . $wpdb->last_error ] );
		}

		// Повертаємо ім'я категорії для миттєвого оновлення в DOM.
		$cat_name = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT Category_Name FROM {$wpdb->prefix}Category WHERE id = %d",
			$category_id
		) );

		wp_send_json_success( [
			'category_id'   => $category_id,
			'category_name' => $cat_name,
		] );

	} else {
		// category_id = 0 → знімаємо прив'язку.
		$wpdb->delete(
			$wpdb->prefix . 'account_category',
			[ 'account_id' => $account_db_id ],
			[ '%d' ]
		);

		wp_send_json_success( [
			'category_id'   => 0,
			'category_name' => '',
		] );
	}
}

// ============================================================================
// AJAX: SET DEFAULT — Встановлення головного рахунку
// ============================================================================

add_action( 'wp_ajax_fb_set_default_account', 'fb_ajax_set_default_account' );

/**
 * AJAX: Встановлення рахунку як «Головного» в родині.
 *
 * [SEC] Перевірка доступу через UserFamily перед UPDATE.
 *
 * @since  1.0.0
 * @return void Повертає JSON-відповідь.
 */
function fb_ajax_set_default_account(): void {
	fb_accounts_verify_request();
	global $wpdb;

	$id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
	$user_id = get_current_user_id();

	$account = $wpdb->get_row( $wpdb->prepare(
		"SELECT Family_ID FROM {$wpdb->prefix}Account WHERE id = %d",
		$id
	) );

	if ( ! $account ) {
		wp_send_json_error( [ 'message' => __( 'Рахунок не знайдено.', 'family-budget' ) ] );
	}

	$has_access = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
		$user_id,
		$account->Family_ID
	) );

	if ( ! $has_access ) {
		wp_send_json_error( [ 'message' => __( 'Помилка доступу.', 'family-budget' ) ] );
	}

	// Скидаємо флаг для всіх рахунків родини, потім ставимо обраному.
	$wpdb->update(
		$wpdb->prefix . 'Account',
		[ 'Account_Default' => 0 ],
		[ 'Family_ID' => $account->Family_ID ],
		[ '%d' ],
		[ '%d' ]
	);
	$wpdb->update(
		$wpdb->prefix . 'Account',
		[ 'Account_Default' => 1 ],
		[ 'id' => $id ],
		[ '%d' ],
		[ '%d' ]
	);

	wp_send_json_success();
}

// ============================================================================
// AJAX: DELETE ACCOUNT — Видалення рахунку
// ============================================================================

add_action( 'wp_ajax_fb_delete_account', 'fb_ajax_delete_account' );

/**
 * AJAX: Видалення рахунку після перевірки доступу.
 *
 * Запис у account_category видаляється автоматично через ON DELETE CASCADE (migration v7).
 * [SEC] Перевірка UserFamily перед DELETE.
 *
 * @since  1.0.0
 * @return void Повертає JSON-відповідь.
 */
function fb_ajax_delete_account(): void {
	fb_accounts_verify_request();
	global $wpdb;

	$id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
	$user_id = get_current_user_id();

	$account = $wpdb->get_row( $wpdb->prepare(
		"SELECT Family_ID FROM {$wpdb->prefix}Account WHERE id = %d",
		$id
	) );

	if ( ! $account ) {
		wp_send_json_error( [ 'message' => __( 'Рахунок не знайдено.', 'family-budget' ) ] );
	}

	$has_access = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
		$user_id,
		$account->Family_ID
	) );

	if ( ! $has_access ) {
		wp_send_json_error( [ 'message' => __( 'Неможливо видалити рахунок.', 'family-budget' ) ] );
	}

	$wpdb->delete( $wpdb->prefix . 'Account', [ 'id' => $id ], [ '%d' ] );
	wp_send_json_success();
}

// ============================================================================
// AJAX: REORDER ACCOUNTS — Drag & Drop сортування
// ============================================================================

add_action( 'wp_ajax_fb_reorder_accounts', 'fb_ajax_reorder_accounts' );

/**
 * AJAX: Оновлення порядку рахунків після Drag & Drop.
 *
 * @since  1.0.0
 * @param  array $_POST['order'] Впорядкований масив ID рахунків.
 * @return void Повертає JSON-відповідь.
 */
function fb_ajax_reorder_accounts(): void {
	fb_accounts_verify_request();
	global $wpdb;

	if ( ! isset( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
		wp_send_json_error();
	}

	$order = array_map( 'absint', (array) wp_unslash( $_POST['order'] ) );

	foreach ( $order as $index => $id ) {
		if ( ! $id ) {
			continue;
		}
		$wpdb->update(
			$wpdb->prefix . 'Account',
			[ 'Account_Order' => $index + 1 ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	wp_send_json_success();
}
