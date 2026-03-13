<?php
/**
 * Plugin Name: Family Budget
 * Plugin URI: https://fbudget.pp.ua/
 * Description: Професійна система керування сімейними фінансами, інтеграцією курсів НБУ, аналітичними графіками та універсальною AJAX-системою. Повна підтримка мультивалютності та динамічних параметрів.
 * Version: 1.3.13
 * Author: Alex Wild
 * Author URI: https://wildwind.org.ua/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: family-budget
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * @package FamilyBudget
 * @version    1.3.13
 * @since 1.0.0
 */

// Захист від прямого доступу
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Головний файл: family-budget.php
// Константи плагіна
define( 'FB_VERSION', '1.3.13' );
define( 'FB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FB_PLUGIN_FILE', __FILE__ );

// Підключаємо бібліотеку автоматичних оновлень
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/AleksandrDikiy/FamilyBudget-Plagin-WordPress/', // Посилання на твій репозиторій
    __FILE__, // Вказує на цей головний файл плагіна
    'FamilyBudget' // Унікальний slug твого плагіна ВАЖЛИВО: Точна назва папки плагіна на сервері!
);

// Вказуємо гілку, з якої брати оновлення (зазвичай main)
$myUpdateChecker->setBranch('main');

/*
 * АВТОРИЗАЦІЯ ДЛЯ GITHUB API (ОПЦІОНАЛЬНО, АЛЕ БАЖАНО)
 * -------------------------------------------------------
 * GitHub має ліміт на 60 анонімних запитів на годину.
 * Щоб плагін гарантовано бачив оновлення — визначте константу
 * FB_GITHUB_TOKEN у файлі wp-config.php:
 *
 *   define( 'FB_GITHUB_TOKEN', 'your_personal_access_token_here' );
 *
 * ⚠️  НІКОЛИ не вставляйте токен безпосередньо в код плагіна!
 *     Токен у публічному репозиторії буде автоматично анульований GitHub.
 */
if ( defined( 'FB_GITHUB_TOKEN' ) && '' !== FB_GITHUB_TOKEN ) {
	$myUpdateChecker->setAuthentication( FB_GITHUB_TOKEN );
}


// 1. Задаємо версію структури БД (змінюйте її при кожній новій міграції)
if ( ! defined( 'FB_DB_VERSION' ) ) {
    define( 'FB_DB_VERSION', '1.2.0' ); // Наприклад, підвищили з 1.0.0
}
// 2. Підключаємо файл, який відповідатиме за міграції
// (Створіть папку includes, якщо її ще немає)
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fb-migrations.php';
// 1. Підключаємо PHP-файли
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fb-utilities-model.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-fb-ajax.php';

// 2. Реєструємо та підключаємо JS-скрипти
add_action( 'wp_enqueue_scripts', 'fb_enqueue_scripts' );
function fb_enqueue_scripts() {

    // Шлях до вашого JS-файлу
    wp_enqueue_script(
        'family-budget-js',
        plugin_dir_url( __FILE__ ) . 'js/family-budget.js',
        array( 'jquery' ), // Залежність від jQuery
        '1.1.0',
        true // Підключити у футері
    );

    // ВАЖЛИВО: Передаємо дані з PHP в JS (ajax_url та nonce)
    wp_localize_script( 'family-budget-js', 'fb_obj', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fb_utilities_nonce' )
    ) );
}

// 3. Вішаємо перевірку версії на хук plugins_loaded
add_action( 'plugins_loaded', 'fb_check_db_updates' );

/**
 * ПІДКЛЮЧЕННЯ МОДУЛІВ ТА ЛОГІКИ
 *
 * Структура:
 *  - includes/ — хелпери, класи та setup (без UI).
 *  - views/    — модулі-шорткоди з бізнес-логікою та HTML.
 *
 * Безпечне завантаження: відсутній файл не крашить весь плагін,
 * а лише логується в debug.log через error_log().
 */
$fb_modules = [
	// Спочатку завантажуємо includes/ — усе без UI
	'includes/fb-functions.php',           // Допоміжні функції — ПЕРШИМИ
	'includes/db-setup.php',
	'includes/class-fb-crud.php',
	'includes/class-fb-currency-rates.php',
	'includes/class-fb-import.php',

	// Потім views/ — модулі з шорткодами та бізнес-логікою
	'views/family.php',
	'views/currency.php',
	'views/account.php',
	'views/category.php',
	'views/amount.php',
	'views/category-params.php',
	'views/category-type.php',
	'views/amount-type.php',
	'views/account-type.php',
	'views/currency-admin.php',
	'views/parameter-type.php',
	'views/charts.php',
	'views/communal.php',
    'views/home.php',
    'views/houses.php',
	'views/personal-accounts.php',
];

