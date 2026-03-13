<?php
/**
 * Модуль Осель (Family Budget)
 * Рефакторинг UI: повна відповідність дизайну модуля Рахунки.
 *
 * @package FamilyBudget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Перевіряє автентичність AJAX-запиту: nonce + авторизація.
 *
 * @param string $action Назва nonce-дії.
 * @return void
 */
function fb_houses_verify_request( string $action = 'fb_house_nonce' ): void {
    check_ajax_referer( $action, 'security' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Сесія завершена.' ] );
    }
}

/**
 * Шорткод [fb_houses]: виводить інтерфейс управління оселями.
 *
 * @return string HTML-розмітка модуля.
 */
function fb_shortcode_houses_interface(): string {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'Будь ласка, увійдіть.', 'family-budget' ) . '</p>';
    }

    global $wpdb;
    $uid = get_current_user_id();

    $families = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT f.id, f.Family_Name FROM {$wpdb->prefix}Family f
         INNER JOIN {$wpdb->prefix}UserFamily uf ON f.id = uf.Family_ID
         WHERE uf.User_ID = %d ORDER BY f.Family_Name ASC",
        $uid
    ) );

    $house_types = $wpdb->get_results(
        "SELECT id, house_type_name FROM {$wpdb->prefix}house_type ORDER BY house_type_order ASC"
    );

    $plugin_url = defined( 'FB_PLUGIN_URL' )     ? FB_PLUGIN_URL     : plugin_dir_url( dirname( __FILE__ ) );
    $plugin_ver = defined( 'FB_PLUGIN_VERSION' ) ? FB_PLUGIN_VERSION : '1.0.0';

    wp_enqueue_style( 'fb-houses-css', $plugin_url . 'css/houses.css', [], $plugin_ver );
    wp_enqueue_script( 'fb-houses-js', $plugin_url . 'js/houses.js', [ 'jquery' ], $plugin_ver, true );
    wp_localize_script( 'fb-houses-js', 'fbHousesObj', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'fb_house_nonce' ),
        'confirm'  => esc_js( 'Видалити оселю та всі її дані?' ),
    ] );

    ob_start();
    ?>
    <div class="fb-houses-wrapper">

        <!-- Панель: фільтри ліворуч, форма додавання праворуч -->
        <div class="fb-houses-controls">

            <div class="fb-filter-group">
                <select id="fb-filter-family" class="fb-compact-input">
                    <option value="0">Всі родини</option>
                    <?php foreach ( $families as $f ) : ?>
                        <option value="<?php echo absint( $f->id ); ?>"><?php echo esc_html( $f->Family_Name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="fb-filter-type" class="fb-compact-input">
                    <option value="0">Всі типи</option>
                    <?php foreach ( $house_types as $ht ) : ?>
                        <option value="<?php echo absint( $ht->id ); ?>"><?php echo esc_html( $ht->house_type_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <form id="fb-add-house-form" class="fb-add-group">
                <select name="family_id" required class="fb-compact-input">
                    <option value="" disabled selected>Родина</option>
                    <?php foreach ( $families as $f ) : ?>
                        <option value="<?php echo absint( $f->id ); ?>"><?php echo esc_html( $f->Family_Name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="id_house_type" required class="fb-compact-input">
                    <?php foreach ( $house_types as $ht ) : ?>
                        <option value="<?php echo absint( $ht->id ); ?>"><?php echo esc_html( $ht->house_type_name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="city"   required placeholder="Місто"  class="fb-compact-input" style="width:110px;">
                <input type="text" name="street" required placeholder="Вулиця" class="fb-compact-input" style="width:130px;">
                <input type="text" name="number" required placeholder="Буд."   class="fb-compact-input" style="width:48px;">
                <input type="text" name="apt"             placeholder="Кв."    class="fb-compact-input" style="width:42px;">
                <button type="submit" class="fb-btn-primary">Додати</button>
            </form>

        </div><!-- .fb-houses-controls -->

        <!-- Таблиця -->
        <div class="fb-houses-table-container">
            <table class="fb-table">
                <thead>
                    <tr>
                        <th style="width:160px;">Родина</th>
                        <th style="width:140px;">Тип</th>
                        <th>Адреса</th>
                        <th style="width:72px;" class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody id="fb-houses-tbody"></tbody>
            </table>
        </div><!-- .fb-houses-table-container -->

    </div><!-- .fb-houses-wrapper -->
    <?php
    return ob_get_clean();
}
add_shortcode( 'fb_houses', 'fb_shortcode_houses_interface' );

/* ═══════════════════════════════════════════════════════════════════════════
 * AJAX-обробники
 * ═══════════════════════════════════════════════════════════════════════════ */

/**
 * AJAX: Завантаження списку осель із фільтрацією.
 * GROUP BY h.id — виключає дублі через кількох учасників родини.
 *
 * Важливо: у рядку таблиці:
 *  - .fb-view-mode   — видимий за замовчуванням (без класу hidden)
 *  - .fb-edit-mode   — прихований за замовчуванням (клас hidden)
 *  Перемикання відбувається в JS через .addClass('hidden') / .removeClass('hidden')
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_load_houses', 'fb_ajax_load_houses' );
function fb_ajax_load_houses(): void {
    fb_houses_verify_request();

    global $wpdb;
    $uid  = get_current_user_id();
    $f_id = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
    $t_id = isset( $_POST['type_id'] )   ? absint( $_POST['type_id'] )   : 0;

    $house_types = $wpdb->get_results(
        "SELECT id, house_type_name FROM {$wpdb->prefix}house_type ORDER BY house_type_order ASC"
    );

    $query = "SELECT h.*, ht.house_type_name, f.Family_Name
              FROM {$wpdb->prefix}houses h
              JOIN {$wpdb->prefix}house_type ht ON h.id_house_type = ht.id
              JOIN {$wpdb->prefix}house_family hf ON h.id = hf.id_houses
              JOIN {$wpdb->prefix}Family f ON f.id = hf.id_Family
              WHERE f.id IN (
                  SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d
              )";

    $args = [ $uid ];
    if ( $f_id > 0 ) { $query .= ' AND f.id = %d';            $args[] = $f_id; }
    if ( $t_id > 0 ) { $query .= ' AND h.id_house_type = %d'; $args[] = $t_id; }
    $query .= ' GROUP BY h.id ORDER BY h.id DESC';

    $houses = $wpdb->get_results( $wpdb->prepare( $query, $args ) );

    ob_start();

    if ( $houses ) {
        foreach ( $houses as $h ) :
            $apt_part     = $h->houses_number_apartment ? ', кв. ' . $h->houses_number_apartment : '';
            $address_view = esc_html( 'м. ' . $h->houses_city . ', ' . $h->houses_street . ' ' . $h->houses_number . $apt_part );
            ?>
            <tr data-id="<?php echo absint( $h->id ); ?>">

                <!-- Родина -->
                <td><?php echo esc_html( $h->Family_Name ); ?></td>

                <!-- Тип: view (за замовчуванням) / edit (hidden за замовчуванням) -->
                <td>
                    <span class="fb-view-mode"><?php echo esc_html( $h->house_type_name ); ?></span>
                    <select class="fb-edit-mode fb-edit-type fb-compact-input hidden" style="width:115px;">
                        <?php foreach ( $house_types as $ht ) : ?>
                            <option value="<?php echo absint( $ht->id ); ?>" <?php selected( $h->id_house_type, $ht->id ); ?>>
                                <?php echo esc_html( $ht->house_type_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>

                <!-- Адреса: view / edit в один рядок -->
                <td>
                    <span class="fb-view-mode"><?php echo $address_view; ?></span>
                    <span class="fb-edit-mode hidden">
                        <input type="text" class="fb-edit-city   fb-compact-input" value="<?php echo esc_attr( $h->houses_city ); ?>"             placeholder="Місто"  style="width:90px;">
                        <input type="text" class="fb-edit-street fb-compact-input" value="<?php echo esc_attr( $h->houses_street ); ?>"           placeholder="Вулиця" style="width:110px;">
                        <input type="text" class="fb-edit-num    fb-compact-input" value="<?php echo esc_attr( $h->houses_number ); ?>"           placeholder="Буд."   style="width:40px;">
                        <input type="text" class="fb-edit-apt    fb-compact-input" value="<?php echo esc_attr( $h->houses_number_apartment ); ?>" placeholder="Кв."    style="width:36px;">
                    </span>
                </td>

                <!-- Дії: edit, save (hidden), delete -->
                <td class="fb-actions text-center">
                    <span class="fb-edit-btn"        title="Редагувати">&#9998;</span>
                    <span class="fb-save-btn hidden" title="Зберегти">&#10004;</span>
                    <span class="fb-delete-btn"      title="Видалити"></span>
                </td>

            </tr>
        <?php endforeach;
    } else {
        echo '<tr class="fb-empty-row"><td colspan="4">Осель не знайдено</td></tr>';
    }

    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

/**
 * AJAX: Додавання нової оселі (INSERT у транзакції).
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_add_house', 'fb_ajax_add_house' );
function fb_ajax_add_house(): void {
    fb_houses_verify_request();

    if ( empty( $_POST['city'] ) || empty( $_POST['street'] ) ) {
        wp_send_json_error( [ 'message' => "Місто та вулиця обов\u{2019}язкові." ] );
    }

    global $wpdb;

    $fid    = absint( $_POST['family_id'] );
    $city   = sanitize_text_field( wp_unslash( $_POST['city'] ) );
    $street = sanitize_text_field( wp_unslash( $_POST['street'] ) );
    $number = sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) );
    $apt    = sanitize_text_field( wp_unslash( $_POST['apt']    ?? '' ) );
    $type   = absint( $_POST['id_house_type'] );

    $wpdb->query( 'START TRANSACTION' );

    $inserted = $wpdb->insert( "{$wpdb->prefix}houses", [
        'id_house_type'           => $type,
        'houses_city'             => $city,
        'houses_street'           => $street,
        'houses_number'           => $number,
        'houses_number_apartment' => $apt,
    ] );

    if ( $inserted ) {
        $wpdb->insert( "{$wpdb->prefix}house_family", [ 'id_houses' => $wpdb->insert_id, 'id_Family' => $fid ] );
        $wpdb->query( 'COMMIT' );
        wp_send_json_success();
    } else {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( [ 'message' => 'Помилка бази даних.' ] );
    }
}

/**
 * AJAX: Inline-редагування оселі.
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_edit_house', 'fb_ajax_edit_house' );
function fb_ajax_edit_house(): void {
    fb_houses_verify_request();
    global $wpdb;

    $wpdb->update( "{$wpdb->prefix}houses", [
        'id_house_type'           => absint( $_POST['type_id'] ),
        'houses_city'             => sanitize_text_field( wp_unslash( $_POST['city']   ?? '' ) ),
        'houses_street'           => sanitize_text_field( wp_unslash( $_POST['street'] ?? '' ) ),
        'houses_number'           => sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) ),
        'houses_number_apartment' => sanitize_text_field( wp_unslash( $_POST['apt']    ?? '' ) ),
    ], [ 'id' => absint( $_POST['id'] ) ] );

    wp_send_json_success();
}

/**
 * AJAX: Каскадне видалення оселі у транзакції.
 * indicators → personal_accounts → house_family → houses
 *
 * @return void
 */
add_action( 'wp_ajax_fb_ajax_delete_house', 'fb_ajax_delete_house' );
function fb_ajax_delete_house(): void {
    fb_houses_verify_request();
    global $wpdb;
    $id = absint( $_POST['id'] );

    $wpdb->query( 'START TRANSACTION' );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}indicators WHERE id_personal_accounts IN (SELECT id FROM {$wpdb->prefix}personal_accounts WHERE id_houses = %d)", $id ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}personal_accounts WHERE id_houses = %d", $id ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}house_family WHERE id_houses = %d", $id ) );
    $wpdb->delete( "{$wpdb->prefix}houses", [ 'id' => $id ] );
    $wpdb->query( 'COMMIT' );

    wp_send_json_success();
}
