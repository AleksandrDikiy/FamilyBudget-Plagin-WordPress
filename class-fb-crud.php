<?php
/**
 * КЛАС FB_CRUD
 * Центральний вузол для обробки всіх операцій з базою даних та зовнішніми API.
 * 
 * @package FamilyBudget
 * @version 1.0.20.0
 * @since 1.0.0
 */

// Захист від прямого доступу
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Клас FB_CRUD для роботи з базою даних
 * 
 * Цей клас надає універсальні методи для:
 * - AJAX-обробки запитів
 * - Роботи з API НБУ
 * - Управління порядком елементів
 * - Встановлення основної валюти
 * 
 * @since 1.0.0
 */
class FB_CRUD {
    
    /**
     * Повна назва таблиці з префіксом
     * 
     * @var string
     * @since 1.0.0
     */
    private $table;
    
    /**
     * Глобальний об'єкт wpdb
     * 
     * @var wpdb
     * @since 1.0.0
     */
    private $wpdb;

    /**
     * Конструктор класу
     * 
     * Ініціалізує з'єднання з базою даних та встановлює назву таблиці.
     * 
     * @param string $table_name Назва таблиці без префікса (необов'язково).
     * 
     * @since 1.0.0
     */
    public function __construct( $table_name = '' ) {
        global $wpdb;
        
        $this->wpdb = $wpdb;
        
        if ( ! empty( $table_name ) ) {
            $this->table = $wpdb->prefix . $table_name;
        }
    }

    /**
     * Отримання актуальних курсів валют НБУ
     * 
     * Використовує WordPress Transients API для кешування результатів на 12 годин.
     * Це значно зменшує навантаження на API НБУ та прискорює роботу плагіна.
     * 
     * @return array Асоціативний масив курсів [КодВалюти => КурсДоГривні].
     * 
     * @since 1.0.0
     */
    public static function get_exchange_rates() {
        // Спроба отримати закешовані курси
        $rates = get_transient( 'fb_nbu_rates' );
        
        if ( false === $rates ) {
            // Запит до API НБУ
            $api_url = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json';
            $response = wp_remote_get( $api_url, array(
                'timeout' => 10,
                'sslverify' => true
            ) );
            
            // Перевірка на помилки
            if ( is_wp_error( $response ) ) {
                // Логування помилки
                error_log( 'FB_CRUD: Помилка отримання курсів НБУ - ' . $response->get_error_message() );
                
                // Повернення дефолтного курсу
                return array( 'UAH' => 1.0, '₴' => 1.0 );
            }

            // Декодування відповіді
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            
            // Ініціалізація масиву з базовою валютою
            $rates = array(
                'UAH' => 1.0,
                '₴' => 1.0
            );
            
            // Парсинг даних НБУ
            if ( is_array( $data ) && ! empty( $data ) ) {
                foreach ( $data as $currency ) {
                    if ( isset( $currency['cc'] ) && isset( $currency['rate'] ) ) {
                        $rates[ $currency['cc'] ] = (float) $currency['rate'];
                    }
                }
            }
            
            // Кешування на 12 годин
            set_transient( 'fb_nbu_rates', $rates, 12 * HOUR_IN_SECONDS );
        }
        
        return $rates;
    }

    /**
     * Універсальний AJAX-обробник збереження назв
     * 
     * Приймає ID запису, назву стовпця та нове значення.
     * Оновлює запис та встановлює мітку часу оновлення.
     * 
     * @since 1.0.0
     * @return void Надсилає JSON-відповідь та завершує виконання.
     */
    public function handle_ajax_save_generic() {
        // Перевірка nonce для безпеки
        check_ajax_referer( 'fb_ajax_nonce', 'security' );
        
        // Перевірка прав доступу
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Необхідна авторизація' );
        }

        // Валідація вхідних даних
        if ( ! isset( $_POST['id'] ) || ! isset( $_POST['name'] ) || ! isset( $_POST['col'] ) ) {
            wp_send_json_error( 'Недостатньо параметрів' );
        }

        $id = intval( $_POST['id'] );
        $name = sanitize_text_field( $_POST['name'] );
        $col = sanitize_text_field( $_POST['col'] );

        // Додаткова валідація назви стовпця (захист від SQL-ін'єкцій)
        $allowed_columns = array(
            'Family_Name',
            'Currency_Name',
            'Account_Name',
            'Category_Name',
            'ParameterType_Name',
            'AccountType_Name',
            'AmountType_Name',
            'CategoryType_Name'
        );

        if ( ! in_array( $col, $allowed_columns, true ) ) {
            wp_send_json_error( 'Некоректна назва стовпця' );
        }

        // Оновлення запису
        $result = $this->wpdb->update(
            $this->table,
            array(
                $col => $name,
                'updated_at' => current_time( 'mysql' )
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            wp_send_json_error( 'Помилка оновлення бази даних' );
        }

        wp_send_json_success( array(
            'message' => 'Успішно оновлено',
            'new_value' => $name
        ) );
    }

    /**
     * Універсальний AJAX-обробник зміни порядку (сортування)
     * 
     * Змінює місцями значення Order для поточного запису та його сусіда
     * (вгору або вниз). Працює в межах однієї родини (Family_ID).
     * 
     * @since 1.0.0
     * @return void Надсилає JSON-відповідь та завершує виконання.
     */
    public function handle_move_order() {
        // Перевірка nonce для безпеки
        check_ajax_referer( 'fb_ajax_nonce', 'security' );
        
        // Перевірка прав доступу
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Необхідна авторизація' );
        }

