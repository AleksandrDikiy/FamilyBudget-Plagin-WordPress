<?php
/**
 * Модуль параметрів категорій — Family Budget
 *
 * Реалізує функціонал додавання динамічних параметрів до категорій родини.
 * Параметри дозволяють розширити категорію додатковими полями (наприклад,
 * номер транспортного засобу для категорії «Авто»).
 *
 * @package    FamilyBudget
 * @subpackage Modules
 * @version    1.1.0
 * @since      1.0.0
 */

// Захист від прямого доступу до файлу.
defined( 'ABSPATH' ) || exit;

// =============================================================================
// ОБРОБКА ФОРМИ ДОДАВАННЯ ПАРАМЕТРА
// =============================================================================

/**
 * Обробляє POST-запит на додавання параметра до категорії.
 *
 * Виконує двошарову перевірку безпеки:
 *  1. Авторизація користувача та валідація nonce.
 *  2. Ізоляція даних — перевірка, що Family_ID та Category_ID належать
 *     поточному користувачу.
 *
 * @since  1.1.0
 * @return void
 */
function fb_handle_add_cat_param(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	if ( ! isset( $_POST['fb_action'] ) || 'add_cat_param' !== $_POST['fb_action'] ) {
		return;
	}

	// Перевірка nonce (захист від CSRF).
	if ( ! isset( $_POST['fb_cat_param_nonce'] ) ||
		! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['fb_cat_param_nonce'] ) ),
			'fb_add_cat_param'
		)
	) {
		wp_die( esc_html__( 'Помилка безпеки. Оновіть сторінку та спробуйте ще раз.', 'family-budget' ) );
	}

	global $wpdb;

	$user_id    = get_current_user_id();
	$family_id  = absint( wp_unslash( $_POST['fam_id'] ?? 0 ) );
	$cat_id     = absint( wp_unslash( $_POST['cat_id'] ?? 0 ) );
	$param_type = absint( wp_unslash( $_POST['param_type_id'] ?? 0 ) );
	$p_name     = sanitize_text_field( wp_unslash( $_POST['p_name'] ?? '' ) );

	// Валідація обов'язкових полів.
	if ( ! $family_id || ! $cat_id || ! $param_type || '' === $p_name ) {
		return;
	}

	// Ізоляція даних: перевірка доступу до родини.
	if ( ! fb_user_has_family_access( $family_id ) ) {
		wp_die( esc_html__( 'Доступ заборонено.', 'family-budget' ) );
	}

	// Ізоляція даних: перевірка, що категорія належить цій родині.
	$cat_family_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT Family_ID FROM {$wpdb->prefix}Category WHERE id = %d LIMIT 1",
			$cat_id
		)
	);

	if ( $cat_family_id !== $family_id ) {
		wp_die( esc_html__( 'Доступ заборонено.', 'family-budget' ) );
	}

	// Збереження параметра.
	$wpdb->insert(
		$wpdb->prefix . 'CategoryParam',
		array(
			'User_ID'          => $user_id,
			'Family_ID'        => $family_id,
			'Category_ID'      => $cat_id,
			'ParameterType_ID' => $param_type,
			'Category_Name'    => $p_name,
			'Category_Order'   => 1,
			'created_at'       => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
	);

	wp_safe_redirect( wp_get_referer() ?: home_url() );
	exit;
}

add_action( 'template_redirect', 'fb_handle_add_cat_param' );

// =============================================================================
// ШОРТКОД [fb_cat_params]
// =============================================================================

/**
 * Відображає форму для додавання параметрів до категорій.
 *
 * Виводить форму з вибором родини, категорії, типу параметра та його назви.
 * Доступна лише для авторизованих користувачів.
 *
 * @since  1.0.0
 * @return string HTML-розмітка форми або повідомлення про необхідність авторизації.
 */
function fb_shortcode_cat_params(): string {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Авторизуйтесь для перегляду цього розділу.', 'family-budget' ) . '</p>';
	}

	global $wpdb;

	$uid = get_current_user_id();

	// Родини поточного користувача.
	$fams = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.id, f.Family_Name
			   FROM {$wpdb->prefix}Family f
			   JOIN {$wpdb->prefix}UserFamily uf ON f.id = uf.Family_ID
			  WHERE uf.User_ID = %d
			  ORDER BY f.Family_Name ASC",
			$uid
		)
	);

	// Категорії лише з родин поточного користувача (без SQL-ін'єкції).
	$cats = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.id, c.Category_Name
			   FROM {$wpdb->prefix}Category c
			  WHERE c.Family_ID IN (
			        SELECT uf.Family_ID
			          FROM {$wpdb->prefix}UserFamily uf
			         WHERE uf.User_ID = %d
			  )
			  ORDER BY c.Category_Name ASC",
			$uid
		)
	);

	// Усі типи параметрів.
	$p_types = $wpdb->get_results(
		"SELECT id, ParameterType_Name
		   FROM {$wpdb->prefix}ParameterType
		  ORDER BY id ASC"
	);

	ob_start();
	?>
	<div class="fb-container">
		<h2><?php esc_html_e( 'Налаштування параметрів категорій', 'family-budget' ); ?></h2>
		<form method="POST" class="fb-card">
			<?php wp_nonce_field( 'fb_add_cat_param', 'fb_cat_param_nonce' ); ?>
			<input type="hidden" name="fb_action" value="add_cat_param">

			<select name="fam_id" required>
				<option value=""><?php esc_html_e( '— Оберіть родину —', 'family-budget' ); ?></option>
				<?php foreach ( $fams as $f ) : ?>
					<option value="<?php echo esc_attr( $f->id ); ?>">
						<?php echo esc_html( $f->Family_Name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="cat_id" required>
				<option value=""><?php esc_html_e( '— Оберіть категорію —', 'family-budget' ); ?></option>
				<?php foreach ( $cats as $c ) : ?>
					<option value="<?php echo esc_attr( $c->id ); ?>">
						<?php echo esc_html( $c->Category_Name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="param_type_id" required>
				<option value=""><?php esc_html_e( '— Тип параметра —', 'family-budget' ); ?></option>
				<?php foreach ( $p_types as $pt ) : ?>
					<option value="<?php echo esc_attr( $pt->id ); ?>">
						<?php echo esc_html( $pt->ParameterType_Name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<input
				type="text"
				name="p_name"
				placeholder="<?php esc_attr_e( 'Назва параметра (напр. номер машини)', 'family-budget' ); ?>"
				required
			>

			<button type="submit" class="fb-btn-save">
				<?php esc_html_e( 'Додати параметр', 'family-budget' ); ?>
			</button>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'fb_cat_params', 'fb_shortcode_cat_params' );
