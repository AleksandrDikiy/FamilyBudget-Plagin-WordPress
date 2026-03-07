<?php
/**
 * Модуль Родини — Управління сімейними групами
 *
 * Надає CRUD-операції для таблиці Family та управління членством:
 *  - Створення нової родини з автоматичним прив'язуванням поточного користувача
 *  - AJAX-редагування назви родини (inline)
 *  - AJAX-видалення родини (з перевіркою наявних записів)
 *  - AJAX-додавання користувача до родини через модальне вікно
 *  - AJAX-відображення учасників обраної родини
 *  - AJAX-видалення користувача з родини
 *
 * Доступ за ролями:
 *  - fb_payment, fb_admin, manage_options → створення, редагування, видалення
 *  - fb_user, fb_payment, fb_admin       → додавання користувача до родини
 *
 * @package    FamilyBudget
 * @subpackage Modules
 * @version    1.0.0
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Заборона прямого доступу до файлу.
}

// ============================================================================
// ENQUEUE — Підключення зовнішніх скриптів та стилів
// ============================================================================

add_action( 'wp_enqueue_scripts', 'fb_family_enqueue_scripts' );

/**
 * Реєстрація та підключення зовнішніх ресурсів модуля «Родини».
 *
 * Підключає css/family.css та js/family.js лише для авторизованих.
 * Рядки UI передаються через fbFamilyI18n, конфіг — через fbFamilyData.
 *
 * @since  1.0.0
 * @return void
 */
function fb_family_enqueue_scripts(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$plugin_url = plugin_dir_url( __FILE__ );
	$version    = '1.0.0';

	wp_enqueue_style(
		'fb-family-style',
		$plugin_url . 'css/family.css',
		array(),
		$version
	);

	wp_enqueue_script(
		'fb-family-script',
		$plugin_url . 'js/family.js',
		array( 'jquery' ),
		$version,
		true
	);

	// Конфігурація: AJAX URL та nonce для запитів модуля.
	wp_localize_script(
		'fb-family-script',
		'fbFamilyData',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fb_family_nonce' ),
		)
	);

	// Рядки UI для JavaScript-повідомлень.
	wp_localize_script(
		'fb-family-script',
		'fbFamilyI18n',
		array(
			'networkError'      => __( 'Помилка мережі. Спробуйте ще раз.', 'family-budget' ),
			'saveError'         => __( 'Помилка збереження.', 'family-budget' ),
			'deleteConfirm'     => __( 'Ви впевнені, що хочете видалити цю родину?', 'family-budget' ),
			'deleteBlocked'     => __( 'Видалення неможливе: родина має пов\'язані записи або це ваша єдина родина.', 'family-budget' ),
			'saving'            => __( 'Збереження...', 'family-budget' ),
			'userNotFound'      => __( 'Користувача не знайдено.', 'family-budget' ),
			'selectFamily'      => __( 'Оберіть родину для перегляду учасників.', 'family-budget' ),
			'noMembers'         => __( 'Учасників не знайдено.', 'family-budget' ),
			'removeConfirm'     => __( 'Видалити цього користувача з родини?', 'family-budget' ),
			'namePlaceholder'   => __( 'Назва родини (наприклад: Сім\'я Шевченків)', 'family-budget' ),
		)
	);
}

// ============================================================================
// SECURITY HELPERS — Помічники перевірки доступу
// ============================================================================

/**
 * Перевіряє, чи має поточний користувач право управляти родинами.
 *
 * Права управління мають ролі: fb_payment, fb_admin та адміністратори WP.
 *
 * @since  1.0.0
 * @return bool true — є права, false — немає.
 */
function fb_user_can_manage_family(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$user = wp_get_current_user();
	return array_intersect( array( 'fb_payment', 'fb_admin' ), (array) $user->roles ) !== array()
		|| current_user_can( 'manage_options' );
}

