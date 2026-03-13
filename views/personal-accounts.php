<?php
/**
 * Модуль Особових рахунків (Family Budget)
 *
 * Забезпечує повний CRUD для управління особовими рахунками комунальних послуг.
 * Шорткод: [fb_personal_accounts]
 *
 * @package FamilyBudget
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════════════════════════════════════
 * БЕЗПЕКА
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Двошарова перевірка запиту: nonce + авторизація користувача.
 *
 * @param string $action Назва nonce-дії для перевірки.
 * @return void Перериває виконання і повертає JSON-помилку за невдачі.
 */
function fb_pa_verify_request( string $action = 'fb_pa_nonce' ): void {
    check_ajax_referer( $action, 'security' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Сесія завершена. Оновіть сторінку.' ] );
    }
}

/**
 * Перевіряє, чи належить оселя поточному користувачу.
 * Захищає від несанкціонованого доступу до чужих даних.
 *
 * @param int $house_id  ID оселі для перевірки.
 * @param int $user_id   ID поточного користувача.
 * @return bool True — доступ дозволено, false — заборонено.
 */
function fb_pa_user_owns_house( int $house_id, int $user_id ): bool {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}house_family hf
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE hf.id_houses = %d AND uf.User_ID = %d",
        $house_id, $user_id
    ) );
}

/**
 * Перевіряє, чи належить особовий рахунок поточному користувачу.
 *
 * @param int $pa_id   ID особового рахунку.
 * @param int $user_id ID поточного користувача.
 * @return bool True — доступ дозволено.
 */
function fb_pa_user_owns_account( int $pa_id, int $user_id ): bool {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}personal_accounts pa
         INNER JOIN {$wpdb->prefix}house_family hf ON pa.id_houses = hf.id_houses
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE pa.id = %d AND uf.User_ID = %d",
        $pa_id, $user_id
    ) );
}

/* ═══════════════════════════════════════════════════════════════════════════
 * ШОРТКОД
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Шорткод [fb_personal_accounts]: виводить інтерфейс особових рахунків.
 *
 * Завантажує активи, отримує довідникові дані та рендерить HTML-каркас.
 * Тіло таблиці заповнюється через AJAX при ініціалізації сторінки.
 *
 * @return string HTML-розмітка модуля або повідомлення про необхідність входу.
 */
