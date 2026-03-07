<?php
/**
 * Модуль типів операцій — Family Budget
 *
 * Реалізує довідник системних типів фінансових операцій (Витрата, Дохід, Переказ тощо).
 * Доступний виключно адміністраторам WordPress (capability: manage_options).
 *
 * @package    FamilyBudget
 * @subpackage Modules
 * @version    1.1.0
 * @since      1.0.0
 */

// Захист від прямого доступу до файлу.
defined( 'ABSPATH' ) || exit;

// =============================================================================
// ОБРОБКА CRUD-ОПЕРАЦІЙ
// =============================================================================

/**
 * Обробляє POST/GET-запити для CRUD-операцій над типами операцій.
 *
 * Викликається через хук template_redirect. Перевіряє права адміністратора,
 * валідує nonce та виконує додавання, редагування або видалення запису.
 * Перед видаленням перевіряє, чи тип не використовується у фінансових записах.
 *
 * @since  1.0.0
 * @return void
 */
function fb_handle_amount_type_actions(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;

	$table   = $wpdb->prefix . 'AmountType';
	$referer = wp_get_raw_referer() ?: esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

	// ── Збереження (додавання / редагування) ─────────────────────────────────
	if ( isset( $_POST['fb_action'] ) && 'save_amount_type' === $_POST['fb_action'] ) {

		// [SEC-1] wp_unslash() + sanitize_key() перед wp_verify_nonce().
		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['fb_amount_type_nonce'] ?? '' ) ),
			'fb_save_amount_type'
		) ) {
			wp_die( esc_html__( 'Помилка безпеки. Оновіть сторінку.', 'family-budget' ) );
		}

		// [SEC-5] wp_unslash() + sanitize_text_field() для рядкових полів.
		$name = sanitize_text_field( wp_unslash( $_POST['amount_type_name'] ?? '' ) );

		// [SEC-4] absint() замість intval() для ID.
		$id = absint( wp_unslash( $_POST['amount_type_id'] ?? 0 ) );

		if ( $id > 0 ) {
			$wpdb->update(
				$table,
				array( 'AmountType_Name' => $name ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'AmountType_Name' => $name,
					'created_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s' )
			);
		}

		wp_safe_redirect( remove_query_arg( 'edit', $referer ) );
		exit;
	}

	// ── Видалення ─────────────────────────────────────────────────────────────
	if ( isset( $_GET['del_amount_type'] ) ) {

		// [SEC-4] absint() для GET-параметра ID.
		$id = absint( wp_unslash( $_GET['del_amount_type'] ) );

		if ( ! $id ) {
			return;
		}

		// Перевірка на використання в таблиці транзакцій.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}Amount WHERE AmountType_ID = %d",
				$id
			)
		);

		if ( 0 === $count ) {
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			wp_safe_redirect( remove_query_arg( 'del_amount_type', $referer ) );
			exit;
		} else {
			set_transient( 'fb_amt_error', __( 'Неможливо видалити: цей тип використовується у фінансових записах.', 'family-budget' ), 30 );
		}
	}
}

add_action( 'template_redirect', 'fb_handle_amount_type_actions' );

// =============================================================================
// ШОРТКОД [fb_amount_type]
// =============================================================================

/**
 * Відображає інтерфейс управління типами операцій.
 *
 * Виводить форму додавання/редагування та таблицю наявних типів.
 * Доступний виключно адміністраторам.
 *
 * @since  1.0.0
 * @return string HTML-розмітка інтерфейсу або повідомлення про відмову в доступі.
 */
function fb_render_amount_type_interface(): string {
	if ( ! current_user_can( 'manage_options' ) ) {
		return esc_html__( 'Доступ обмежено.', 'family-budget' );
	}

	global $wpdb;

	$table = $wpdb->prefix . 'AmountType';

	// Виводимо помилку видалення, якщо є.
	$error = get_transient( 'fb_amt_error' );
	if ( $error ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		delete_transient( 'fb_amt_error' );
	}

	// Завантажуємо запис для редагування.
	$edit_item = null;
	if ( isset( $_GET['edit'] ) ) {
		$edit_item = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}AmountType WHERE id = %d", absint( wp_unslash( $_GET['edit'] ) ) )
		);
	}

	$types = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}AmountType ORDER BY id ASC" );

	ob_start();
	?>
	<div class="fb-container">
		<h2><i class="dashicons dashicons-money-alt"></i> <?php esc_html_e( 'Довідник: Типи операцій', 'family-budget' ); ?></h2>

		<form method="POST" class="fb-card">
			<?php wp_nonce_field( 'fb_save_amount_type', 'fb_amount_type_nonce' ); ?>
			<input type="hidden" name="fb_action" value="save_amount_type">
			<?php if ( $edit_item ) : ?>
				<input type="hidden" name="amount_type_id" value="<?php echo esc_attr( $edit_item->id ); ?>">
			<?php endif; ?>

			<input
				type="text"
				name="amount_type_name"
				placeholder="<?php esc_attr_e( 'Напр.: Витрата, Дохід, Переказ', 'family-budget' ); ?>"
				value="<?php echo $edit_item ? esc_attr( $edit_item->AmountType_Name ) : ''; ?>"
				required
			>

			<button type="submit" class="fb-btn-save">
				<?php echo $edit_item ? esc_html__( 'Оновити', 'family-budget' ) : esc_html__( 'Додати', 'family-budget' ); ?>
			</button>
			<?php if ( $edit_item ) : ?>
				<a href="<?php echo esc_url( remove_query_arg( 'edit' ) ); ?>" class="fb-btn-cancel">
					<?php esc_html_e( 'Скасувати', 'family-budget' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<table class="fb-table">
			<thead>
				<tr>
					<th width="50"><?php esc_html_e( 'ID', 'family-budget' ); ?></th>
					<th><?php esc_html_e( 'Найменування типу операції', 'family-budget' ); ?></th>
					<th width="100"><?php esc_html_e( 'Дії', 'family-budget' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $types as $t ) : ?>
					<tr>
						<td><?php echo absint( $t->id ); ?></td>
						<td><strong><?php echo esc_html( $t->AmountType_Name ); ?></strong></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'edit', $t->id ) ); ?>" title="<?php esc_attr_e( 'Редагувати', 'family-budget' ); ?>">📝</a>
							<a href="<?php echo esc_url( add_query_arg( 'del_amount_type', $t->id ) ); ?>"
							   onclick="return confirm('<?php esc_attr_e( 'Видалити цей тип?', 'family-budget' ); ?>')"
							   style="color:red;">🗑️</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
	return ob_get_clean();
}

add_shortcode( 'fb_amount_type', 'fb_render_amount_type_interface' );