/**
 * Перевіряє, чи має поточний користувач право додавати учасників до родини.
 *
 * Мінімальна роль: fb_user, fb_payment, fb_admin або WP-адмін.
 *
 * @since  1.0.0
 * @return bool true — є права, false — немає.
 */
function fb_user_can_invite_to_family(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$user = wp_get_current_user();
	return array_intersect( array( 'fb_user', 'fb_payment', 'fb_admin' ), (array) $user->roles ) !== array()
		|| current_user_can( 'manage_options' );
}

/**
 * Двошарова перевірка безпеки для AJAX-обробників модуля «Родини».
 *
 * Перевіряє: nonce → авторизацію → роль користувача.
 * При невдачі одразу завершує виконання через wp_send_json_error().
 *
 * @since  1.0.0
 * @param  bool $require_manage true — вимагати роль управління; false — достатньо fb_user.
 * @return void
 */
function fb_family_verify_request( bool $require_manage = true ): void {
	check_ajax_referer( 'fb_family_nonce', 'security' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( __( 'Необхідна аутентифікація.', 'family-budget' ) );
	}

	if ( $require_manage && ! fb_user_can_manage_family() ) {
		wp_send_json_error( __( 'Недостатньо прав для виконання цієї дії.', 'family-budget' ) );
	}

	if ( ! $require_manage && ! fb_user_can_invite_to_family() ) {
		wp_send_json_error( __( 'Недостатньо прав для виконання цієї дії.', 'family-budget' ) );
	}
}

// ============================================================================
// AJAX: CREATE FAMILY — Створення нової родини
// ============================================================================

add_action( 'wp_ajax_fb_create_family', 'fb_ajax_create_family' );

/**
 * AJAX: Створення нової родини з автоматичним прив'язуванням поточного користувача.
 *
 * Алгоритм:
 *  1. Двошарова перевірка безпеки (nonce + роль).
 *  2. Валідація та санітизація назви родини.
 *  3. INSERT у таблицю Family.
 *  4. INSERT у таблицю UserFamily (прив'язка поточного юзера).
 *
 * @since  1.0.0
 * @return void JSON-відповідь з ID та назвою нової родини.
 */