function fb_shortcode_personal_accounts(): string {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Будь ласка, увійдіть.', 'family-budget' ) . '</p>';
    }

    global $wpdb;
    $uid = get_current_user_id();

    // --- Бізнес-логіка: довідникові дані ---

    // Оселі, доступні поточному користувачу
    $houses = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT h.id, h.houses_city, h.houses_street, h.houses_number
         FROM {$wpdb->prefix}houses h
         INNER JOIN {$wpdb->prefix}house_family hf ON h.id = hf.id_houses
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE uf.User_ID = %d
         ORDER BY h.houses_city ASC, h.houses_street ASC",
        $uid
    ) );

    // Типи особових рахунків (комунальні послуги)
    $account_types = $wpdb->get_results(
        "SELECT id, personal_accounts_type_name FROM {$wpdb->prefix}personal_accounts_type ORDER BY id ASC"
    );

    // --- Підключення активів ---
    $plugin_url = defined( 'FB_PLUGIN_URL' )     ? FB_PLUGIN_URL     : plugin_dir_url( dirname( __FILE__ ) );
    $plugin_ver = defined( 'FB_PLUGIN_VERSION' ) ? FB_PLUGIN_VERSION : '1.0.0';

    wp_enqueue_style(
        'fb-personal-accounts-css',
        $plugin_url . 'css/personal-accounts.css',
        [],
        $plugin_ver
    );
    wp_enqueue_script(
        'fb-personal-accounts-js',
        $plugin_url . 'js/personal-accounts.js',
        [ 'jquery' ],
        $plugin_ver,
        true
    );
    wp_localize_script( 'fb-personal-accounts-js', 'fbPaObj', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fb_pa_nonce' ),
        'confirm'  => esc_js( 'Видалити рахунок та всі його показники?' ),
    ] );

    // --- HTML-рендеринг ---
    ob_start();
    ?>
    <div class="fb-pa-wrapper">

        <!-- Панель: фільтри ліворуч, форма додавання праворуч -->
        <div class="fb-pa-controls">

            <div class="fb-filter-group">
                <select id="fb-pa-filter-house" class="fb-compact-input">
                    <option value="0">Всі оселі</option>
                    <?php foreach ( $houses as $h ) : ?>
                        <option value="<?php echo absint( $h->id ); ?>">
                            <?php echo esc_html( 'м. ' . $h->houses_city . ', ' . $h->houses_street . ' ' . $h->houses_number ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="fb-pa-filter-type" class="fb-compact-input">
                    <option value="0">Всі типи</option>
                    <?php foreach ( $account_types as $at ) : ?>
                        <option value="<?php echo absint( $at->id ); ?>">
                            <?php echo esc_html( $at->personal_accounts_type_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <form id="fb-pa-add-form" class="fb-add-group">
                <select name="id_houses" required class="fb-compact-input">
                    <option value="" disabled selected>Оселя</option>
                    <?php foreach ( $houses as $h ) : ?>
                        <option value="<?php echo absint( $h->id ); ?>">
                            <?php echo esc_html( 'м. ' . $h->houses_city . ', ' . $h->houses_street . ' ' . $h->houses_number ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="id_personal_accounts_type" required class="fb-compact-input">
                    <option value="" disabled selected>Тип послуги</option>
                    <?php foreach ( $account_types as $at ) : ?>
                        <option value="<?php echo absint( $at->id ); ?>">
                            <?php echo esc_html( $at->personal_accounts_type_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input
                    type="text"
                    name="personal_accounts_number"
                    required
                    placeholder="Номер рахунку"
                    class="fb-compact-input"
                    style="width:160px;"
                >
                <button type="submit" class="fb-btn-primary">Додати</button>
            </form>

        </div><!-- .fb-pa-controls -->

        <!-- Таблиця -->
        <div class="fb-pa-table-container">
            <table class="fb-table">
                <thead>
                    <tr>
                        <th>Оселя</th>
                        <th style="width:130px;">Тип послуги</th>
                        <th style="width:220px;">Номер рахунку</th>
                        <th style="width:80px;" class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody id="fb-pa-tbody">
                    <tr class="fb-pa-empty-row">
                        <td colspan="4">Завантаження...</td>
                    </tr>
                </tbody>
            </table>
        </div><!-- .fb-pa-table-container -->

    </div><!-- .fb-pa-wrapper -->
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_personal_accounts', 'fb_shortcode_personal_accounts' );

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ЗАВАНТАЖЕННЯ СПИСКУ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Завантажує список особових рахунків із фільтрацією.
 *
 * Повертає HTML рядків <tr> для вставки в #fb-pa-tbody.
 * Ізоляція даних: вибірка обмежена оселями поточного користувача.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_pa_load', 'fb_ajax_pa_load' );
function fb_ajax_pa_load(): void {
    fb_pa_verify_request();

    global $wpdb;
    $uid    = get_current_user_id();
    $h_id   = isset( $_POST['house_id'] ) ? absint( $_POST['house_id'] ) : 0;
    $t_id   = isset( $_POST['type_id'] )  ? absint( $_POST['type_id'] )  : 0;

    // Довідник типів для select у режимі редагування
    $account_types = $wpdb->get_results(
        "SELECT id, personal_accounts_type_name FROM {$wpdb->prefix}personal_accounts_type ORDER BY id ASC"
    );

    // Основний запит з ізоляцією по користувачу
    $query = "SELECT pa.*,
                     pat.personal_accounts_type_name,
                     h.houses_city, h.houses_street, h.houses_number
              FROM {$wpdb->prefix}personal_accounts pa
              INNER JOIN {$wpdb->prefix}personal_accounts_type pat ON pa.id_personal_accounts_type = pat.id
              INNER JOIN {$wpdb->prefix}houses h ON pa.id_houses = h.id
              WHERE pa.id_houses IN (
                  SELECT hf.id_houses FROM {$wpdb->prefix}house_family hf
                  INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
                  WHERE uf.User_ID = %d
              )";

    $args = [ $uid ];

    if ( $h_id > 0 ) {
        $query  .= ' AND pa.id_houses = %d';
        $args[]  = $h_id;
    }
    if ( $t_id > 0 ) {
        $query  .= ' AND pa.id_personal_accounts_type = %d';
        $args[]  = $t_id;
    }

    $query .= ' ORDER BY h.houses_city ASC, pa.id ASC';

    $accounts = $wpdb->get_results( $wpdb->prepare( $query, $args ) );

    ob_start();

    if ( $accounts ) {
        foreach ( $accounts as $pa ) :
            $address = esc_html( 'м. ' . $pa->houses_city . ', ' . $pa->houses_street . ' ' . $pa->houses_number );
            ?>
            <tr data-id="<?php echo absint( $pa->id ); ?>">

                <!-- Адреса оселі (лише перегляд) -->
                <td><?php echo $address; ?></td>

                <!-- Тип послуги: view / edit -->
                <td>
                    <span class="fb-view-mode"><?php echo esc_html( $pa->personal_accounts_type_name ); ?></span>
                    <select class="fb-edit-mode fb-pa-edit-type fb-compact-input hidden" style="width:115px;">
                        <?php foreach ( $account_types as $at ) : ?>
                            <option value="<?php echo absint( $at->id ); ?>" <?php selected( $pa->id_personal_accounts_type, $at->id ); ?>>
                                <?php echo esc_html( $at->personal_accounts_type_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>

                <!-- Номер рахунку: view / edit -->
                <td>
                    <span class="fb-view-mode"><?php echo esc_html( $pa->personal_accounts_number ); ?></span>
                    <input
                        type="text"
                        class="fb-edit-mode fb-pa-edit-number fb-compact-input hidden"
                        value="<?php echo esc_attr( $pa->personal_accounts_number ); ?>"
                        placeholder="Номер рахунку"
                        style="width:180px;"
                    >
                </td>

                <!-- Дії -->
                <td class="fb-pa-actions text-center">
                    <span class="fb-edit-btn"        title="Редагувати">&#9998;</span>
                    <span class="fb-save-btn hidden" title="Зберегти">&#10004;</span>
                    <span class="fb-delete-btn"      title="Видалити"></span>
                </td>

            </tr>
        <?php endforeach;
    } else {
        echo '<tr class="fb-pa-empty-row"><td colspan="4">Рахунків не знайдено</td></tr>';
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ДОДАВАННЯ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Додає новий особовий рахунок.
 *
 * Перевіряє доступ до оселі перед записом.
 * Обов'язкові поля: id_houses, id_personal_accounts_type, personal_accounts_number.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_pa_add', 'fb_ajax_pa_add' );
function fb_ajax_pa_add(): void {
    fb_pa_verify_request();

    $uid     = get_current_user_id();
    $house   = absint( $_POST['id_houses']                  ?? 0 );
    $type    = absint( $_POST['id_personal_accounts_type']  ?? 0 );
    $number  = sanitize_text_field( wp_unslash( $_POST['personal_accounts_number'] ?? '' ) );

    // Валідація обов'язкових полів
    if ( ! $house || ! $type || '' === $number ) {
        wp_send_json_error( [ 'message' => 'Заповніть усі обов\'язкові поля.' ] );
    }

    // Ізоляція: перевіряємо, чи оселя належить користувачу
    if ( ! fb_pa_user_owns_house( $house, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $inserted = $wpdb->insert(
        "{$wpdb->prefix}personal_accounts",
        [
            'id_houses'                  => $house,
            'id_personal_accounts_type'  => $type,
            'personal_accounts_number'   => $number,
        ]
    );

    if ( $inserted ) {
        wp_send_json_success();
    } else {
        wp_send_json_error( [ 'message' => 'Помилка запису до бази даних.' ] );
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — РЕДАГУВАННЯ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Оновлює тип та номер особового рахунку (inline-редагування).
 *
 * Перевіряє право власності на рахунок перед збереженням.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_pa_edit', 'fb_ajax_pa_edit' );
function fb_ajax_pa_edit(): void {
    fb_pa_verify_request();

    $uid    = get_current_user_id();
    $id     = absint( $_POST['id']     ?? 0 );
    $type   = absint( $_POST['type_id'] ?? 0 );
    $number = sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) );

    if ( ! $id || ! $type || '' === $number ) {
        wp_send_json_error( [ 'message' => 'Некоректні дані.' ] );
    }

    // Ізоляція: перевіряємо право власності на рахунок
    if ( ! fb_pa_user_owns_account( $id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $wpdb->update(
        "{$wpdb->prefix}personal_accounts",
        [
            'id_personal_accounts_type' => $type,
            'personal_accounts_number'  => $number,
        ],
        [ 'id' => $id ]
    );

    wp_send_json_success();
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ВИДАЛЕННЯ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Каскадно видаляє особовий рахунок та всі пов'язані дані.
 *
 * Порядок видалення (для цілісності даних):
 * indicator_amount → indicators → personal_accounts
 *
 * Виконується у транзакції.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_pa_delete', 'fb_ajax_pa_delete' );
function fb_ajax_pa_delete(): void {
    fb_pa_verify_request();

    $uid = get_current_user_id();
    $id  = absint( $_POST['id'] ?? 0 );

    if ( ! $id ) {
        wp_send_json_error( [ 'message' => 'Некоректний ID.' ] );
    }

    // Ізоляція: перевіряємо право власності
    if ( ! fb_pa_user_owns_account( $id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $wpdb->query( 'START TRANSACTION' );

    // 1. Видаляємо прив'язки показників до сум
    $wpdb->query( $wpdb->prepare(
        "DELETE ia FROM {$wpdb->prefix}indicator_amount ia
         INNER JOIN {$wpdb->prefix}indicators ind ON ia.id_indicators = ind.id
         WHERE ind.id_personal_accounts = %d",
        $id
    ) );

    // 2. Видаляємо показники
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}indicators WHERE id_personal_accounts = %d",
        $id
    ) );

    // 3. Видаляємо сам рахунок
    $deleted = $wpdb->delete( "{$wpdb->prefix}personal_accounts", [ 'id' => $id ] );

    if ( false !== $deleted ) {
        $wpdb->query( 'COMMIT' );
        wp_send_json_success();
    } else {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( [ 'message' => 'Помилка видалення.' ] );
    }
}
