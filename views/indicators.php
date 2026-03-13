<?php
/**
 * Модуль Показників лічильників (Family Budget)
 *
 * Забезпечує повний CRUD для комунальних показників,
 * фільтрацію по родині/оселі/рахунку та прив'язку до платежів.
 * Шорткод: [fb_indicators]
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
 * @param string $action Назва nonce-дії.
 * @return void
 */
function fb_ind_verify_request( string $action = 'fb_ind_nonce' ): void {
    check_ajax_referer( $action, 'security' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Сесія завершена. Оновіть сторінку.' ] );
    }
}

/**
 * Перевіряє, чи особовий рахунок належить поточному користувачу.
 *
 * @param int $pa_id   ID особового рахунку.
 * @param int $user_id ID поточного користувача.
 * @return bool
 */
function fb_ind_user_owns_pa( int $pa_id, int $user_id ): bool {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}personal_accounts pa
         INNER JOIN {$wpdb->prefix}house_family hf ON pa.id_houses = hf.id_houses
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE pa.id = %d AND uf.User_ID = %d",
        $pa_id, $user_id
    ) );
}

/**
 * Перевіряє, чи показник належить поточному користувачу.
 *
 * @param int $ind_id  ID показника.
 * @param int $user_id ID поточного користувача.
 * @return bool
 */
function fb_ind_user_owns_indicator( int $ind_id, int $user_id ): bool {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}indicators ind
         INNER JOIN {$wpdb->prefix}personal_accounts pa ON ind.id_personal_accounts = pa.id
         INNER JOIN {$wpdb->prefix}house_family hf ON pa.id_houses = hf.id_houses
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE ind.id = %d AND uf.User_ID = %d",
        $ind_id, $user_id
    ) );
}

/* ═══════════════════════════════════════════════════════════════════════════
 * ШОРТКОД
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Шорткод [fb_indicators]: рендерить інтерфейс показників лічильників.
 *
 * @return string HTML-розмітка модуля.
 */