function fb_ajax_create_family(): void {
	fb_family_verify_request( true );

	$family_name = isset( $_POST['family_name'] )
		? sanitize_text_field( wp_unslash( $_POST['family_name'] ) )
		: '';

	if ( '' === $family_name ) {
		wp_send_json_error( __( 'Назва родини не може бути порожньою.', 'family-budget' ) );
	}

	if ( mb_strlen( $family_name ) > 50 ) {
		wp_send_json_error( __( 'Назва родини не може перевищувати 50 символів.', 'family-budget' ) );
	}

	global $wpdb;
	$user_id = get_current_user_id();

	// Вставляємо нову родину.
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'Family',
		array(
			'Family_Name' => $family_name,
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		error_log( sprintf( 'FB Family Create Error: %s | Користувач: %d', $wpdb->last_error, $user_id ) );
		wp_send_json_error( __( 'Помилка створення родини. Спробуйте ще раз.', 'family-budget' ) );
	}

	$family_id = $wpdb->insert_id;

	// Автоматично прив'язуємо поточного користувача до нової родини.
	$linked = $wpdb->insert(
		$wpdb->prefix . 'UserFamily',
		array(
			'User_ID'    => $user_id,
			'Family_ID'  => $family_id,
			'created_at' => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s' )
	);

	if ( false === $linked ) {
		// Родину створено, але прив'язка не вдалась — логуємо, але не відкочуємо.
		error_log( sprintf( 'FB UserFamily Link Error: %s | Family: %d | User: %d', $wpdb->last_error, $family_id, $user_id ) );
	}

	wp_send_json_success( array(
		'id'          => $family_id,
		'family_name' => $family_name,
		'message'     => __( 'Родину успішно створено.', 'family-budget' ),
	) );
}

// ============================================================================
// AJAX: UPDATE FAMILY — Перейменування родини
// ============================================================================

add_action( 'wp_ajax_fb_update_family', 'fb_ajax_update_family' );

/**
 * AJAX: Перейменування родини (inline-редагування).
 *
 * Перевіряє право доступу поточного користувача до конкретної родини
 * через UserFamily JOIN перед виконанням UPDATE-запиту.
 *
 * @since  1.0.0
 * @return void JSON-відповідь.
 */
function fb_ajax_update_family(): void {
	fb_family_verify_request( true );

	global $wpdb;

	$family_id   = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
	$family_name = isset( $_POST['family_name'] )
		? sanitize_text_field( wp_unslash( $_POST['family_name'] ) )
		: '';
	$user_id = get_current_user_id();

	if ( ! $family_id ) {
		wp_send_json_error( __( 'Невірний ID родини.', 'family-budget' ) );
	}

	if ( '' === $family_name ) {
		wp_send_json_error( __( 'Назва родини не може бути порожньою.', 'family-budget' ) );
	}

	if ( mb_strlen( $family_name ) > 50 ) {
		wp_send_json_error( __( 'Назва родини не може перевищувати 50 символів.', 'family-budget' ) );
	}

	// Перевірка доступу: поточний юзер повинен належати до цієї родини.
	if ( ! fb_user_has_family_access( $family_id ) ) {
		wp_send_json_error( __( 'Доступ заборонено.', 'family-budget' ) );
	}

	$updated = $wpdb->update(
		$wpdb->prefix . 'Family',
		array(
			'Family_Name' => $family_name,
			'updated_at'  => current_time( 'mysql' ),
		),
		array( 'id' => $family_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		error_log( sprintf( 'FB Family Update Error: %s | Family: %d | User: %d', $wpdb->last_error, $family_id, $user_id ) );
		wp_send_json_error( __( 'Помилка оновлення. Спробуйте ще раз.', 'family-budget' ) );
	}

	wp_send_json_success( array(
		'family_name' => $family_name,
		'message'     => __( 'Назву родини успішно оновлено.', 'family-budget' ),
	) );
}

// ============================================================================
// AJAX: DELETE FAMILY — Видалення родини
// ============================================================================

add_action( 'wp_ajax_fb_delete_family', 'fb_ajax_delete_family' );

/**
 * AJAX: Видалення родини з перевіркою обмежень.
 *
 * Видалення ЗАБОРОНЕНО якщо:
 *  - У родини є пов'язані записи (транзакції, рахунки, валюти, категорії).
 *  - Це єдина родина поточного користувача.
 *
 * @since  1.0.0
 * @return void JSON-відповідь.
 */
function fb_ajax_delete_family(): void {
	fb_family_verify_request( true );

	global $wpdb;

	$family_id = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
	$user_id   = get_current_user_id();

	if ( ! $family_id ) {
		wp_send_json_error( __( 'Невірний ID родини.', 'family-budget' ) );
	}

	// Перевірка доступу до родини.
	if ( ! fb_user_has_family_access( $family_id ) ) {
		wp_send_json_error( __( 'Доступ заборонено.', 'family-budget' ) );
	}

	// Перевірка: чи це єдина родина користувача.
	$family_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d",
			$user_id
		)
	);

	if ( $family_count <= 1 ) {
		wp_send_json_error( __( 'Неможливо видалити єдину родину. Спочатку створіть іншу.', 'family-budget' ) );
	}

	// Перевірка: чи є пов'язані записи в родині.
	if ( function_exists( 'fb_get_available_records' ) && fb_get_available_records( $family_id ) ) {
		wp_send_json_error( __( 'Неможливо видалити родину, яка має транзакції, рахунки, валюти або категорії.', 'family-budget' ) );
	}

	// Видаляємо зв'язки UserFamily.
	$wpdb->delete(
		$wpdb->prefix . 'UserFamily',
		array( 'Family_ID' => $family_id ),
		array( '%d' )
	);

	// Видаляємо саму родину.
	$deleted = $wpdb->delete(
		$wpdb->prefix . 'Family',
		array( 'id' => $family_id ),
		array( '%d' )
	);

	if ( false === $deleted ) {
		error_log( sprintf( 'FB Family Delete Error: %s | Family: %d | User: %d', $wpdb->last_error, $family_id, $user_id ) );
		wp_send_json_error( __( 'Помилка видалення. Спробуйте ще раз.', 'family-budget' ) );
	}

	wp_send_json_success( __( 'Родину успішно видалено.', 'family-budget' ) );
}

