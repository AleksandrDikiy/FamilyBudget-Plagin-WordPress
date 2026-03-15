<?php
/**
 * Міграція v1.3.0: Статус імпорту показників лічильників
 *
 * Цей файл відповідає за:
 * 1. Додавання колонки `indicators_import` до таблиці `{prefix}indicators`.
 * 2. Додавання індексу `Idx_Import` на нову колонку для швидкої фільтрації.
 *
 * Кожна операція є ідемпотентною — перед застосуванням перевіряється
 * поточний стан схеми через INFORMATION_SCHEMA / SHOW COLUMNS,
 * тому файл безпечно запускається повторно без побічних ефектів.
 *
 * @package FamilyBudget
 * @version 1.3.0
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Головна функція міграції v1.3.0
 *
 * Послідовно виконує всі зміни схеми, притаманні версії 1.3.0:
 *  – додавання колонки `indicators_import` (прапорець імпорту);
 *  – додавання індексу `Idx_Import` для оптимізації фільтрації за статусом.
 *
 * @since  1.3.0
 * @return void
 */
function fb_migrate_indicators_v3(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_indicators = $wpdb->prefix . 'indicators';

    $wpdb->hide_errors();

    // =========================================================================
    // 1. Додавання колонки `indicators_import` до таблиці indicators.
    //
    //    Призначення: зберігає статус походження запису —
    //      0 — запис введено або відредаговано вручну (за замовчуванням);
    //      1 — запис імпортовано з зовнішнього джерела.
    //
    //    Ідемпотентність: SHOW COLUMNS перевіряє існування колонки перед ALTER.
    // =========================================================================
    $col_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = %s
               AND COLUMN_NAME  = %s',
            $table_indicators,
            'indicators_import'
        )
    );

    if ( ! (int) $col_exists ) {
        $wpdb->query(
            "ALTER TABLE {$table_indicators}
             ADD COLUMN indicators_import TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Import status (1 - imported, 0 - edited/manual)'
             AFTER indicators_consumed"
        );
    }

    // =========================================================================
    // 2. Додавання індексу `Idx_Import` на колонку `indicators_import`.
    //
    //    Мета: прискорити вибірки з фільтрацією за статусом імпорту,
    //    що є типовою операцією при відображенні журналу показників.
    //
    //    Ідемпотентність: information_schema.STATISTICS перевіряє існування
    //    індексу до виконання ALTER TABLE.
    // =========================================================================
    $index_exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = %s
               AND INDEX_NAME   = %s',
            $table_indicators,
            'Idx_Import'
        )
    );

    if ( 0 === $index_exists ) {
        $wpdb->query(
            "ALTER TABLE {$table_indicators}
             ADD INDEX Idx_Import (indicators_import)"
        );
    }
}