function fb_shortcode_indicators_interface(): string {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Будь ласка, увійдіть.', 'family-budget' ) . '</p>';
    }

    global $wpdb;
    $uid          = get_current_user_id();
    $current_year = (int) date( 'Y' );
    $current_month = (int) date( 'n' );

    // Родини користувача
    $families = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT f.id, f.Family_Name
         FROM {$wpdb->prefix}Family f
         INNER JOIN {$wpdb->prefix}UserFamily uf ON f.id = uf.Family_ID
         WHERE uf.User_ID = %d ORDER BY f.Family_Name ASC",
        $uid
    ) );

    // Оселі користувача
    $houses = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT h.id,
                CONCAT('м. ', h.houses_city, ', ', h.houses_street, ' ', h.houses_number) AS address
         FROM {$wpdb->prefix}houses h
         INNER JOIN {$wpdb->prefix}house_family hf ON h.id = hf.id_houses
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE uf.User_ID = %d ORDER BY h.houses_city ASC, h.houses_street ASC",
        $uid
    ) );

    // Особові рахунки користувача
    $accounts = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT pa.id, pa.personal_accounts_number,
                pat.personal_accounts_type_name
         FROM {$wpdb->prefix}personal_accounts pa
         INNER JOIN {$wpdb->prefix}personal_accounts_type pat ON pa.id_personal_accounts_type = pat.id
         INNER JOIN {$wpdb->prefix}house_family hf ON pa.id_houses = hf.id_houses
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE uf.User_ID = %d ORDER BY pat.personal_accounts_type_name ASC",
        $uid
    ) );

    // Підключення активів
    $plugin_url = defined( 'FB_PLUGIN_URL' )     ? FB_PLUGIN_URL     : plugin_dir_url( dirname( __FILE__ ) );
    $plugin_ver = defined( 'FB_PLUGIN_VERSION' ) ? FB_PLUGIN_VERSION : '1.0.0';

    wp_enqueue_style( 'fb-indicators-css', $plugin_url . 'css/indicators.css', [], $plugin_ver );
    wp_enqueue_script( 'fb-indicators-js', $plugin_url . 'js/indicators.js', [ 'jquery' ], $plugin_ver, true );
    wp_localize_script( 'fb-indicators-js', 'fbIndObj', [
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'fb_ind_nonce' ),
        'confirm_delete' => esc_js( 'Видалити показник та всі пов\'язані дані?' ),
        'confirm_unlink' => esc_js( 'Від\'язати платіж від цього показника?' ),
    ] );

    ob_start();
    ?>
    <div class="fb-ind-wrapper">

        <!-- ─── Панель фільтрів ─── -->
        <div class="fb-ind-filters">
            <div class="fb-filter-group">
                <select id="fb-ind-f-family" class="fb-compact-input">
                    <option value="0">Всі родини</option>
                    <?php foreach ( $families as $f ) : ?>
                        <option value="<?php echo absint( $f->id ); ?>"><?php echo esc_html( $f->Family_Name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fb-ind-f-house" class="fb-compact-input">
                    <option value="0">Всі оселі</option>
                    <?php foreach ( $houses as $h ) : ?>
                        <option value="<?php echo absint( $h->id ); ?>"><?php echo esc_html( $h->address ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fb-ind-f-account" class="fb-compact-input">
                    <option value="0">Всі рахунки</option>
                    <?php foreach ( $accounts as $a ) : ?>
                        <option value="<?php echo absint( $a->id ); ?>">
                            <?php echo esc_html( $a->personal_accounts_type_name . ' · ' . $a->personal_accounts_number ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select id="fb-ind-f-year" class="fb-compact-input" style="width:80px;">
                    <?php for ( $y = $current_year + 1; $y >= 1960; $y-- ) : ?>
                        <option value="<?php echo $y; ?>" <?php selected( $y, $current_year ); ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <select id="fb-ind-f-month" class="fb-compact-input" style="width:90px;">
                    <option value="0">Всі міс.</option>
                    <?php
                    $months = [ 1=>'Січень',2=>'Лютий',3=>'Березень',4=>'Квітень',5=>'Травень',6=>'Червень',
                                7=>'Липень',8=>'Серпень',9=>'Вересень',10=>'Жовтень',11=>'Листопад',12=>'Грудень' ];
                    foreach ( $months as $num => $name ) :
                    ?>
                        <option value="<?php echo $num; ?>" <?php selected( $num, $current_month ); ?>><?php echo esc_html( $name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div><!-- .fb-ind-filters -->

        <!-- ─── Рядок додавання ─── -->
        <div class="fb-ind-add-bar">
            <form id="fb-ind-add-form">
                <select name="id_personal_accounts" required class="fb-compact-input fb-add-account">
                    <option value="" disabled selected>Рахунок</option>
                    <?php foreach ( $accounts as $a ) : ?>
                        <option value="<?php echo absint( $a->id ); ?>">
                            <?php echo esc_html( $a->personal_accounts_type_name . ' · ' . $a->personal_accounts_number ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="indicators_month" required class="fb-compact-input" style="width:95px;">
                    <?php foreach ( $months as $num => $name ) : ?>
                        <option value="<?php echo $num; ?>" <?php selected( $num, $current_month ); ?>><?php echo esc_html( $name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="indicators_year"     required min="1960" max="2060" value="<?php echo $current_year; ?>" placeholder="Рік"    class="fb-compact-input" style="width:68px;">
                <input type="number" name="indicators_value1"           min="0" step="0.001" placeholder="Знач.1"  class="fb-compact-input" style="width:76px;">
                <input type="number" name="indicators_value2"           min="0" step="0.001" placeholder="Знач.2"  class="fb-compact-input" style="width:76px;">
                <input type="number" name="indicators_consumed" required min="0" step="0.001" placeholder="Спожито" class="fb-compact-input" style="width:80px;">
                <button type="submit" class="fb-btn-primary">Додати</button>
            </form>
        </div><!-- .fb-ind-add-bar -->

        <!-- ─── Таблиця ─── -->
        <div class="fb-ind-table-container">
            <table class="fb-table">
                <thead>
                    <tr>
                        <th>Родина</th>
                        <th>Оселя</th>
                        <th style="width:140px;">Рахунок</th>
                        <th style="width:60px;" class="text-center">Міс.</th>
                        <th style="width:56px;" class="text-center">Рік</th>
                        <th style="width:76px;" class="text-right">Знач.1</th>
                        <th style="width:76px;" class="text-right">Знач.2</th>
                        <th style="width:80px;" class="text-right">Спожито</th>
                        <th style="width:80px;" class="text-right">Сума</th>
                        <th style="width:90px;" class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody id="fb-ind-tbody">
                    <tr class="fb-ind-empty"><td colspan="10">Завантаження...</td></tr>
                </tbody>
            </table>
        </div><!-- .fb-ind-table-container -->

    </div><!-- .fb-ind-wrapper -->

    <!-- ─── Модальне вікно прив'язки платежу ─── -->
    <div id="fb-ind-modal" class="fb-ind-modal-overlay" style="display:none;">
        <div class="fb-ind-modal-box">
            <div class="fb-ind-modal-head">
                <span>Прив'язати платіж</span>
                <button type="button" id="fb-ind-modal-close" class="fb-ind-modal-close">&times;</button>
            </div>
            <div class="fb-ind-modal-body">
                <div class="fb-ind-modal-search">
                    <input type="text" id="fb-ind-search-input" class="fb-compact-input" placeholder="Пошук за сумою або приміткою..." style="width:100%;">
                </div>
                <div id="fb-ind-search-results" class="fb-ind-search-results">
                    <p class="fb-ind-hint">Введіть запит для пошуку платежів</p>
                </div>
                <div id="fb-ind-linked-list" class="fb-ind-linked-list"></div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_indicators', 'fb_shortcode_indicators_interface' );

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ЗАВАНТАЖЕННЯ ТАБЛИЦІ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Завантажує список показників із фільтрацією.
 *
 * Повертає HTML рядків <tr> для вставки в #fb-ind-tbody.
 * Ізоляція: лише показники осель поточного користувача.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_load', 'fb_ajax_ind_load' );
function fb_ajax_ind_load(): void {
    fb_ind_verify_request();

    global $wpdb;
    $uid   = get_current_user_id();
    $f_id  = isset( $_POST['family_id']  ) ? absint( $_POST['family_id'] )  : 0;
    $h_id  = isset( $_POST['house_id']   ) ? absint( $_POST['house_id'] )   : 0;
    $pa_id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
    $year  = isset( $_POST['year']       ) ? absint( $_POST['year'] )       : 0;
    $month = isset( $_POST['month']      ) ? absint( $_POST['month'] )      : 0;

    // Довідник рахунків для select у inline-редагуванні
    $accounts = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT pa.id, pa.personal_accounts_number, pat.personal_accounts_type_name
         FROM {$wpdb->prefix}personal_accounts pa
         INNER JOIN {$wpdb->prefix}personal_accounts_type pat ON pa.id_personal_accounts_type = pat.id
         INNER JOIN {$wpdb->prefix}house_family hf ON pa.id_houses = hf.id_houses
         INNER JOIN {$wpdb->prefix}UserFamily uf ON hf.id_family = uf.Family_ID
         WHERE uf.User_ID = %d ORDER BY pat.personal_accounts_type_name ASC",
        $uid
    ) );

    $query = "SELECT ind.*,
                     pa.personal_accounts_number,
                     pat.personal_accounts_type_name,
                     CONCAT('м. ', h.houses_city, ', ', h.houses_street, ' ', h.houses_number) AS house_address,
                     f.Family_Name,
                     GROUP_CONCAT(DISTINCT a.Amount_Value ORDER BY a.id SEPARATOR ', ') AS linked_amounts,
                     GROUP_CONCAT(DISTINCT ia.id ORDER BY ia.id SEPARATOR ',') AS linked_ia_ids
              FROM {$wpdb->prefix}indicators ind
              INNER JOIN {$wpdb->prefix}personal_accounts pa ON ind.id_personal_accounts = pa.id
              INNER JOIN {$wpdb->prefix}personal_accounts_type pat ON pa.id_personal_accounts_type = pat.id
              INNER JOIN {$wpdb->prefix}houses h ON pa.id_houses = h.id
              INNER JOIN {$wpdb->prefix}house_family hf ON h.id = hf.id_houses
              INNER JOIN {$wpdb->prefix}Family f ON hf.id_family = f.id
              INNER JOIN {$wpdb->prefix}UserFamily uf ON f.id = uf.Family_ID
              LEFT JOIN {$wpdb->prefix}indicator_amount ia ON ind.id = ia.id_indicators
              LEFT JOIN {$wpdb->prefix}Amount a ON ia.id_amount = a.id
              WHERE uf.User_ID = %d";

    $args = [ $uid ];

    if ( $f_id  > 0 ) { $query .= ' AND f.id = %d';                        $args[] = $f_id;  }
    if ( $h_id  > 0 ) { $query .= ' AND h.id = %d';                        $args[] = $h_id;  }
    if ( $pa_id > 0 ) { $query .= ' AND pa.id = %d';                       $args[] = $pa_id; }
    if ( $year  > 0 ) { $query .= ' AND ind.indicators_year = %d';         $args[] = $year;  }
    if ( $month > 0 ) { $query .= ' AND ind.indicators_month = %d';        $args[] = $month; }

    $query .= ' GROUP BY ind.id ORDER BY ind.indicators_year DESC, ind.indicators_month DESC, ind.id DESC';

    $rows = $wpdb->get_results( $wpdb->prepare( $query, $args ) );

    $months_ua = [ 1=>'Січ',2=>'Лют',3=>'Бер',4=>'Кві',5=>'Тра',6=>'Чер',
                   7=>'Лип',8=>'Сер',9=>'Вер',10=>'Жов',11=>'Лис',12=>'Гру' ];

    ob_start();

    if ( $rows ) {
        foreach ( $rows as $r ) :
            $month_label = $months_ua[ (int) $r->indicators_month ] ?? $r->indicators_month;
            ?>
            <tr data-id="<?php echo absint( $r->id ); ?>">

                <!-- Родина -->
                <td><?php echo esc_html( $r->Family_Name ); ?></td>

                <!-- Адреса оселі -->
                <td class="fb-ind-addr"><?php echo esc_html( $r->house_address ); ?></td>

                <!-- Рахунок: view / edit -->
                <td>
                    <span class="fb-view-mode"><?php echo esc_html( $r->personal_accounts_type_name . ' · ' . $r->personal_accounts_number ); ?></span>
                    <select class="fb-edit-mode fb-ind-edit-pa fb-compact-input hidden" style="width:130px;">
                        <?php foreach ( $accounts as $a ) : ?>
                            <option value="<?php echo absint( $a->id ); ?>" <?php selected( $r->id_personal_accounts, $a->id ); ?>>
                                <?php echo esc_html( $a->personal_accounts_type_name . ' · ' . $a->personal_accounts_number ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>

                <!-- Місяць: view / edit -->
                <td class="text-center">
                    <span class="fb-view-mode"><?php echo esc_html( $month_label ); ?></span>
                    <input type="number" class="fb-edit-mode fb-ind-edit-month fb-compact-input hidden"
                           value="<?php echo absint( $r->indicators_month ); ?>" min="1" max="12" style="width:48px;">
                </td>

                <!-- Рік: view / edit -->
                <td class="text-center">
                    <span class="fb-view-mode"><?php echo absint( $r->indicators_year ); ?></span>
                    <input type="number" class="fb-edit-mode fb-ind-edit-year fb-compact-input hidden"
                           value="<?php echo absint( $r->indicators_year ); ?>" min="1960" max="2060" style="width:56px;">
                </td>

                <!-- Значення 1 -->
                <td class="text-right">
                    <span class="fb-view-mode"><?php echo '' !== $r->indicators_value1 ? esc_html( number_format( (float) $r->indicators_value1, 3, '.', '' ) ) : '—'; ?></span>
                    <input type="number" class="fb-edit-mode fb-ind-edit-val1 fb-compact-input hidden"
                           value="<?php echo esc_attr( $r->indicators_value1 ?? '' ); ?>" min="0" step="0.001" style="width:68px;">
                </td>

                <!-- Значення 2 -->
                <td class="text-right">
                    <span class="fb-view-mode"><?php echo '' !== $r->indicators_value2 ? esc_html( number_format( (float) $r->indicators_value2, 3, '.', '' ) ) : '—'; ?></span>
                    <input type="number" class="fb-edit-mode fb-ind-edit-val2 fb-compact-input hidden"
                           value="<?php echo esc_attr( $r->indicators_value2 ?? '' ); ?>" min="0" step="0.001" style="width:68px;">
                </td>

                <!-- Спожито -->
                <td class="text-right">
                    <span class="fb-view-mode"><?php echo esc_html( number_format( (float) $r->indicators_consumed, 3, '.', '' ) ); ?></span>
                    <input type="number" class="fb-edit-mode fb-ind-edit-consumed fb-compact-input hidden"
                           value="<?php echo esc_attr( $r->indicators_consumed ); ?>" min="0" step="0.001" style="width:72px;">
                </td>

                <!-- Сума (прив'язані платежі) -->
                <td class="text-right fb-ind-amounts">
                    <?php echo $r->linked_amounts ? esc_html( $r->linked_amounts ) : '<span class="fb-ind-no-amount">—</span>'; ?>
                </td>

                <!-- Дії -->
                <td class="fb-ind-actions text-center">
                    <span class="fb-link-btn" title="Прив'язати платіж"
                          data-id="<?php echo absint( $r->id ); ?>">&#9881;</span>
                    <span class="fb-edit-btn"        title="Редагувати">&#9998;</span>
                    <span class="fb-save-btn hidden" title="Зберегти">&#10004;</span>
                    <span class="fb-delete-btn"      title="Видалити"></span>
                </td>

            </tr>
        <?php endforeach;
    } else {
        echo '<tr class="fb-ind-empty"><td colspan="10">Показників не знайдено</td></tr>';
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ДОДАВАННЯ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Додає новий запис показника.
 *
 * Серверна валідація: місяць 1-12, рік 1960-2060, значення >= 0.
 * Перевірка унікальності: один рахунок — один запис на місяць/рік.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_add', 'fb_ajax_ind_add' );
function fb_ajax_ind_add(): void {
    fb_ind_verify_request();

    $uid      = get_current_user_id();
    $pa_id    = absint( $_POST['id_personal_accounts']  ?? 0 );
    $month    = absint( $_POST['indicators_month']       ?? 0 );
    $year     = absint( $_POST['indicators_year']        ?? 0 );
    $val1     = isset( $_POST['indicators_value1'] )    && $_POST['indicators_value1'] !== '' ? (float) $_POST['indicators_value1']    : null;
    $val2     = isset( $_POST['indicators_value2'] )    && $_POST['indicators_value2'] !== '' ? (float) $_POST['indicators_value2']    : null;
    $consumed = isset( $_POST['indicators_consumed'] )  ? (float) $_POST['indicators_consumed'] : null;

    // Валідація
    if ( ! $pa_id || $month < 1 || $month > 12 || $year < 1960 || $year > 2060 || null === $consumed || $consumed < 0 ) {
        wp_send_json_error( [ 'message' => 'Перевірте правильність заповнення полів.' ] );
    }
    if ( null !== $val1 && $val1 < 0 ) wp_send_json_error( [ 'message' => 'Значення не може бути від\'ємним.' ] );
    if ( null !== $val2 && $val2 < 0 ) wp_send_json_error( [ 'message' => 'Значення не може бути від\'ємним.' ] );

    // Ізоляція даних
    if ( ! fb_ind_user_owns_pa( $pa_id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    // Перевірка унікальності
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}indicators
         WHERE id_personal_accounts = %d AND indicators_month = %d AND indicators_year = %d",
        $pa_id, $month, $year
    ) );
    if ( $exists ) {
        wp_send_json_error( [ 'message' => 'Показник за цей місяць/рік вже існує.' ] );
    }

    $data = [
        'id_personal_accounts' => $pa_id,
        'indicators_month'     => $month,
        'indicators_year'      => $year,
        'indicators_consumed'  => $consumed,
    ];
    if ( null !== $val1 ) $data['indicators_value1'] = $val1;
    if ( null !== $val2 ) $data['indicators_value2'] = $val2;

    $inserted = $wpdb->insert( "{$wpdb->prefix}indicators", $data );

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
 * AJAX: Оновлює поля показника (inline-редагування).
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_edit', 'fb_ajax_ind_edit' );
function fb_ajax_ind_edit(): void {
    fb_ind_verify_request();

    $uid      = get_current_user_id();
    $id       = absint( $_POST['id']       ?? 0 );
    $pa_id    = absint( $_POST['pa_id']    ?? 0 );
    $month    = absint( $_POST['month']    ?? 0 );
    $year     = absint( $_POST['year']     ?? 0 );
    $consumed = isset( $_POST['consumed'] ) ? (float) $_POST['consumed'] : null;
    $val1     = isset( $_POST['val1'] ) && $_POST['val1'] !== '' ? (float) $_POST['val1'] : null;
    $val2     = isset( $_POST['val2'] ) && $_POST['val2'] !== '' ? (float) $_POST['val2'] : null;

    if ( ! $id || $month < 1 || $month > 12 || $year < 1960 || $year > 2060 || null === $consumed || $consumed < 0 ) {
        wp_send_json_error( [ 'message' => 'Некоректні дані.' ] );
    }

    if ( ! fb_ind_user_owns_indicator( $id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $data = [
        'id_personal_accounts' => $pa_id,
        'indicators_month'     => $month,
        'indicators_year'      => $year,
        'indicators_consumed'  => $consumed,
        'indicators_value1'    => $val1,
        'indicators_value2'    => $val2,
    ];

    $wpdb->update( "{$wpdb->prefix}indicators", $data, [ 'id' => $id ] );

    wp_send_json_success();
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ВИДАЛЕННЯ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Каскадно видаляє показник та пов'язані записи.
 *
 * Порядок: indicator_amount → indicators.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_delete', 'fb_ajax_ind_delete' );
function fb_ajax_ind_delete(): void {
    fb_ind_verify_request();

    $uid = get_current_user_id();
    $id  = absint( $_POST['id'] ?? 0 );

    if ( ! $id ) wp_send_json_error( [ 'message' => 'Некоректний ID.' ] );

    if ( ! fb_ind_user_owns_indicator( $id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $wpdb->query( 'START TRANSACTION' );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}indicator_amount WHERE id_indicators = %d", $id ) );
    $deleted = $wpdb->delete( "{$wpdb->prefix}indicators", [ 'id' => $id ] );

    if ( false !== $deleted ) {
        $wpdb->query( 'COMMIT' );
        wp_send_json_success();
    } else {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( [ 'message' => 'Помилка видалення.' ] );
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ПОШУК ПЛАТЕЖІВ (для модального вікна)
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Пошук платежів у таблиці Amount за сумою або приміткою.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_search_amounts', 'fb_ajax_ind_search_amounts' );
function fb_ajax_ind_search_amounts(): void {
    fb_ind_verify_request();

    $uid    = get_current_user_id();
    $ind_id = absint( $_POST['ind_id'] ?? 0 );
    $query  = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );

    if ( ! $ind_id || ! fb_ind_user_owns_indicator( $ind_id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $search = '%' . $wpdb->esc_like( $query ) . '%';

    $amounts = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.id, a.Amount_Value, a.Note
         FROM {$wpdb->prefix}Amount a
         WHERE (CAST(a.Amount_Value AS CHAR) LIKE %s OR a.Note LIKE %s)
         ORDER BY a.id DESC LIMIT 30",
        $search, $search
    ) );

    // Вже прив'язані платежі для цього показника
    $linked_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id_amount FROM {$wpdb->prefix}indicator_amount WHERE id_indicators = %d",
        $ind_id
    ) );

    wp_send_json_success( [
        'amounts'    => $amounts,
        'linked_ids' => array_map( 'absint', $linked_ids ),
    ] );
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ПРИВ'ЯЗКА ПЛАТЕЖУ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Прив'язує платіж до показника (INSERT в indicator_amount).
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_link', 'fb_ajax_ind_link' );
function fb_ajax_ind_link(): void {
    fb_ind_verify_request();

    $uid      = get_current_user_id();
    $ind_id   = absint( $_POST['ind_id']    ?? 0 );
    $amount_id = absint( $_POST['amount_id'] ?? 0 );

    if ( ! $ind_id || ! $amount_id ) wp_send_json_error( [ 'message' => 'Некоректні дані.' ] );

    if ( ! fb_ind_user_owns_indicator( $ind_id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    // Перевірка на дублікат
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}indicator_amount WHERE id_indicators = %d AND id_amount = %d",
        $ind_id, $amount_id
    ) );

    if ( $exists ) {
        wp_send_json_error( [ 'message' => 'Платіж вже прив\'язано.' ] );
    }

    $wpdb->insert( "{$wpdb->prefix}indicator_amount", [
        'id_indicators' => $ind_id,
        'id_amount'     => $amount_id,
    ] );

    wp_send_json_success();
}

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX — ВІДВ'ЯЗКА ПЛАТЕЖУ
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Видаляє зв'язок між показником та платежем (DELETE з indicator_amount).
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_unlink', 'fb_ajax_ind_unlink' );
function fb_ajax_ind_unlink(): void {
    fb_ind_verify_request();

    $uid      = get_current_user_id();
    $ind_id   = absint( $_POST['ind_id']    ?? 0 );
    $amount_id = absint( $_POST['amount_id'] ?? 0 );

    if ( ! $ind_id || ! $amount_id ) wp_send_json_error( [ 'message' => 'Некоректні дані.' ] );

    if ( ! fb_ind_user_owns_indicator( $ind_id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}indicator_amount WHERE id_indicators = %d AND id_amount = %d",
        $ind_id, $amount_id
    ) );

    wp_send_json_success();
}

/**
 * AJAX: Повертає список прив'язаних платежів для показника (для модального вікна).
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_ind_get_linked', 'fb_ajax_ind_get_linked' );
function fb_ajax_ind_get_linked(): void {
    fb_ind_verify_request();

    $uid    = get_current_user_id();
    $ind_id = absint( $_POST['ind_id'] ?? 0 );

    if ( ! $ind_id || ! fb_ind_user_owns_indicator( $ind_id, $uid ) ) {
        wp_send_json_error( [ 'message' => 'Доступ заборонено.' ] );
    }

    global $wpdb;

    $linked = $wpdb->get_results( $wpdb->prepare(
        "SELECT a.id, a.Amount_Value, a.Note
         FROM {$wpdb->prefix}indicator_amount ia
         INNER JOIN {$wpdb->prefix}Amount a ON ia.id_amount = a.id
         WHERE ia.id_indicators = %d ORDER BY a.id DESC",
        $ind_id
    ) );

    wp_send_json_success( [ 'linked' => $linked ] );
}
