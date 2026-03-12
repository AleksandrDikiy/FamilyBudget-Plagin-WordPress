<?php
defined('ABSPATH') || exit;

class FB_Utilities_Model {

    public static function get_family_houses($family_id) {
        global $wpdb;
        $table_houses = $wpdb->prefix . 'houses';
        $table_link = $wpdb->prefix . 'house_family';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT h.* FROM $table_houses h
             JOIN $table_link hf ON h.id = hf.id_houses
             WHERE hf.id_Family = %d",
            $family_id
        ));
    }

    /**
     * Розрахунок споживання на основі попереднього показника
     */
    public static function calculate_consumption($account_id, $current_value, $month, $year) {
        global $wpdb;
        $table = $wpdb->prefix . 'indicators';

        // Шукаємо останній запис ПЕРЕД обраним періодом
        $prev_value = $wpdb->get_var($wpdb->prepare(
            "SELECT indicators_value1 FROM $table 
             WHERE id_personal_accounts = %d 
             AND (indicators_year < %d OR (indicators_year = %d AND indicators_month < %d))
             ORDER BY indicators_year DESC, indicators_month DESC LIMIT 1",
            $account_id, $year, $year, $month
        ));

        if (null === $prev_value) return $current_value; // Якщо записів немає, споживання = поточному показнику

        return floatval($current_value) - floatval($prev_value);
    }

    public static function save_indicator($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'indicators';

        return $wpdb->replace(
            $table,
            [
                'id_personal_accounts' => $data['account_id'],
                'indicators_month'     => $data['month'],
                'indicators_year'      => $data['year'],
                'indicators_value1'    => $data['value'],
                'indicators_consumed'  => $data['consumed'],
                'created_at'           => current_time('mysql')
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s'] // %s для DECIMAL
        );
    }
}