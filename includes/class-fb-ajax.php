<?php
/**
 * Файл: includes/class-fb-ajax.php
 * Обробка AJAX-запитів модуля комунальних послуг
 */

defined('ABSPATH') || exit;

/**
 * Реєстрація обробників AJAX
 * wp_ajax_{action} — спрацьовує, коли запит іде через admin-ajax.php
 */
add_action('wp_ajax_fb_save_indicator', 'fb_ajax_save_indicator_handler');

function fb_ajax_save_indicator_handler() {
    // 1. Перевірка безпеки (Nonce)
    // Має збігатися з ключем у wp_localize_script
    check_ajax_referer('fb_utilities_nonce', 'security');

    // 2. Перевірка прав доступу
    if (!current_user_can('fb_user') && !current_user_can('fb_admin') && !current_user_can('fb_payment')) {
        wp_send_json_error('У вас недостатньо прав для цієї операції');
    }

    // 3. Отримання та очищення даних з $_POST
    $account_id = isset($_POST['account_id']) ? absint($_POST['account_id']) : 0;
    $value      = isset($_POST['value'])      ? floatval($_POST['value']) : 0;
    $month      = isset($_POST['month'])      ? absint($_POST['month'])   : (int)date('n');
    $year       = isset($_POST['year'])       ? absint($_POST['year'])    : (int)date('Y');

    // Валідація
    if (!$account_id || $value <= 0) {
        wp_send_json_error('Некоректні дані: оберіть рахунок та введіть показник більше 0');
    }

    // 4. Розрахунок споживання (Consumed) через модель
    $consumed = FB_Utilities_Model::calculate_consumption($account_id, $value, $month, $year);

    // 5. Збереження в базу через модель
    $save_result = FB_Utilities_Model::save_indicator([
        'account_id' => $account_id,
        'value'      => $value,
        'month'      => $month,
        'year'       => $year,
        'consumed'   => $consumed
    ]);

    // 6. Відповідь фронтенду
    if ($save_result !== false) {
        wp_send_json_success([
            'message'  => 'Дані успішно оновлені',
            'consumed' => number_format($consumed, 3, '.', ''),
            'id'       => $account_id
        ]);
    } else {
        wp_send_json_error('Помилка бази даних при збереженні');
    }

    // Завжди завершуємо AJAX у WordPress
    wp_die();
}