foreach ( $fb_modules as $fb_module ) {
	$fb_path = FB_PLUGIN_DIR . $fb_module;
	if ( file_exists( $fb_path ) ) {
		require_once $fb_path;
	} else {
		error_log( "[FamilyBudget] Модуль не знайдено: {$fb_module}" );
	}
}
unset( $fb_modules, $fb_module, $fb_path );

/**
 * Підключення стилів плагіна
 */
add_action( 'wp_enqueue_scripts', 'fb_enqueue_styles' );
function fb_enqueue_styles() {
    wp_enqueue_style( 'family-budget-styles', FB_PLUGIN_URL . 'css/family-budget.css', array(), FB_VERSION );
}

/**
 * Хуки активації та деактивації
 */
register_activation_hook( FB_PLUGIN_FILE, 'fb_create_tables' );
register_deactivation_hook( FB_PLUGIN_FILE, 'fb_drop_tables' );

/**
 * AJAX МАРШРУТИЗАЦІЯ
 */
add_action( 'wp_ajax_fb_ajax_save', function() {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );
    $table = sanitize_text_field( $_POST['table'] );
    $crud = new FB_CRUD( $table );
    $crud->handle_ajax_save_generic();
} );

add_action( 'wp_ajax_fb_ajax_move', function() {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );
    $table = sanitize_text_field( $_POST['table'] );
    $crud = new FB_CRUD( $table );
    $crud->handle_ajax_move_generic();
} );

add_action( 'wp_ajax_fb_ajax_delete', function() {
    check_ajax_referer( 'fb_ajax_nonce', 'security' );
    $table = sanitize_text_field( $_POST['table'] );
    $crud = new FB_CRUD( $table );
    $crud->handle_ajax_delete_generic();
} );

// Обробник fb_set_primary_currency перенесений до currency.php (нова схема БД через CurrencyFamily).

add_action( 'wp_footer', function() {
    if ( is_user_logged_in() ) {
        ?>
        <script>
            var fb_ajax_url = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
            var fb_nonce = '<?php echo wp_create_nonce( 'fb_ajax_nonce' ); ?>';
        </script>
        <?php
    }
} );


/**
 * РЕГІСТРАЦІЯ ШОРТКОДІВ
 */
add_shortcode( 'fb_family', 'fb_shortcode_family_interface' );
// Шорткод fb_currency реєструється всередині currency.php через add_shortcode().
add_shortcode( 'fb_accounts', 'fb_shortcode_accounts' );
add_shortcode( 'fb_categories', 'fb_shortcode_categories_interface' );
add_shortcode( 'fb_budget', 'fb_render_budget_interface' );
add_shortcode( 'fb_analytics', 'fb_render_analytics_module' ); // аналітика
//add_shortcode( 'fb_charts', 'fb_render_charts_module' ); // графіки
add_shortcode( 'fb_charts', 'fb_charts_render_page' ); // графіки

add_shortcode( 'fb_account_type', 'fb_render_account_type_interface' );
add_shortcode( 'fb_category_type', 'fb_render_category_type_interface' );
add_shortcode( 'fb_amount_type', 'fb_render_amount_type_interface' );
add_shortcode( 'fb_parameter_type', 'fb_render_parameter_type_interface' );
add_shortcode( 'fb_home', 'fb_shortcode_home_interface' );
// комуналка
//add_shortcode( 'fb_houses', 'fb_shortcode_houses_interface' );


/**
 * МЕНЮ АДМІНІСТРАТОРА
 */
/**
 * Відображає сторінку адміністратора Family Budget з документацією по шорткодах
 * та системною інформацією.
 *
 * Функція винесена на рівень файлу (не всередину хука) відповідно до WPCS:
 * визначення функцій всередині колбеків є антипатерном.
 *
 * @since  1.0.0
 * @return void
 */