        // Валідація вхідних даних
        if ( ! isset( $_POST['id'] ) || ! isset( $_POST['direction'] ) || ! isset( $_POST['order_col'] ) ) {
            wp_send_json_error( 'Недостатньо параметрів' );
        }

        $id = intval( $_POST['id'] );
        $direction = sanitize_text_field( $_POST['direction'] );
        $order_col = sanitize_text_field( $_POST['order_col'] );

        // Валідація напрямку
        if ( ! in_array( $direction, array( 'up', 'down' ), true ) ) {
            wp_send_json_error( 'Некоректний напрямок' );
        }

        // Валідація назви стовпця сортування
        $allowed_order_columns = array(
            'Account_Order',
            'Category_Order',
            'ParameterType_Order',
            'AccountType_Order'
        );

        if ( ! in_array( $order_col, $allowed_order_columns, true ) ) {
            wp_send_json_error( 'Некоректна назва стовпця сортування' );
        }

        // Отримання поточного запису
        $current = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            )
        );

        if ( ! $current ) {
            wp_send_json_error( 'Запис не знайдено' );
        }

        // Перевірка наявності Family_ID
        if ( ! isset( $current->Family_ID ) ) {
            wp_send_json_error( 'Запис не має прив\'язки до родини' );
        }

        // Визначення умов пошуку сусіднього запису
        if ( 'up' === $direction ) {
            $neighbor_sql = "{$order_col} < %d ORDER BY {$order_col} DESC";
        } else {
            $neighbor_sql = "{$order_col} > %d ORDER BY {$order_col} ASC";
        }

        // Пошук сусіднього запису
        $neighbor = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, {$order_col} FROM {$this->table} 
                WHERE Family_ID = %d AND {$neighbor_sql} 
                LIMIT 1",
                $current->Family_ID,
                $current->$order_col
            )
        );

        if ( ! $neighbor ) {
            wp_send_json_error( 'Немає сусіднього запису для переміщення' );
        }

        // Обмін значеннями порядку
        $this->wpdb->update(
            $this->table,
            array( $order_col => $neighbor->$order_col ),
            array( 'id' => $current->id ),
            array( '%d' ),
            array( '%d' )
        );

        $this->wpdb->update(
            $this->table,
            array( $order_col => $current->$order_col ),
            array( 'id' => $neighbor->id ),
            array( '%d' ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'message' => 'Порядок успішно змінено'
        ) );
    }

    /**
     * AJAX-обробник встановлення основної валюти
     * 
     * Скидає прапорець Currency_Primary для всіх валют родини,
     * потім встановлює його для обраної валюти.
     * 
     * @since 1.0.0
     * @return void Надсилає JSON-відповідь та завершує виконання.
     */
    public function handle_set_primary_currency() {
        // Перевірка nonce для безпеки
        check_ajax_referer( 'fb_ajax_nonce', 'security' );
        
        // Перевірка прав доступу
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Необхідна авторизація' );
        }

        // Валідація вхідних даних
        if ( ! isset( $_POST['id'] ) || ! isset( $_POST['family_id'] ) ) {
            wp_send_json_error( 'Недостатньо параметрів' );
        }

        $id = intval( $_POST['id'] );
        $family_id = intval( $_POST['family_id'] );

        // Скидання прапорця для всіх валют родини
        $this->wpdb->update(
            $this->table,
            array( 'Currency_Primary' => 0 ),
            array( 'Family_ID' => $family_id ),
            array( '%d' ),
            array( '%d' )
        );

        // Встановлення основної валюти
        $result = $this->wpdb->update(
            $this->table,
            array( 'Currency_Primary' => 1 ),
            array( 'id' => $id ),
            array( '%d' ),
            array( '%d' )
        );

        if ( false === $result ) {
            wp_send_json_error( 'Помилка оновлення бази даних' );
        }

        wp_send_json_success( array(
            'message' => 'Основну валюту встановлено'
        ) );
    }

    /**
     * Безпечне отримання даних з таблиці
     * 
     * Допоміжний метод для отримання даних з додатковими перевірками безпеки.
     * 
     * @param array $where Умови вибірки (ключ => значення).
     * @param string $output_type Тип виводу (OBJECT, ARRAY_A, ARRAY_N).
     * 
     * @return mixed Результат запиту або null.
     * 
     * @since 1.0.0
     */
    public function get_items( $where = array(), $output_type = OBJECT ) {
        if ( empty( $this->table ) ) {
            return null;
        }

        $where_clause = '';
        $where_values = array();

        if ( ! empty( $where ) ) {
            $conditions = array();
            foreach ( $where as $key => $value ) {
                $conditions[] = sanitize_key( $key ) . ' = %s';
                $where_values[] = $value;
            }
            $where_clause = 'WHERE ' . implode( ' AND ', $conditions );
        }

        $query = "SELECT * FROM {$this->table} {$where_clause}";

        if ( ! empty( $where_values ) ) {
            $query = $this->wpdb->prepare( $query, $where_values );
        }

        return $this->wpdb->get_results( $query, $output_type );
    }
}