// ============================================================================
// AJAX: ADD USER TO FAMILY — Додавання користувача до родини
// ============================================================================

add_action( 'wp_ajax_fb_add_user_to_family', 'fb_ajax_add_user_to_family' );

/**
 * AJAX: Додавання користувача до родини за логіном або email.
 *
 * Пошук виконується спочатку за user_login, потім за user_email.
 * Перевіряє, що поточний юзер має доступ до родини та що запрошуваний
 * ще не є її учасником.
 *
 * @since  1.0.0
 * @return void JSON-відповідь з даними доданого користувача.
 */
function fb_ajax_add_user_to_family(): void {
	fb_family_verify_request( false ); // Достатньо ролі fb_user.

	global $wpdb;

	$family_id  = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
	$user_query = isset( $_POST['user_query'] )
		? sanitize_text_field( wp_unslash( $_POST['user_query'] ) )
		: '';
	$current_user_id = get_current_user_id();

	if ( ! $family_id ) {
		wp_send_json_error( __( 'Невірний ID родини.', 'family-budget' ) );
	}

	if ( '' === $user_query ) {
		wp_send_json_error( __( 'Введіть логін або email користувача.', 'family-budget' ) );
	}

	// Перевірка доступу поточного юзера до родини.
	if ( ! fb_user_has_family_access( $family_id ) ) {
		wp_send_json_error( __( 'Доступ заборонено.', 'family-budget' ) );
	}

	// Пошук користувача за логіном або email.
	$target_user = get_user_by( 'login', $user_query );

	if ( ! $target_user ) {
		$target_user = get_user_by( 'email', $user_query );
	}

	if ( ! $target_user ) {
		wp_send_json_error( __( 'Користувача не знайдено.', 'family-budget' ) );
	}

	$target_id = $target_user->ID;

	// Перевірка: чи вже є учасником.
	$already_member = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d AND Family_ID = %d",
			$target_id,
			$family_id
		)
	);

	if ( $already_member > 0 ) {
		wp_send_json_error( __( 'Цей користувач вже є учасником родини.', 'family-budget' ) );
	}

	// Додаємо користувача до родини.
	$inserted = $wpdb->insert(
		$wpdb->prefix . 'UserFamily',
		array(
			'User_ID'    => $target_id,
			'Family_ID'  => $family_id,
			'created_at' => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s' )
	);

	if ( false === $inserted ) {
		error_log( sprintf( 'FB UserFamily Add Error: %s | Family: %d | Target: %d', $wpdb->last_error, $family_id, $target_id ) );
		wp_send_json_error( __( 'Помилка додавання користувача. Спробуйте ще раз.', 'family-budget' ) );
	}

	wp_send_json_success( array(
		'user_id'      => $target_id,
		'login'        => $target_user->user_login,
		'display_name' => $target_user->display_name ?: $target_user->user_login,
		'email'        => $target_user->user_email,
		'joined'       => current_time( 'd.m.Y H:i' ),
		'message'      => __( 'Користувача успішно додано до родини.', 'family-budget' ),
	) );
}

// ============================================================================
// AJAX: REMOVE USER FROM FAMILY — Видалення користувача з родини
// ============================================================================