function fb_render_admin_page(): void {
	?>
	<div class="wrap">
		<h1>
			<span class="dashicons dashicons-chart-line" style="font-size:32px;vertical-align:middle;"></span>
			&nbsp;&nbsp;FamilyBudget <?php echo esc_html( FB_VERSION ); ?>
		</h1>
		<p class="description">
			<?php esc_html_e( 'Професійна система керування сімейними фінансами з підтримкою мультивалютності та інтеграцією з НБУ.', 'family-budget' ); ?>
		</p>
		<hr>
		<h2><?php esc_html_e( '📋 Повний список шорткодів', 'family-budget' ); ?></h2>
		<p><?php esc_html_e( 'Використовуйте ці шорткоди на сторінках WordPress для відображення функціоналу плагіна:', 'family-budget' ); ?></p>
		<table class="widefat" style="max-width:1000px;margin-top:20px;">
			<thead>
				<tr>
					<th style="width:25%;"><?php esc_html_e( 'Шорткод', 'family-budget' ); ?></th>
					<th style="width:50%;"><?php esc_html_e( 'Функціонал', 'family-budget' ); ?></th>
					<th style="width:25%;"><?php esc_html_e( 'Доступ', 'family-budget' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$fb_shortcodes = array(
					'fb_budget'     => array( __( 'Основний інтерфейс бюджету: баланс, транзакції, аналітика', 'family-budget' ), 'auth' ),
					'fb_family'     => array( __( 'Керування родинами: створення, учасники, AJAX редагування', 'family-budget' ), 'auth' ),
					'fb_currency'   => array( __( 'Валюти: додавання, основна валюта (★), конвертація НБУ', 'family-budget' ), 'auth' ),
					'fb_accounts'   => array( __( 'Рахунки: Готівка, Картки, Депозити. Сортування ▲▼', 'family-budget' ), 'auth' ),
					'fb_categories' => array( __( 'Категорії доходів/витрат. Сортування ▲▼, AJAX редагування', 'family-budget' ), 'auth' ),
					'fb_analytics'  => array( __( 'Аналітика витрат та доходів по категоріях', 'family-budget' ), 'auth' ),
					'fb_charts'     => array( __( 'Графіки фінансових показників', 'family-budget' ), 'auth' ),
				);
				foreach ( $fb_shortcodes as $sc => $info ) :
					?>
					<tr>
						<td><code>[<?php echo esc_html( $sc ); ?>]</code></td>
						<td><?php echo esc_html( $info[0] ); ?></td>
						<td><?php esc_html_e( 'Авторизовані користувачі', 'family-budget' ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr style="background:#f0f6fb;">
					<td colspan="3" style="padding:10px;">
						<strong><?php esc_html_e( '🔧 Системні довідники (тільки адміністратори)', 'family-budget' ); ?></strong>
					</td>
				</tr>
				<?php
				$fb_admin_sc = array(
					'fb_amount_type'    => __( 'Типи операцій — Витрата, Переказ, Дохід', 'family-budget' ),
					'fb_account_type'   => __( 'Типи рахунків — Готівка, Картка, Депозит', 'family-budget' ),
					'fb_parameter_type' => __( 'Типи параметрів — Число, Текст, Дата', 'family-budget' ),
                    'fb_currency_admin' => __( 'Валюти — Гривна, Долар, Евро', 'family-budget' ),

                );
				foreach ( $fb_admin_sc as $sc => $desc ) :
					?>
					<tr>
						<td><code>[<?php echo esc_html( $sc ); ?>]</code></td>
						<td><?php echo esc_html( $desc ); ?></td>
						<td><span style="color:#dc3545;"><strong>Admin Only</strong></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<hr style="margin-top:40px;">
		<h2><?php esc_html_e( '⚙️ Системна інформація', 'family-budget' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Версія плагіна:', 'family-budget' ); ?></th>
				<td><code><?php echo esc_html( FB_VERSION ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Версія WordPress:', 'family-budget' ); ?></th>
				<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Версія PHP:', 'family-budget' ); ?></th>
				<td><code><?php echo esc_html( phpversion() ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Директорія плагіна:', 'family-budget' ); ?></th>
				<td><code><?php echo esc_html( FB_PLUGIN_DIR ); ?></code></td>
			</tr>
	ot</table>
	</div>
	<?php
}

/**
 * Реєструє пункти меню адміністратора Family Budget.
 *
 * Додає головний пункт та підменю для системних довідників.
 * Підменю відображають модулі через do_shortcode().
 *
 * @since  1.0.0
 * @return void
 */
function fb_register_admin_menu(): void {
	$capability = 'manage_options';

	add_menu_page(
		'Family Budget',
		'Family Budget',
		$capability,
		'fb_family',
		'fb_render_admin_page',
		'dashicons-chart-line',
		30
	);

	$subpages = array(
		'fb_account_type'   => __( 'Типи рахунків', 'family-budget' ),
		'fb_amount_type'    => __( 'Типи операцій', 'family-budget' ),
        'fb_parameter_type' => __( 'Типи параметрів', 'family-budget' ),
        'fb_currency_admin' => __( 'Валюти', 'family-budget' ),
	);

	foreach ( $subpages as $slug => $title ) {
		add_submenu_page(
			'fb_family',
			$title,
			$title,
			$capability,
			$slug,
			static function () use ( $slug ) {
				echo '<div class="wrap">' . do_shortcode( '[' . esc_attr( $slug ) . ']' ) . '</div>';
			}
		);
	}
}

add_action( 'admin_menu', 'fb_register_admin_menu' );
