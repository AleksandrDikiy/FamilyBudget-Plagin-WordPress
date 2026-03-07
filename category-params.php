<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('template_redirect', function() {
    if (!is_user_logged_in() || !isset($_POST['fb_action']) || $_POST['fb_action'] !== 'add_cat_param') return;
    global $wpdb;

    $wpdb->insert($wpdb->prefix . 'CategoryParam', [
        'User_ID'          => get_current_user_id(),
        'Family_ID'        => intval($_POST['fam_id']),
        'Category_ID'      => intval($_POST['cat_id']),
        'ParameterType_ID' => intval($_POST['param_type_id']),
        'Category_Name'    => sanitize_text_field($_POST['p_name']),
        'Category_Order'   => 1,
        'created_at'       => current_time('mysql')
    ]);
    wp_safe_redirect(wp_get_referer()); exit;
});

add_shortcode('fb_cat_params', function() {
    if (!is_user_logged_in()) return 'Авторизуйтесь.';
    global $wpdb;
    $uid = get_current_user_id();

    $fams = $wpdb->get_results($wpdb->prepare("SELECT f.id, f.Family_Name FROM {$wpdb->prefix}Family f JOIN {$wpdb->prefix}UserFamily uf ON f.id = uf.Family_ID WHERE uf.User_ID = %d", $uid));
    $cats = $wpdb->get_results("SELECT id, Category_Name FROM {$wpdb->prefix}Category WHERE Family_ID IN (SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = $uid)");
    $p_types = $wpdb->get_results("SELECT id, ParameterType_Name FROM {$wpdb->prefix}ParameterType");

    ob_start();
    //fb_render_nav();
    ?>
    <div class="fb-container">
        <h2>Налаштування параметрів категорій</h2>
        <form method="POST" class="fb-card">
            <input type="hidden" name="fb_action" value="add_cat_param">
            <select name="fam_id" required><?php foreach($fams as $f) echo "<option value='{$f->id}'>{$f->Family_Name}</option>"; ?></select>
            <select name="cat_id" required><?php foreach($cats as $c) echo "<option value='{$c->id}'>{$c->Category_Name}</option>"; ?></select>
            <select name="param_type_id" required><?php foreach($p_types as $pt) echo "<option value='{$pt->id}'>{$pt->ParameterType_Name}</option>"; ?></select>
            <input type="text" name="p_name" placeholder="Назва параметра (напр. номер машини)" required>
            <button type="submit" class="fb-btn-save">Додати параметр</button>
        </form>
    </div>
    <?php return ob_get_clean();
});