<?php
/**
 * Plugin Name: Family Budget
 * Plugin URI: https://fbudget.pp.ua/
 * Description: Професійна система керування сімейними фінансами, інтеграцією курсів НБУ, аналітичними графіками та універсальною AJAX-системою. Повна підтримка мультивалютності та динамічних параметрів.
 * Version: 1.3.0.1
 * Author: Alex Wild
 * Author URI: https://wildwind.org.ua/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: family-budget
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * @package FamilyBudget
 * @version 1.3.0.1
 * @since 1.0.0
 */

// Захист від прямого доступу
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Константи плагіна
define( 'FB_VERSION', '1.1.0.0' );
define( 'FB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FB_PLUGIN_FILE', __FILE__ );

/**
 * ПІДКЛЮЧЕННЯ МОДУЛІВ ТА ЛОГІКИ
 *
 * Безпечне завантаження: відсутній файл не крашить весь плагін,
 * а лише логується в debug.log через error_log().
 */
$fb_modules = [
	'db-setup.php',
	'class-fb-crud.php',
	'class-fb-currency-rates.php',
	'class-fb-import.php',
	'family.php',
	'currency.php',
	'account.php',
	'category.php',
	'amount.php',
	'category-params.php',
	'category-type.php',
	'amount-type.php',
	'account-type.php',
	'parameter-type.php',
	'fb-charts.php',
	'communal.php',
	'home.php',
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
add_shortcode( 'fb_charts', 'fb_render_charts_module' ); // графіки

add_shortcode( 'fb_account_type', 'fb_render_account_type_interface' );
add_shortcode( 'fb_category_type', 'fb_render_category_type_interface' );
add_shortcode( 'fb_amount_type', 'fb_render_amount_type_interface' );
add_shortcode( 'fb_parameter_type', 'fb_render_parameter_type_interface' );
add_shortcode( 'fb_home', 'fb_shortcode_home_interface' );

/**
 * МЕНЮ АДМІНІСТРАТОРА
 */
add_action( 'admin_menu', function() {
    $capability = 'manage_options';

    // Головний пункт меню тепер веде на Родини
    add_menu_page( 'Family Budget'
        , 'Family Budget'
        , $capability
        , 'fb_family'
        /*, function(){ echo '<div class="wrap">'.do_shortcode('[fb_family]').'</div>'; }*/
        , 'fb_render_admin_page'            // Функція відображення
        , 'dashicons-chart-line'
        , 30 );

    /**
     * Відображає сторінку адміністратора з документацією по шорткодам
     *
     * @since 1.0.0
     * @return void
     */
    function fb_render_admin_page() {
        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-chart-line" style="font-size: 32px; vertical-align: middle;"></span>
                &nbsp;&nbsp;FamilyBudget <?php echo esc_html( FB_VERSION ); ?>
            </h1>

            <p class="description">
                Професійна система керування сімейними фінансами з підтримкою мультивалютності та інтеграцією з НБУ.
            </p>

            <hr>

            <h2>📋 Повний список шорткодів</h2>
            <p>Використовуйте ці шорткоди на сторінках WordPress для відображення функціоналу плагіна:</p>

            <table class="widefat" style="max-width: 1000px; margin-top: 20px;">
                <thead>
                <tr>
                    <th style="width: 25%;">Шорткод</th>
                    <th style="width: 50%;">Функціонал</th>
                    <th style="width: 25%;">Доступ</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td><code>[fb_budget]</code></td>
                    <td>
                        <strong>Основний інтерфейс бюджету</strong><br>
                        • Баланс з конвертацією за курсом НБУ<br>
                        • Жовта форма додавання транзакцій<br>
                        • Історія останніх 20 записів<br>
                        • Графік аналітики витрат
                    </td>
                    <td>Авторизовані користувачі</td>
                </tr>

                <tr>
                    <td><code>[fb_family]</code></td>
                    <td>
                        <strong>Керування родинами</strong><br>
                        • Створення нових родин<br>
                        • Додавання користувачів до родини<br>
                        • AJAX редагування назв<br>
                        • Видалення та архівування
                    </td>
                    <td>Авторизовані користувачі</td>
                </tr>

                <tr>
                    <td><code>[fb_currency]</code></td>
                    <td>
                        <strong>Валюти</strong><br>
                        • Додавання валют (USD, EUR, ₴ тощо)<br>
                        • Встановлення основної валюти (★)<br>
                        • Автоматична конвертація за НБУ<br>
                        • Мультивалютний баланс
                    </td>
                    <td>Авторизовані користувачі</td>
                </tr>

                <tr>
                    <td><code>[fb_accounts]</code></td>
                    <td>
                        <strong>Рахунки</strong><br>
                        • Готівка, Картки, Депозити<br>
                        • ▲▼ Сортування порядку<br>
                        • AJAX редагування назв<br>
                        • Прив'язка до типів рахунків
                    </td>
                    <td>Авторизовані користувачі</td>
                </tr>

                <tr>
                    <td><code>[fb_categories]</code></td>
                    <td>
                        <strong>Категорії доходів/витрат</strong><br>
                        • Витрати (Їжа, Транспорт, тощо)<br>
                        • Доходи (Зарплата, Бонуси)<br>
                        • ▲▼ Сортування порядку<br>
                        • AJAX редагування
                    </td>
                    <td>Авторизовані користувачі</td>
                </tr>

                <tr style="background: #f0f6fb;">
                    <td colspan="3" style="padding: 10px;">
                        <strong>🔧 Системні довідники (тільки для адміністраторів)</strong>
                    </td>
                </tr>

                <tr>
                    <td><code>[fb_dict_category_type]</code></td>
                    <td>
                        <strong>Типи категорій</strong><br>
                        Базові типи: Витрати, Доходи
                    </td>
                    <td>
                        <span style="color: #dc3545;">
                            <strong>Admin Only</strong>
                        </span>
                    </td>
                </tr>

                <tr>
                    <td><code>[fb_dict_amount_type]</code></td>
                    <td>
                        <strong>Типи транзакцій</strong><br>
                        Базові типи: Витрата, Переказ, Дохід
                    </td>
                    <td>
                        <span style="color: #dc3545;">
                            <strong>Admin Only</strong>
                        </span>
                    </td>
                </tr>

                <tr>
                    <td><code>[fb_dict_account_type]</code></td>
                    <td>
                        <strong>Типи рахунків</strong><br>
                        Базові типи: Готівка, Картка, Депозит
                    </td>
                    <td>
                        <span style="color: #dc3545;">
                            <strong>Admin Only</strong>
                        </span>
                    </td>
                </tr>

                <tr>
                    <td><code>[fb_dict_param_type]</code></td>
                    <td>
                        <strong>Типи параметрів</strong><br>
                        Базові типи: число, строка, дата
                    </td>
                    <td>
                        <span style="color: #dc3545;">
                            <strong>Admin Only</strong>
                        </span>
                    </td>
                </tr>
                </tbody>
            </table>

            <hr style="margin-top: 40px;">

            <h2>⚙️ Системна інформація</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Версія плагіна:</th>
                    <td><code><?php echo esc_html( FB_VERSION ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Версія WordPress:</th>
                    <td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Версія PHP:</th>
                    <td><code><?php echo esc_html( phpversion() ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Директорія плагіна:</th>
                    <td><code><?php echo esc_html( FB_PLUGIN_DIR ); ?></code></td>
                </tr>
            </table>

            <hr style="margin-top: 40px;">

            <h2>📚 Документація</h2>
            <p>
                <strong>Початок роботи:</strong>
            </p>
            <ol>
                <li>Створіть сторінки в WordPress для кожного модуля (Бюджет, Родина, Валюти, Категорії, Рахунки)</li>
                <li>Додайте відповідні шорткоди на ці сторінки</li>
                <li>Створіть родину через інтерфейс <code>[fb_family]</code></li>
                <li>Додайте валюти через <code>[fb_currency]</code></li>
                <li>Налаштуйте рахунки <code>[fb_accounts]</code> та категорії <code>[fb_categories]</code></li>
                <li>Почніть використовувати форму бюджету!</li>
            </ol>

            <p>
                <strong>Оновлення курсів НБУ:</strong><br>
                Курси валют автоматично додаються при додаванні нової транзакції через WordPress Transients API.
                Для примусового оновлення очистіть кеш WordPress.
            </p>
        </div>
        <?php
    }

    $subpages = array(
        'fb_account_type'   => 'Типи рахунків',
        'fb_category_type'  => 'Типи категорій',
        'fb_amount_type'    => 'Типи операцій',
        'fb_parameter_type' => 'Типи параметрів'
    );

    foreach ( $subpages as $slug => $title ) {
        add_submenu_page( 'fb_family', $title, $title, $capability, $slug, function() use ($slug) {
            echo '<div class="wrap">'.do_shortcode('[' . $slug . ']').'</div>';
        } );
    }
} );