add_action( 'wp_ajax_fb_remove_user_from_family', 'fb_ajax_remove_user_from_family' );

/**
 * AJAX: Видалення користувача з родини.
 *
 * Заборонено видаляти самого себе, якщо ти останній учасник.
 * Управляти членством може лише fb_admin, fb_payment або WP-адмін.
 *
 * @since  1.0.0
 * @return void JSON-відповідь.
 */
function fb_ajax_remove_user_from_family(): void {
	fb_family_verify_request( true );

	global $wpdb;

	$family_id     = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
	$target_user_id = isset( $_POST['target_user_id'] ) ? absint( $_POST['target_user_id'] ) : 0;
	$current_user_id = get_current_user_id();

	if ( ! $family_id || ! $target_user_id ) {
		wp_send_json_error( __( 'Невірні параметри запиту.', 'family-budget' ) );
	}

	// Перевірка доступу поточного юзера до родини.
	if ( ! fb_user_has_family_access( $family_id ) ) {
		wp_send_json_error( __( 'Доступ заборонено.', 'family-budget' ) );
	}

	// Перевірка кількості учасників — не можна видалити єдиного.
	$member_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}UserFamily WHERE Family_ID = %d",
			$family_id
		)
	);

	if ( $member_count <= 1 ) {
		wp_send_json_error( __( 'Неможливо видалити єдиного учасника родини.', 'family-budget' ) );
	}

	$deleted = $wpdb->delete(
		$wpdb->prefix . 'UserFamily',
		array(
			'User_ID'   => $target_user_id,
			'Family_ID' => $family_id,
		),
		array( '%d', '%d' )
	);

	if ( false === $deleted || 0 === $deleted ) {
		wp_send_json_error( __( 'Користувача не знайдено у цій родині.', 'family-budget' ) );
	}

	wp_send_json_success( __( 'Користувача видалено з родини.', 'family-budget' ) );
}

// ============================================================================
// AJAX: GET FAMILY MEMBERS — Учасники родини
// ============================================================================

add_action( 'wp_ajax_fb_get_family_members', 'fb_ajax_get_family_members' );

/**
 * AJAX: Отримання списку учасників родини для таблиці.
 *
 * Повертає HTML-рядки таблиці з даними: ім'я/логін, email, дата додавання.
 * Перевіряє, що поточний юзер має доступ до запитуваної родини.
 *
 * @since  1.0.0
 * @return void HTML-виведення рядків таблиці.
 */
