<?php
/**
 * Клас FB_Import — Імпорт транзакцій з CSV-файлів
 *
 * ЗМІНИ v2.0.0 (рефакторинг):
 *  [FIX-1] Видалено do_action('fb_after_amount_inserted') з import_row() —
 *           виклик на кожен рядок спричиняв N HTTP-запитів до НБУ і 504 timeout.
 *  [OPT-1] Пакетна синхронізація курсів: після завершення циклу вставки
 *           FB_Currency_Rates::fetch_and_save_rates() викликається ОДИН РАЗ
 *           для кожної унікальної дати в імпорті (а не для кожного рядка).
 *  [OPT-2] Курси НБУ кешуються через Transients — повторні виклики за одну дату
 *           не породжують нових HTTP-запитів.
 *  [SEC-1] Перевірка $family_id тепер передається явно і не може бути підмінена.
 *  [LOG-1] Розширене логування: статистика по датах, кількість збережених курсів.
 *
 * @package    FamilyBudget
 * @subpackage Import
 * @since      1.0.20.0
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Клас для імпорту транзакцій з CSV-файлів.
 *
 * АРХІТЕКТУРА ЧАНКОВОГО ІМПОРТУ (v3.0.0):
 *
 *  КРОК 1 — [AJAX fb_import_upload]
 *           JS надсилає файл → збереження у захищену директорію →
 *           підрахунок рядків → створення transient-сесії → повертає token.
 *
 *  КРОК 2 — [AJAX fb_import_chunk] (N разів)
 *           JS надсилає token + offset → обробляємо CHUNK_SIZE рядків →
 *           оновлюємо сесію → повертаємо прогрес і next_offset.
 *
 *  КРОК 3 — Якщо is_done === true:
 *           Синхронізація курсів → видалення файлу → видалення transient.
 *
 * Кожен AJAX-запит займає < 5 сек → таймаути сервера виключені.
 */
class FB_Import {

    /**
     * Кількість рядків CSV в одному чанку.
     *
     * @var int
     */
    const CHUNK_SIZE = 100;

    /**
     * Час життя transient-сесії (1 година).
     *
     * @var int
     */
    const SESSION_TTL = HOUR_IN_SECONDS;

    /**
     * Прапор: чи потрібно виводити JS імпорту у wp_footer.
     *
     * [FIX-A] Замінює ненадійний has_shortcode() — встановлюється
     * безпосередньо з fb_render_budget_interface() при рендері шорткоду.
     *
     * @var bool
     */
    private static bool $js_needed = false;

    /**
     * Повідомляє класу що JS-код імпорту потрібно вивести у wp_footer.
     *
     * Викликається з fb_render_budget_interface() при кожному рендері шорткоду.
     *
     * @since  3.1.0
     * @return void
     */
    public static function set_js_needed(): void {
        self::$js_needed = true;
    }

    // =========================================================================
    // ІНІЦІАЛІЗАЦІЯ ХУКІВ
    // =========================================================================

    /**
     * Реєструє AJAX-хуки та wp_footer для JS.
     * Викликається при підключенні файлу (внизу файлу).
     *
     * @since  3.0.0
     * @return void
     */
    public static function register_hooks(): void {
        // Примітка: wp_footer для JS імпорту реєструється безпосередньо
        // в fb_render_budget_interface() (amount.php) — без залежності від цього файлу.
        add_action( 'wp_ajax_fb_import_upload',      array( self::class, 'ajax_upload' ) );
        add_action( 'wp_ajax_fb_import_chunk',       array( self::class, 'ajax_chunk' ) );
        // Фоновий cron для синхронізації курсів після імпорту.
        add_action( 'fb_cron_sync_currency_rates',   array( self::class, 'cron_sync_rates' ) );
    }

    // =========================================================================
    // AJAX: КРОК 1 — Завантаження та збереження файлу
    // =========================================================================

    /**
     * AJAX-обробник завантаження CSV-файлу на сервер.
     *
     * Зберігає файл у захищену директорію, підраховує рядки, створює
     * transient-сесію та повертає token для подальшого polling.
     *
     * @since  3.0.0
     * @return void Повертає JSON-відповідь.
     */
    public static function ajax_upload(): void {
        check_ajax_referer( 'fb_ajax_nonce', 'security' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Необхідна аутентифікація.', 'family-budget' ) ) );
        }

        if ( ! current_user_can( 'family_member' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'family-budget' ) ) );
        }

        if ( empty( $_FILES['xls_file'] ) || UPLOAD_ERR_OK !== $_FILES['xls_file']['error'] ) {
            wp_send_json_error( array( 'message' => __( 'Файл не отримано або помилка завантаження.', 'family-budget' ) ) );
        }

        $file      = $_FILES['xls_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, array( 'csv' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Дозволено лише CSV-файли.', 'family-budget' ) ) );
        }

        if ( $file['size'] > 10 * 1024 * 1024 ) {
            wp_send_json_error( array( 'message' => __( 'Файл завеликий. Максимум: 10MB.', 'family-budget' ) ) );
        }

        // Отримуємо Family_ID поточного користувача.
        global $wpdb;
        $user_id   = get_current_user_id();
        $family_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT Family_ID FROM {$wpdb->prefix}UserFamily WHERE User_ID = %d LIMIT 1",
                $user_id
            )
        );

        if ( ! $family_id ) {
            wp_send_json_error( array( 'message' => __( "Родину не знайдено для вашого акаунту.", 'family-budget' ) ) );
        }

        // Зберігаємо файл у захищену директорію.
        $filepath = self::save_uploaded_file( $file );
        if ( is_wp_error( $filepath ) ) {
            wp_send_json_error( array( 'message' => $filepath->get_error_message() ) );
        }

        // Підраховуємо рядки (без заголовка).
        $total_rows = max( 0, self::count_csv_rows( $filepath ) - 1 );

        if ( $total_rows === 0 ) {
            wp_delete_file( $filepath );
            wp_send_json_error( array( 'message' => __( 'CSV-файл порожній або містить лише заголовок.', 'family-budget' ) ) );
        }

        // Валюта родини за замовчуванням.
        $currency_id = self::get_family_primary_currency( $family_id );

        // Створюємо transient-сесію.
        $token   = wp_generate_uuid4();
        $session = array(
            'filepath'       => $filepath,
            'family_id'      => $family_id,
            'user_id'        => $user_id,
            'currency_id'    => $currency_id,
            'total_rows'     => $total_rows,
            'imported'       => 0,
            'errors'         => 0,
            'imported_dates' => array(),
            'started_at'     => microtime( true ),
        );
        set_transient( 'fb_import_' . $token, $session, self::SESSION_TTL );

        error_log( sprintf(
            '[FB Import %s] START: token=%s, family=%d, total_rows=%d, file=%s',
            current_time( 'Y-m-d H:i:s' ),
            $token,
            $family_id,
            $total_rows,
            basename( $filepath )
        ) );

        wp_send_json_success( array(
            'token'      => $token,
            'total_rows' => $total_rows,
            'chunk_size' => self::CHUNK_SIZE,
        ) );
    }

    // =========================================================================
    // AJAX: КРОК 2 — Обробка одного чанку рядків
    // =========================================================================

    /**
     * AJAX-обробник обробки одного чанку рядків CSV.
     *
     * Викликається JS у циклі до завершення всіх рядків (is_done === true).
     * При завершенні: синхронізує курси, видаляє файл та transient.
     *
     * @since  3.0.0
     * @return void Повертає JSON-відповідь із прогресом.
     */
    public static function ajax_chunk(): void {
        check_ajax_referer( 'fb_ajax_nonce', 'security' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Необхідна аутентифікація.', 'family-budget' ) ) );
        }

        $token  = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $offset = absint( $_POST['offset'] ?? 0 );

        if ( ! $token ) {
            wp_send_json_error( array( 'message' => __( 'Відсутній токен сесії.', 'family-budget' ) ) );
        }

        $session = get_transient( 'fb_import_' . $token );

        if ( ! $session ) {
            wp_send_json_error( array( 'message' => __( 'Сесію імпорту не знайдено або вона застаріла.', 'family-budget' ) ) );
        }

        // Перевіряємо що сесія належить поточному користувачу.
        if ( (int) $session['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => __( 'Доступ заборонено.', 'family-budget' ) ) );
        }

        if ( ! file_exists( $session['filepath'] ) ) {
            delete_transient( 'fb_import_' . $token );
            wp_send_json_error( array( 'message' => __( 'Тимчасовий файл не знайдено.', 'family-budget' ) ) );
        }

        // Відкриваємо файл і переходимо до потрібного рядка.
        $handle = fopen( $session['filepath'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $handle ) {
            wp_send_json_error( array( 'message' => __( 'Не вдалося відкрити файл для читання.', 'family-budget' ) ) );
        }

        $delimiter = ';';

        // Пропускаємо заголовок + вже оброблені рядки ($offset рядків даних).
        $skip = $offset + 1; // +1 = заголовок
        for ( $i = 0; $i < $skip; $i++ ) {
            fgetcsv( $handle, 0, $delimiter );
        }

        $chunk_inserted = 0;
        $chunk_errors   = 0;
        $chunk_dates    = array();
        $rows_read      = 0;
        $chunk_start    = microtime( true );

        while ( $rows_read < self::CHUNK_SIZE ) {
            $data = fgetcsv( $handle, 0, $delimiter );
            if ( $data === false ) {
                break;
            }

            $rows_read++;
            $line = $offset + $rows_read;

            // Пропускаємо порожні рядки.
            if ( empty( array_filter( $data ) ) ) {
                continue;
            }

            if ( count( $data ) < 5 ) {
                $chunk_errors++;
                error_log( sprintf( '[FB Import] SKIP line %d: not enough columns (%d)', $line, count( $data ) ) );
                continue;
            }

            $result = self::import_row( $data, $session['family_id'], $session['currency_id'], $line );

            if ( $result['success'] ) {
                $chunk_inserted++;
                if ( ! empty( $result['date'] ) ) {
                    $chunk_dates[ $result['date'] ] = true;
                }
            } else {
                $chunk_errors++;
                error_log( sprintf( '[FB Import] ERROR: %s', $result['message'] ) );
            }
        }

        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        // Оновлюємо сесію.
        $session['imported']       += $chunk_inserted;
        $session['errors']         += $chunk_errors;
        $session['imported_dates']  = array_merge( $session['imported_dates'], $chunk_dates );

        $next_offset = $offset + $rows_read;

        /*
         * [FIX-2] Стара умова $rows_read === 0 спричиняла зайвий порожній AJAX-запит
         * після останнього чанку розміром рівно CHUNK_SIZE.
         * Достатньо перевірити що оброблено >= загальної кількості рядків,
         * або прочитано менше CHUNK_SIZE (означає кінець файлу).
         */
        $is_done = ( $next_offset >= $session['total_rows'] ) || ( $rows_read < self::CHUNK_SIZE );

        error_log( sprintf(
            '[FB Import %s] CHUNK: offset=%d, read=%d, inserted=%d, errors=%d, elapsed=%.2fs',
            current_time( 'Y-m-d H:i:s' ),
            $offset,
            $rows_read,
            $chunk_inserted,
            $chunk_errors,
            microtime( true ) - $chunk_start
        ) );

        if ( $is_done ) {
            wp_delete_file( $session['filepath'] );
            delete_transient( 'fb_import_' . $token );

            /*
             * [FIX-SYNC] Синхронізація курсів НБУ виконується у ФОНІ через wp_cron.
             *
             * Проблема: при імпорті за рік ~365 унікальних дат → 365 HTTP-запитів
             * до НБУ API синхронно в одному PHP-процесі → max_execution_time → 500.
             *
             * Рішення: wp_schedule_single_event() ставить завдання в чергу cron.
             * Воно запуститься при наступному відвідуванні сайту (або wp-cron.php)
             * окремим PHP-процесом, не блокуючи поточний AJAX-запит.
             */
            if ( $session['imported'] > 0 && ! empty( $session['imported_dates'] ) ) {
                $dates = array_keys( $session['imported_dates'] );
                // Зберігаємо дати в transient для передачі в cron-обробник.
                set_transient(
                    'fb_sync_rates_' . $session['family_id'],
                    $dates,
                    2 * HOUR_IN_SECONDS
                );
                // Плануємо фоновий запуск через 5 секунд.
                wp_schedule_single_event(
                    time() + 5,
                    'fb_cron_sync_currency_rates',
                    array( $session['family_id'] )
                );
                error_log( sprintf(
                    '[FB Import %s] Scheduled background currency sync: %d dates for family %d',
                    current_time( 'Y-m-d H:i:s' ),
                    count( $dates ),
                    $session['family_id']
                ) );
            }

            error_log( sprintf(
                '[FB Import %s] DONE: token=%s, total_imported=%d, total_errors=%d, elapsed=%.2fs',
                current_time( 'Y-m-d H:i:s' ),
                $token,
                $session['imported'],
                $session['errors'],
                microtime( true ) - $session['started_at']
            ) );
        } else {
            // Зберігаємо оновлену сесію.
            set_transient( 'fb_import_' . $token, $session, self::SESSION_TTL );
        }

        wp_send_json_success( array(
            'processed'   => $next_offset,
            'total_rows'  => $session['total_rows'],
            'inserted'    => $chunk_inserted,
            'errors'      => $chunk_errors,
            'is_done'     => $is_done,
            'next_offset' => $next_offset,
            'total_imported' => $session['imported'],
            'total_errors'   => $session['errors'],
        ) );
    }

    // =========================================================================
    // WP_FOOTER: Inline JS для чанкового імпорту
    // =========================================================================

    /**
     * Виводить inline JavaScript для UI чанкового імпорту через хук wp_footer.
     *
     * Активується лише для авторизованих користувачів.
     * Перехоплює клік на #fb-import-btn, показує оверлей з прогрес-баром,
     * послідовно надсилає AJAX-запити на fb_import_upload → fb_import_chunk (N).
     *
     * @since  3.0.0
     * @return void
     */
    public static function render_import_js(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        /*
         * [FIX-A] has_shortcode($post->post_content) ненадійний: повертає false
         * коли сторінка побудована в Gutenberg, page builder або є кешування.
         * Замінено на статичний прапор self::$js_needed, що встановлюється
         * безпосередньо з fb_render_budget_interface() при рендері шорткоду.
         */
        if ( ! self::$js_needed ) {
            return;
        }
        ?>
        <script>
        /* FB Import — чанковий AJAX-імпорт транзакцій */
        (function ($) {
            'use strict';

            if ( typeof fbAmountData === 'undefined' ) { return; }

            var FBImport = {
                ajaxUrl  : fbAmountData.ajax_url,
                nonce    : fbAmountData.nonce,
                token    : null,
                total    : 0,
                offset   : 0,
                inserted : 0,
                errors   : 0,

                /**
                 * Ініціалізація обробників подій.
                 */
                init: function () {
                    $(document).on( 'click', '#fb-import-btn', function (e) {
                        e.preventDefault();
                        FBImport.start();
                    });
                },

                /**
                 * Показ оверлею з повноекранним блокуванням.
                 * [FIX-3] jQuery fadeIn() встановлює display:block, руйнуючи flex-центрування.
                 * Використовуємо CSS-клас .fb-overlay-visible з display:flex.
                 * @param {string} text
                 */
                showOverlay: function ( text ) {
                    $('#fb-import-overlay-text').text( text );
                    $('#fb-import-overlay').addClass( 'fb-overlay-visible' );
                    $('body').css( 'overflow', 'hidden' );
                },

                /**
                 * Приховання оверлею.
                 * [FIX-3] Видаляємо клас замість fadeOut() щоб не конфліктувати з flex.
                 */
                hideOverlay: function () {
                    $('#fb-import-overlay').removeClass( 'fb-overlay-visible' );
                    $('body').css( 'overflow', '' );
                },

                /**
                 * Оновлення прогрес-бару та тексту статусу.
                 * @param {number} processed
                 */
                updateProgress: function ( processed ) {
                    var pct = FBImport.total > 0 ? Math.round( processed / FBImport.total * 100 ) : 0;
                    $( '#fb-import-bar' ).css( 'width', pct + '%' );
                    $( '#fb-import-status' ).text(
                        'Оброблено: ' + processed + ' / ' + FBImport.total + ' (' + pct + '%)  |  ' +
                        'Додано: '    + FBImport.inserted + '  |  ' +
                        'Помилок: '  + FBImport.errors
                    );
                    FBImport.showOverlay( 'Імпорт... ' + pct + '% (' + processed + ' / ' + FBImport.total + ')' );
                },

                /**
                 * КРОК 1: Завантаження файлу на сервер.
                 */
                start: function () {
                    var fileInput = document.getElementById('fb-import-file');

                    if ( ! fileInput || ! fileInput.files.length ) {
                        alert( '<?php echo esc_js( __( 'Оберіть CSV-файл для імпорту.', 'family-budget' ) ); ?>' );
                        return;
                    }

                    FBImport.token    = null;
                    FBImport.total    = 0;
                    FBImport.offset   = 0;
                    FBImport.inserted = 0;
                    FBImport.errors   = 0;

                    var fd = new FormData();
                    fd.append( 'action',   'fb_import_upload' );
                    fd.append( 'security', FBImport.nonce );
                    fd.append( 'xls_file', fileInput.files[0] );

                    $( '#fb-import-progress' ).show();
                    $( '#fb-import-bar' ).css({ 'width': '0%', 'background': '#0073aa' });
                    $( '#fb-import-status' ).text( '<?php echo esc_js( __( 'Завантаження файлу...', 'family-budget' ) ); ?>' );
                    FBImport.showOverlay( '<?php echo esc_js( __( 'Завантаження файлу на сервер...', 'family-budget' ) ); ?>' );

                    $.ajax({
                        url         : FBImport.ajaxUrl,
                        method      : 'POST',
                        data        : fd,
                        processData : false,
                        contentType : false,
                        success: function ( resp ) {
                            if ( ! resp.success ) {
                                FBImport.hideOverlay();
                                alert( '<?php echo esc_js( __( 'Помилка:', 'family-budget' ) ); ?> ' + resp.data.message );
                                return;
                            }
                            FBImport.token  = resp.data.token;
                            FBImport.total  = resp.data.total_rows;
                            FBImport.offset = 0;
                            FBImport.processChunk();
                        },
                        error: function () {
                            FBImport.hideOverlay();
                            alert( '<?php echo esc_js( __( 'Помилка підключення до сервера.', 'family-budget' ) ); ?>' );
                        }
                    });
                },

                /**
                 * КРОК 2: Рекурсивна обробка чанків.
                 */
                processChunk: function () {
                    $.post(
                        FBImport.ajaxUrl,
                        {
                            action   : 'fb_import_chunk',
                            security : FBImport.nonce,
                            token    : FBImport.token,
                            offset   : FBImport.offset,
                        },
                        function ( resp ) {
                            if ( ! resp.success ) {
                                FBImport.hideOverlay();
                                alert( '<?php echo esc_js( __( 'Помилка обробки чанку:', 'family-budget' ) ); ?> ' + resp.data.message );
                                return;
                            }
                            var d = resp.data;
                            FBImport.offset   = d.next_offset;
                            FBImport.inserted = d.total_imported;
                            FBImport.errors   = d.total_errors;
                            FBImport.updateProgress( d.processed );

                            if ( d.is_done ) {
                                FBImport.onDone();
                            } else {
                                // Пауза 100мс між чанками — зменшує навантаження на БД.
                                setTimeout( FBImport.processChunk, 100 );
                            }
                        }
                    ).fail(function () {
                        FBImport.hideOverlay();
                        alert( '<?php echo esc_js( __( 'Мережева помилка. Спробуйте ще раз.', 'family-budget' ) ); ?>' );
                    });
                },

                /**
                 * КРОК 3: Завершення імпорту — оновлення UI.
                 */
                onDone: function () {
                    FBImport.hideOverlay();
                    $( '#fb-import-bar' ).css({ 'width': '100%', 'background': '#46b450' });
                    $( '#fb-import-status' ).html(
                        '✅ <strong><?php echo esc_js( __( 'Імпорт завершено!', 'family-budget' ) ); ?></strong> &nbsp;' +
                        '<?php echo esc_js( __( 'Додано:', 'family-budget' ) ); ?> ' + FBImport.inserted + '&nbsp;&nbsp;' +
                        '<?php echo esc_js( __( 'Помилок:', 'family-budget' ) ); ?> ' + FBImport.errors
                    );
                    // Скидаємо file input.
                    var fi = document.getElementById('fb-import-file');
                    if ( fi ) { fi.value = ''; }
                }
            };

            $( document ).ready(function () {
                FBImport.init();
            });

        }(jQuery));
        </script>
        <?php
    }

    // =========================================================================
    // PUBLIC API (ЗВОРОТНА СУМІСНІСТЬ)
    // =========================================================================

    /**
     * Основний метод обробки завантаженого CSV-файлу.
     *
     * Алгоритм:
     *  1. Перевіряє помилки завантаження та ініціює wp_handle_upload().
     *  2. Зчитує рядки CSV та вставляє транзакції у БД.
     *  3. Збирає унікальні дати всіх імпортованих транзакцій.
     *  4. Після циклу ОДНОРАЗОВО синхронізує курси для кожної унікальної дати.
     *  5. Видаляє тимчасовий файл.
     *
     * @since  1.0.20.0
     * @param  array $file      Масив $_FILES['xls_file'] з даними завантаженого файлу.
     * @param  int   $family_id ID родини, до якої імпортуються транзакції.
     * @return array{
     *     success:  bool,
     *     imported: int,
     *     errors:   string[],
     *     message?: string
     * } Результат операції імпорту.
     */
    public static function process_xls_file( array $file, int $family_id ): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Перевірка помилки завантаження.
        if ( UPLOAD_ERR_OK !== $file['error'] ) {
            return array(
                'success' => false,
                'message' => sprintf( 'Upload error code: %d', $file['error'] ),
            );
        }

        $upload = wp_handle_upload( $file, array( 'test_form' => false ) );

        if ( isset( $upload['error'] ) ) {
            return array(
                'success' => false,
                'message' => sanitize_text_field( $upload['error'] ),
            );
        }

        $file_path = $upload['file'];

        error_log( sprintf(
            '[FB Import] START: File=%s, Size=%d bytes, Family=%d',
            basename( $file_path ),
            filesize( $file_path ),
            $family_id
        ) );

        try {
            $handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            if ( ! $handle ) {
                throw new \Exception( 'Cannot open uploaded file for reading.' );
            }

            $delimiter    = ';';
            $imported     = 0;
            $errors       = array();
            $line         = 0;

            /**
             * [OPT-1] Масив унікальних дат для пакетної синхронізації курсів.
             * Заповнюється під час циклу, використовується після нього.
             *
             * @var array<string,true> $imported_dates
             */
            $imported_dates = array();

            // Валюта за замовчуванням для родини.
            $currency_id = self::get_family_primary_currency( $family_id );

            // Пропускаємо рядок-заголовок.
            if ( false !== ( $header = fgetcsv( $handle, 0, $delimiter ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
                $line++;
                error_log( '[FB Import] Header: ' . implode( ' | ', (array) $header ) );
            }

            // Основний цикл обробки рядків.
            while ( false !== ( $data = fgetcsv( $handle, 0, $delimiter ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
                $line++;

                // Пропускаємо порожні рядки.
                if ( empty( array_filter( $data ) ) ) {
                    continue;
                }

                // Мінімальна кількість стовпців (Дата|Тип|Рахунок|Категорія|Сума).
                if ( count( $data ) < 5 ) {
                    $errors[] = sprintf( 'Line %d: Not enough columns (need ≥5, got %d)', $line, count( $data ) );
                    continue;
                }

                // [FIX-1] import_row() більше НЕ викликає do_action() —
                // замість цього повертає дату для пакетного оновлення курсів.
                $result = self::import_row( $data, $family_id, $currency_id, $line );

                if ( $result['success'] ) {
                    $imported++;
                    // Збираємо унікальні дати для пакетної синхронізації.
                    if ( ! empty( $result['date'] ) ) {
                        $imported_dates[ $result['date'] ] = true;
                    }
                } else {
                    $errors[] = $result['message'];
                }
            }

            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            unlink( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

            // [OPT-1] ПАКЕТНА синхронізація курсів після завершення імпорту.
            // Виконується ОДИН РАЗ на унікальну дату, а не на кожен рядок.
            if ( $imported > 0 && ! empty( $imported_dates ) && class_exists( 'FB_Currency_Rates' ) ) {
                self::sync_rates_for_imported_dates( array_keys( $imported_dates ) );
            }

            error_log( sprintf(
                '[FB Import] DONE: Imported=%d, Errors=%d, Dates=%d',
                $imported,
                count( $errors ),
                count( $imported_dates )
            ) );

            return array(
                'success'  => true,
                'imported' => $imported,
                'errors'   => $errors,
            );

        } catch ( \Exception $e ) {
            error_log( '[FB Import] EXCEPTION: ' . $e->getMessage() );
            if ( file_exists( $file_path ) ) {
                unlink( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Обробка та вставка одного рядка CSV-даних у БД.
     *
     * [FIX-1] Метод більше не викликає do_action('fb_after_amount_inserted').
     * Натомість повертає дату вставленої транзакції для пакетної синхронізації курсів.
     *
     * Формат CSV (0-based):
     *  0 — Дата (Y-m-d / d.m.Y / d/m/Y)
     *  1 — Тип операції (Витрата / Дохід / Переказ)
     *  2 — Рахунок
     *  3 — Категорія
     *  4 — Сума (число, роздільник — кома або крапка)
     *  5 — Валюта (опціонально, символ; ігнорується, якщо не знайдено)
     *  6 — Примітка (опціонально)
     *
     * @since  1.0.20.0
     * @param  string[] $data        Масив значень поточного рядка CSV.
     * @param  int      $family_id   ID родини.
     * @param  int      $currency_id ID валюти за замовчуванням для родини.
     * @param  int      $line        Номер рядка у файлі (для повідомлень про помилки).
     * @return array{success: bool, date?: string, message?: string}
     *         При success=true містить ключ 'date' (Y-m-d) вставленої транзакції.
     */
    private static function import_row(
        array $data,
        int $family_id,
        int $currency_id,
        int $line
    ): array {
        global $wpdb;

        $data = array_map( 'trim', $data );

        // Перевірка обов'язкових полів: Дата, Рахунок, Категорія, Сума.
        if ( empty( $data[0] ) || empty( $data[2] ) || empty( $data[3] ) || empty( $data[4] ) ) {
            return array(
                'success' => false,
                'message' => sprintf( 'Line %d: Required fields (date/account/category/amount) are empty.', $line ),
            );
        }

        $date = self::parse_date( $data[0] );
        if ( ! $date ) {
            return array(
                'success' => false,
                'message' => sprintf( "Line %d: Invalid date format '%s'. Supported: Y-m-d, d.m.Y, d/m/Y.", $line, $data[0] ),
            );
        }

        $account_id  = self::get_or_create_account( $data[2], $family_id );
        $category_id = self::get_or_create_category( $data[3], $family_id );

        // Нормалізація числа: пробіли → '', кома → крапка.
        $amount = (float) str_replace( array( ' ', ',' ), array( '', '.' ), $data[4] );

        if ( ! $account_id || ! $category_id ) {
            return array(
                'success' => false,
                'message' => sprintf( 'Line %d: Could not resolve Account or Category.', $line ),
            );
        }

        if ( $amount <= 0 ) {
            return array(
                'success' => false,
                'message' => sprintf( "Line %d: Invalid amount value '%s'.", $line, $data[4] ),
            );
        }

        // Визначення ID типу операції за текстовим значенням.
        $type_id = self::resolve_type_id( $data[1] ?? '' );

        // Примітка (стовпець 6, опціональний).
        $note = ! empty( $data[6] ) ? sanitize_textarea_field( $data[6] ) : '';

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'Amount',
            array(
                'AmountType_ID' => $type_id,
                'Account_ID'    => $account_id,
                'Category_ID'   => $category_id,
                'Currency_ID'   => $currency_id,
                'Amount_Value'  => $amount,
                'Note'          => $note,
                'created_at'    => $date . ' ' . current_time( 'H:i:s' ),
            ),
            array( '%d', '%d', '%d', '%d', '%f', '%s', '%s' )
        );

        if ( false === $inserted ) {
            error_log( sprintf(
                '[FB Import] DB Insert FAILED: Line=%d | Error: %s',
                $line,
                $wpdb->last_error
            ) );
            return array(
                'success' => false,
                'message' => sprintf( 'Line %d: Database insert failed.', $line ),
            );
        }

        // Повертаємо дату для пакетної синхронізації курсів.
        return array(
            'success' => true,
            'date'    => $date,
        );
    }

    /**
     * Cron-обробник: фонова синхронізація курсів валют після завершення імпорту.
     *
     * Запускається через wp_schedule_single_event() з ajax_chunk() коли is_done=true.
     * Виконується в окремому PHP-процесі → не впливає на час відповіді AJAX.
     *
     * @since  3.1.0
     * @param  int $family_id ID родини для логування.
     * @return void
     */
    public static function cron_sync_rates( int $family_id ): void {
        $dates = get_transient( 'fb_sync_rates_' . $family_id );

        if ( empty( $dates ) || ! is_array( $dates ) ) {
            error_log( sprintf( '[FB Import CRON] No dates found for family %d', $family_id ) );
            return;
        }

        delete_transient( 'fb_sync_rates_' . $family_id );

        error_log( sprintf(
            '[FB Import CRON %s] START sync: %d dates for family %d',
            current_time( 'Y-m-d H:i:s' ),
            count( $dates ),
            $family_id
        ) );

        if ( class_exists( 'FB_Currency_Rates' ) ) {
            self::sync_rates_for_imported_dates( $dates );
        } else {
            error_log( '[FB Import CRON] SKIP: class FB_Currency_Rates not found.' );
        }

        error_log( sprintf(
            '[FB Import CRON %s] DONE sync for family %d',
            current_time( 'Y-m-d H:i:s' ),
            $family_id
        ) );
    }

    /**
     * Пакетна синхронізація курсів валют для масиву унікальних дат.
     *
     * Викликається ОДНОРАЗОВО після завершення всього циклу імпорту.
     * FB_Currency_Rates::fetch_and_save_rates() використовує Transients,
     * тому повторні виклики з однаковою датою не роблять нових HTTP-запитів.
     *
     * @since  2.0.0
     * @param  string[] $dates Масив дат у форматі Y-m-d.
     * @return void
     */
    private static function sync_rates_for_imported_dates( array $dates ): void {
        if ( empty( $dates ) ) {
            return;
        }

        error_log( sprintf(
            '[FB Import] Sync rates: %d unique date(s): %s',
            count( $dates ),
            implode( ', ', $dates )
        ) );

        foreach ( $dates as $date ) {
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                continue;
            }

            $result = FB_Currency_Rates::fetch_and_save_rates( $date );

            if ( $result ) {
                error_log( sprintf( '[FB Import] Rates synced OK for date: %s', $date ) );
            } else {
                error_log( sprintf( '[FB Import] Rates sync FAILED for date: %s', $date ) );
            }
        }
    }

    /**
     * Визначає ID типу операції за текстовим значенням з CSV.
     *
     * Підтримує українські та російські варіанти написання.
     * За замовчуванням повертає 1 (Витрата).
     *
     * @since  2.0.0
     * @param  string $label Текстовий тип операції з CSV-файлу.
     * @return int ID типу: 1 — Витрата, 2 — Переказ, 3 — Дохід.
     */
    private static function resolve_type_id( string $label ): int {
        $label = mb_strtolower( trim( $label ), 'UTF-8' );

        if ( mb_strpos( $label, 'дохід' ) !== false || mb_strpos( $label, 'доход' ) !== false ) {
            return 3;
        }

        if ( mb_strpos( $label, 'переказ' ) !== false || mb_strpos( $label, 'перевод' ) !== false ) {
            return 2;
        }

        return 1; // Витрата за замовчуванням.
    }

    /**
     * Отримує ID головної (первинної) валюти родини.
     *
     * [SCHEMA-v2] Поля Family_ID та Currency_Primary перенесені до таблиці
     * CurrencyFamily. Запит використовує JOIN для отримання Currency_ID
     * головної валюти родини.
     *
     * @since  1.0.20.0
     * @param  int $family_id ID родини.
     * @return int ID валюти (Currency.id). Повертає 1 як fallback, якщо не знайдено.
     */
    private static function get_family_primary_currency( int $family_id ): int {
        global $wpdb;

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT cf.Currency_ID
             FROM {$wpdb->prefix}CurrencyFamily AS cf
             WHERE cf.Family_ID = %d
             ORDER BY cf.CurrencyFamily_Primary DESC, cf.id ASC
             LIMIT 1",
            $family_id
        ) );

        return $id ? (int) $id : 1;
    }

    /**
     * Парсить рядок дати у форматі Y-m-d.
     *
     * Підтримує формати: Y-m-d, d.m.Y, d/m/Y, Y/m/d, d.m.y.
     * Як fallback використовує strtotime().
     *
     * @since  1.0.20.0
     * @param  string $str Рядок дати з CSV-файлу.
     * @return string|false Дата у форматі Y-m-d або false, якщо парсинг неможливий.
     */
    private static function parse_date( string $str ): string|false {
        $formats = array( 'Y-m-d', 'd.m.Y', 'd/m/Y', 'Y/m/d', 'd.m.y' );

        foreach ( $formats as $fmt ) {
            $d = \DateTime::createFromFormat( $fmt, $str );
            if ( $d && $d->format( $fmt ) === $str ) {
                return $d->format( 'Y-m-d' );
            }
        }

        $timestamp = strtotime( $str );
        return false !== $timestamp ? gmdate( 'Y-m-d', $timestamp ) : false;
    }

    /**
     * Знаходить або створює рахунок для родини за назвою.
     *
     * @since  1.0.20.0
     * @param  string $name      Назва рахунку з CSV.
     * @param  int    $family_id ID родини.
     * @return int ID рахунку або 0 у разі помилки.
     */
    private static function get_or_create_account( string $name, int $family_id ): int {
        global $wpdb;

        $name = sanitize_text_field( $name );

        if ( empty( $name ) ) {
            return 0;
        }

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id
             FROM {$wpdb->prefix}Account
             WHERE Account_Name = %s AND Family_ID = %d
             LIMIT 1",
            $name,
            $family_id
        ) );

        if ( $id ) {
            return (int) $id;
        }

        $wpdb->insert(
            $wpdb->prefix . 'Account',
            array(
                'Family_ID'      => $family_id,
                'AccountType_ID' => 1, // 'Готівка' за замовчуванням.
                'Account_Name'   => $name,
                'Account_Order'  => 999,
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%d', '%s' )
        );

        if ( $wpdb->last_error ) {
            error_log( '[FB Import] Account create FAILED: ' . $wpdb->last_error );
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Зберігає завантажений файл у захищену директорію uploads/fb-imports/.
     *
     * Директорія захищена від прямого доступу через .htaccess.
     *
     * @since  3.0.0
     * @param  array $file Масив $_FILES['xls_file'].
     * @return string|WP_Error Шлях до збереженого файлу або WP_Error.
     */
    private static function save_uploaded_file( array $file ): string|\WP_Error {
        $upload_dir = wp_upload_dir();
        $import_dir = trailingslashit( $upload_dir['basedir'] ) . 'fb-imports/';

        if ( ! wp_mkdir_p( $import_dir ) ) {
            return new \WP_Error( 'mkdir_failed', __( 'Не вдалося створити директорію для імпорту.', 'family-budget' ) );
        }

        // Захист від прямого доступу через браузер.
        $htaccess = $import_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, 'Deny from all' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        $filename    = 'fb_import_' . get_current_user_id() . '_' . time() . '.csv';
        $destination = $import_dir . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
            return new \WP_Error( 'move_failed', __( 'Не вдалося зберегти файл на сервер.', 'family-budget' ) );
        }

        return $destination;
    }

    /**
     * Підраховує кількість рядків у CSV-файлі.
     *
     * @since  3.0.0
     * @param  string $filepath Шлях до файлу.
     * @return int Кількість рядків (включно із заголовком).
     */
    private static function count_csv_rows( string $filepath ): int {
        $count  = 0;
        $handle = fopen( $filepath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $handle ) {
            return 0;
        }
        while ( fgetcsv( $handle ) !== false ) {
            $count++;
        }
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return $count;
    }

    /**
     * Знаходить або створює категорію для родини за назвою.
     *
     * @since  1.0.20.0
     * @param  string $name      Назва категорії з CSV.
     * @param  int    $family_id ID родини.
     * @return int ID категорії або 0 у разі помилки.
     */
    private static function get_or_create_category( string $name, int $family_id ): int {
        global $wpdb;

        $name = sanitize_text_field( $name );

        if ( empty( $name ) ) {
            return 0;
        }

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id
             FROM {$wpdb->prefix}Category
             WHERE Category_Name = %s AND Family_ID = %d
             LIMIT 1",
            $name,
            $family_id
        ) );

        if ( $id ) {
            return (int) $id;
        }

        $wpdb->insert(
            $wpdb->prefix . 'Category',
            array(
                'Family_ID'       => $family_id,
                'CategoryType_ID' => 1, // 'Витрати' за замовчуванням.
                'Category_Name'   => $name,
                'Category_Order'  => 999,
                'created_at'      => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%d', '%s' )
        );

        if ( $wpdb->last_error ) {
            error_log( '[FB Import] Category create FAILED: ' . $wpdb->last_error );
            return 0;
        }

        return (int) $wpdb->insert_id;
    }
}

// ============================================================================
// Реєстрація AJAX-хуків та wp_footer при підключенні файлу.
// ============================================================================
FB_Import::register_hooks();