function fb_ajax_get_family_members(): void {
	check_ajax_referer( 'fb_family_nonce', 'security' );

	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'Не авторизовано.', 'family-budget' ) );
	}

	$family_id = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;

	if ( ! $family_id ) {
		wp_die( esc_html__( 'Невірний ID родини.', 'family-budget' ) );
	}

	// Перевірка доступу до родини.
	if ( ! fb_user_has_family_access( $family_id ) ) {
		wp_die( esc_html__( 'Доступ заборонено.', 'family-budget' ) );
	}

	global $wpdb;

	// Отримуємо учасників з даними з таблиці WordPress users.
	$members = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT uf.User_ID,
			        uf.created_at AS joined_at,
			        u.user_login,
			        u.user_email,
			        u.display_name
			   FROM {$wpdb->prefix}UserFamily AS uf
			   JOIN {$wpdb->users} AS u ON u.ID = uf.User_ID
			  WHERE uf.Family_ID = %d
			  ORDER BY uf.created_at ASC",
			$family_id
		)
	);

	if ( empty( $members ) ) {
		echo '<tr><td colspan="4" class="fb-empty-state">'
			. esc_html__( 'Учасників не знайдено', 'family-budget' )
			. '</td></tr>';
		wp_die();
	}

	$can_manage  = fb_user_can_manage_family();
	$current_uid = get_current_user_id();

	foreach ( $members as $member ) {
		$display_name = ! empty( $member->display_name )
			? $member->display_name
			: $member->user_login;

		$joined = ! empty( $member->joined_at )
			? gmdate( 'd.m.Y H:i', strtotime( $member->joined_at ) )
			: '—';

		// Перевіряємо, чи не є цей учасник останнім (блокуємо кнопку видалення).
		$is_only = count( $members ) <= 1;

		echo '<tr>';

		// Стовпець: КОРИСТУВАЧ — ім'я + @логін нижче.
		echo '<td class="fb-member-user">';
		echo '<span class="fb-member-name">' . esc_html( $display_name ) . '</span>';
		echo '<span class="fb-member-login">@' . esc_html( $member->user_login ) . '</span>';
		echo '</td>';

		// Стовпець: EMAIL.
		echo '<td class="fb-member-email">' . esc_html( $member->user_email ) . '</td>';

		// Стовпець: ДАТА ДОДАВАННЯ.
		echo '<td class="fb-member-date">' . esc_html( $joined ) . '</td>';

		// Стовпець: ДІЇ (видалення — лише для управляючих ролей).
		echo '<td class="fb-member-actions">';
		if ( $can_manage && ! $is_only ) {
			echo '<button type="button" class="fb-remove-member-btn"'
				. ' data-user-id="' . absint( $member->User_ID ) . '"'
				. ' data-family-id="' . absint( $family_id ) . '"'
				. ' title="' . esc_attr__( 'Видалити з родини', 'family-budget' ) . '">'
				. '✕</button>';
		}
		echo '</td>';

		echo '</tr>';
	}

	wp_die();
}

// ============================================================================
// AJAX: GET FAMILIES LIST — Список родин для sidebar
// ============================================================================

add_action( 'wp_ajax_fb_get_family_list', 'fb_ajax_get_family_list' );

/**
 * AJAX: Отримання списку родин поточного користувача для sidebar.
 *
 * Повертає HTML-рядки списку родин з кнопками дій.
 * Перевіряє наявність пов'язаних записів для блокування кнопки «Видалити».
 *
 * @since  1.0.0
 * @return void HTML-виведення елементів списку.
 */
function fb_ajax_get_family_list(): void {
	check_ajax_referer( 'fb_family_nonce', 'security' );

	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'Не авторизовано.', 'family-budget' ) );
	}

	global $wpdb;
	$user_id = get_current_user_id();

	$families = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.id, f.Family_Name, f.created_at
			   FROM {$wpdb->prefix}Family AS f
			  INNER JOIN {$wpdb->prefix}UserFamily AS uf ON uf.Family_ID = f.id
			  WHERE uf.User_ID = %d
			  ORDER BY f.Family_Name ASC",
			$user_id
		)
	);

	if ( empty( $families ) ) {
		echo '<li class="fb-family-empty">'
			. esc_html__( 'У вас ще немає родин.', 'family-budget' )
			. '</li>';
		wp_die();
	}

	$family_count = count( $families );
	$can_manage   = fb_user_can_manage_family();

	foreach ( $families as $family ) {
		// Перевіряємо наявність пов'язаних записів для блокування кнопки Delete.
		$has_records  = function_exists( 'fb_get_available_records' ) && fb_get_available_records( $family->id );
		$delete_block = $has_records || $family_count <= 1;

		echo '<li class="fb-family-item" data-family-id="' . absint( $family->id ) . '">';

		// Режим перегляду назви.
		echo '<span class="fb-family-name-view">' . esc_html( $family->Family_Name ) . '</span>';

		// Режим редагування (прихований за замовчуванням).
		echo '<span class="fb-family-name-edit" style="display:none;">';
		echo '<input type="text" class="fb-inline-input fb-family-name-input"'
			. ' value="' . esc_attr( $family->Family_Name ) . '"'
			. ' data-family-id="' . absint( $family->id ) . '"'
			. ' maxlength="50">';
		echo '<button type="button" class="fb-inline-save-btn" data-family-id="' . absint( $family->id ) . '">✓</button>';
		echo '<button type="button" class="fb-inline-cancel-btn">✕</button>';
		echo '</span>';

		// Кнопки дій.
		echo '<span class="fb-family-actions">';

		// Кнопка «Учасники».
		echo '<button type="button" class="fb-members-btn"'
			. ' data-family-id="' . absint( $family->id ) . '"'
			. ' title="' . esc_attr__( 'Учасники родини', 'family-budget' ) . '">👥</button>';

		if ( $can_manage ) {
			// Кнопка «Редагувати».
			echo '<button type="button" class="fb-edit-family-btn"'
				. ' data-family-id="' . absint( $family->id ) . '"'
				. ' title="' . esc_attr__( 'Перейменувати', 'family-budget' ) . '">✏️</button>';

			// Кнопка «Видалити» — disabled якщо є записи або єдина родина.
			$delete_attrs = $delete_block ? ' disabled title="' . esc_attr__( 'Видалення заблоковано', 'family-budget' ) . '"' : ' title="' . esc_attr__( 'Видалити родину', 'family-budget' ) . '"';
			echo '<button type="button" class="fb-delete-family-btn' . ( $delete_block ? ' fb-btn-disabled' : '' ) . '"'
				. ' data-family-id="' . absint( $family->id ) . '"'
				. $delete_attrs
				. '>🗑️</button>';
		}

		echo '</span>'; // .fb-family-actions
		echo '</li>';
	}

	wp_die();
}

// ============================================================================
// SHORTCODE: RENDER UI — Головний інтерфейс модуля
// ============================================================================

add_shortcode( 'fb_family', 'fb_shortcode_family_interface' );

/**
 * Рендер головного інтерфейсу управління родинами (шорткод [fb_family]).
 *
 * Макет — дві колонки:
 *  - Ліва (sidebar): форма створення → список родин.
 *  - Права (main):   таблиця учасників обраної родини.
 *
 * Перед рендером перевіряє авторизацію та наявність родин.
 *
 * @since  1.0.0
 * @return string HTML-виведення інтерфейсу.
 */
function fb_shortcode_family_interface(): string {
	if ( ! is_user_logged_in() ) {
		return '<div class="fb-notice fb-notice-error">'
			. esc_html__( 'Будь ласка, увійдіть для доступу до управління родинами.', 'family-budget' )
			. '</div>';
	}

	// Перевірка: чи є у користувача хоча б одна родина (для контексту UI).
	$has_families    = (bool) fb_get_families();
	$can_manage      = fb_user_can_manage_family();
	$can_invite      = fb_user_can_invite_to_family();

	ob_start();
	?>

	<div class="fb-family-wrapper">

		<?php /* ── Системні повідомлення ── */ ?>
		<div id="fb-family-notice" class="fb-notice" style="display:none;" role="alert"></div>

		<div class="fb-family-container">

			<!-- ════════════════════════════════════════════════════════════ -->
			<!-- ЛІВА КОЛОНКА (sidebar): Форма створення + Список родин     -->
			<!-- ════════════════════════════════════════════════════════════ -->
			<aside class="fb-family-sidebar">

				<?php if ( $can_manage ) : ?>
				<!-- Форма створення нової родини (один рядок, inline) -->
				<div class="fb-create-family-wrap">
					<div class="fb-create-row">
						<input type="text"
						       id="fb-new-family-name"
						       class="fb-form-control"
						       placeholder="<?php esc_attr_e( "Назва родини (наприклад: Сім'я Шевченків)", 'family-budget' ); ?>"
						       maxlength="50"
						       autocomplete="off">
						<button type="button" id="fb-create-family-btn" class="fb-btn-create">
							<?php esc_html_e( 'Створити родину', 'family-budget' ); ?>
						</button>
					</div>
				</div>
				<?php endif; ?>

				<!-- Список родин (завантажується через AJAX) -->
				<div class="fb-families-list-wrap">
					<ul id="fb-families-list" class="fb-families-list">
						<li class="fb-family-loading">
							<div class="fb-spinner" role="status">
								<span class="sr-only"><?php esc_html_e( 'Завантаження...', 'family-budget' ); ?></span>
							</div>
						</li>
					</ul>
				</div>

			</aside>
			<?php /* / .fb-family-sidebar */ ?>

			<!-- ════════════════════════════════════════════════════════════ -->
			<!-- ПРАВА ЧАСТИНА (main): Учасники обраної родини              -->
			<!-- ════════════════════════════════════════════════════════════ -->
			<main class="fb-family-main">

				<!-- Заголовок панелі учасників (прихований поки родину не обрано) -->
				<div id="fb-members-header" class="fb-members-header" style="display:none;">
					<h4 id="fb-selected-family-name" class="fb-members-title"></h4>
					<?php if ( $can_invite ) : ?>
					<button type="button" id="fb-open-add-user-btn" class="fb-btn-invite"
					        data-family-id="">
						+ <?php esc_html_e( 'Додати користувача', 'family-budget' ); ?>
					</button>
					<?php endif; ?>
				</div>

				<!-- Таблиця учасників (tbody заповнюється через AJAX) -->
				<div id="fb-members-table-wrap" class="fb-table-wrapper" style="display:none;">
					<table class="fb-table fb-members-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Користувач', 'family-budget' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Email', 'family-budget' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Дата додавання', 'family-budget' ); ?></th>
								<th scope="col" class="fb-th-actions"></th>
							</tr>
						</thead>
						<tbody id="fb-members-body">
							<tr>
								<td colspan="4" class="fb-empty-state">
									<?php esc_html_e( 'Оберіть родину для перегляду учасників.', 'family-budget' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Заглушка (відображається поки родину не обрано) -->
				<div id="fb-no-family-selected" class="fb-placeholder">
					<p><?php esc_html_e( 'Оберіть родину зі списку для перегляду учасників.', 'family-budget' ); ?></p>
				</div>

			</main>
			<?php /* / .fb-family-main */ ?>

		</div>
		<?php /* / .fb-family-container */ ?>

	</div>
	<?php /* / .fb-family-wrapper */ ?>

	<?php if ( $can_invite ) : ?>
	<!-- ══════════════════════════════════════════════════════════════════════ -->
	<!-- МОДАЛЬНЕ ВІКНО: Додавання користувача до родини                     -->
	<!-- ══════════════════════════════════════════════════════════════════════ -->
	<div id="fb-add-user-modal" class="fb-modal" role="dialog"
	     aria-modal="true" aria-labelledby="fb-add-user-modal-title" aria-hidden="true">
		<div class="fb-modal-overlay"></div>
		<div class="fb-modal-content">
			<h3 id="fb-add-user-modal-title" class="fb-modal-title">
				<?php esc_html_e( 'Додати користувача до родини', 'family-budget' ); ?>
			</h3>
			<p class="fb-modal-subtitle" id="fb-modal-family-label"></p>

			<div class="fb-form-field">
				<label for="fb-user-query">
					<?php esc_html_e( 'Логін або Email користувача', 'family-budget' ); ?>
				</label>
				<input type="text"
				       id="fb-user-query"
				       class="fb-form-control"
				       placeholder="<?php esc_attr_e( 'user_login або email@example.com', 'family-budget' ); ?>"
				       autocomplete="off">
			</div>

			<div class="fb-modal-actions">
				<button type="button" id="fb-confirm-add-user-btn" class="fb-btn-submit">
					<?php esc_html_e( 'Додати', 'family-budget' ); ?>
				</button>
				<button type="button" id="fb-close-add-user-btn" class="fb-btn-cancel">
					<?php esc_html_e( 'Скасувати', 'family-budget' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<?php
	return ob_get_clean();
}
