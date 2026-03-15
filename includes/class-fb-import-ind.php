<?php
/**
 * Клас FB_Import_Ind — Імпорт показників лічильників з CSV-файлів
 *
 * CSV-формат (розділювач «;», перший рядок — заголовок, пропускається):
 *   Колонка 1: accounts_number — номер особового рахунку
 *   Колонка 2: month           — місяць (1–12)
 *   Колонка 3: year            — рік (1960–2060)
 *   Колонка 4: value1          — показник лічильника 1 (може бути порожнім)
 *   Колонка 5: value2          — показник лічильника 2 (може бути порожнім)
 *   Колонка 6: consumed        — спожито (обов'язково, >= 0)
 *   Колонка 7: sum             — сума для автоматичної прив'язки до платежу
 *
 * Логіка захисту від перезапису:
 *   – indicators_import = 0 → рядок введено або відредаговано вручну → ПРОПУСТИТИ.
 *   – indicators_import = 1 → рядок раніше імпортований → ОНОВИТИ.
 *   – Запис відсутній       → ВСТАВИТИ з indicators_import = 1.
 *
 * Вимога: міграція v1.2.0 (class-fb-migrations-v3.php) має бути виконана
 * до першого використання цього класу.
 *
 * @package    FamilyBudget
 * @subpackage Import
 * @since      1.2.0
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Клас для імпорту показників лічильників з CSV-файлів.
 *
 * @since 1.2.0
 */
class FB_Import_Ind {

	/**
	 * Роздільник колонок у CSV-файлі.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const CSV_DELIMITER = ';';

	/**
	 * Мінімальна кількість очікуваних колонок у рядку.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const CSV_MIN_COLS = 7;

	/**
	 * Максимально допустимий розмір CSV-файлу (5 МБ).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_FILE_SIZE = 5242880;

	// =========================================================================
	// ІНІЦІАЛІЗАЦІЯ ХУКІВ
	// =========================================================================

	/**
	 * Реєструє всі AJAX-хуки класу.
	 *
	 * Викликається у нижній частині файлу після оголошення класу.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_ajax_fb_ind_import', array( self::class, 'ajax_import' ) );
	}

	// =========================================================================
	// AJAX — ЗАВАНТАЖЕННЯ ТА ОБРОБКА CSV
	// =========================================================================

	/**
	 * AJAX-обробник: завантаження та повна обробка CSV-файлу показників.
	 *
	 * Порядок виконання:
	 *  1. Перевірка nonce та авторизації.
	 *  2. Валідація та збереження файлу у захищену директорію.
	 *  3. Обробка всіх рядків з результуючою статистикою.
	 *  4. Видалення тимчасового файлу.
	 *  5. Повернення JSON-звіту.
	 *
	 * @since  1.2.0
	 * @return void Надсилає JSON-відповідь та завершує виконання.
	 */
	public static function ajax_import(): void {
		check_ajax_referer( 'fb_ind_import_nonce', 'security' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Сесія завершена. Оновіть сторінку.' ) );
		}

		// Перевірка наявності та цілісності файлу
		if ( empty( $_FILES['csv_file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['csv_file']['error'] ) {
			wp_send_json_error( array( 'message' => 'Файл не отримано або сталася помилка завантаження.' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = $_FILES['csv_file'];
		$ext  = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

		if ( 'csv' !== $ext ) {
			wp_send_json_error( array( 'message' => 'Дозволені лише CSV-файли (.csv).' ) );
		}

		if ( (int) $file['size'] > self::MAX_FILE_SIZE ) {
			wp_send_json_error( array( 'message' => 'Файл завеликий. Максимально допустимий розмір: 5 МБ.' ) );
		}

		$filepath = self::save_uploaded_file( $file );
		if ( is_wp_error( $filepath ) ) {
			wp_send_json_error( array( 'message' => $filepath->get_error_message() ) );
		}

		$uid    = get_current_user_id();
		$result = self::process_csv( $filepath, $uid );

		// Видаляємо тимчасовий файл незалежно від результату
		wp_delete_file( $filepath );

		wp_send_json_success( $result );
	}

	// =========================================================================
	// ОБРОБКА CSV
	// =========================================================================

	/**
	 * Читає та обробляє всі рядки CSV-файлу.
	 *
	 * Перший рядок завжди пропускається як заголовок.
	 * Автоматично усуває BOM (Byte Order Mark) на початку файлу.
	 *
	 * @since  1.2.0
	 * @param  string $filepath Абсолютний шлях до CSV-файлу на сервері.
	 * @param  int    $uid      ID поточного користувача WordPress.
	 * @return array{imported:int, errors:int, failed_rows:string[], debug_log:array[]} Підсумок обробки.
	 */
	private static function process_csv( string $filepath, int $uid ): array {
		// Знімаємо BOM (UTF-8 з BOM: EF BB BF)
		$raw = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false !== $raw ) {
			$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
			file_put_contents( $filepath, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$handle = fopen( $filepath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return array(
				'imported'    => 0,
				'errors'      => 1,
				'failed_rows' => array( 'Не вдалося відкрити файл для читання.' ),
				'debug_log'   => array(),
			);
		}

		// Пропускаємо рядок-заголовок
		fgetcsv( $handle, 0, self::CSV_DELIMITER );

		$imported    = 0;
		$errors      = 0;
		$failed_rows = array();
		$debug_log   = array();
		$row_num     = 1;

		while ( ( $cols = fgetcsv( $handle, 0, self::CSV_DELIMITER ) ) !== false ) {
			++$row_num;

			// Нормалізуємо — видаляємо пробіли та \r навколо значень (Windows CSV)
			$cols = array_map( 'trim', $cols );

			// Перевірка мінімальної кількості колонок
			if ( count( $cols ) < self::CSV_MIN_COLS ) {
				++$errors;
				$failed_rows[] = sprintf(
					'Рядок %d: замало колонок (%d/%d) — %s',
					$row_num,
					count( $cols ),
					self::CSV_MIN_COLS,
					implode( self::CSV_DELIMITER, $cols )
				);
				$debug_log[] = array(
					'row'    => $row_num,
					'action' => 'error',
					'msg'    => 'замало колонок',
				);
				continue;
			}

			$error = self::process_row( $cols, $uid, $row_num, $imported, $debug_log );
			if ( null !== $error ) {
				++$errors;
				$failed_rows[] = $error;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'imported'    => $imported,
			'errors'      => $errors,
			'failed_rows' => $failed_rows,
			'debug_log'   => $debug_log,
		);
	}

	/**
	 * Обробляє один рядок CSV.
	 *
	 * Повертає рядок з описом помилки або null при успішному записі.
	 * Лічильник $imported передається за посиланням та збільшується на 1 при успіху.
	 * $debug_log — масив записів відлагодження, поповнюється після кожного кроку.
	 *
	 * @since  1.2.0
	 * @param  string[] $cols       Масив значень колонок (вже оброблені trim).
	 * @param  int      $uid        ID поточного користувача WordPress.
	 * @param  int      $row_num    Номер рядка (для звіту про помилки).
	 * @param  int      $imported   Лічильник успішних записів (за посиланням).
	 * @param  array    $debug_log  Масив записів відлагодження (за посиланням).
	 * @return string|null          Опис помилки або null при успіху.
	 */
	private static function process_row( array $cols, int $uid, int $row_num, int &$imported, array &$debug_log ): ?string {
		global $wpdb;

		$raw = implode( self::CSV_DELIMITER, $cols );

		// Запис відлагодження для цього рядка
		$dbg = array(
			'row'        => $row_num,
			'account'    => '',
			'month'      => 0,
			'year'       => 0,
			'sum_raw'    => '',
			'sum_str'    => '',
			'pa_id'      => 0,
			'pa_sql'     => '',
			'amount_sql' => '',
			'matches'    => 0,
			'amount_id'  => 0,
			'ind_id'     => 0,
			'action'     => 'pending',
			'linked'     => false,
			'error'      => null,
		);

		// ── Парсинг та санітизація ────────────────────────────────────────────
		$account_number  = sanitize_text_field( $cols[0] );
		$month           = absint( $cols[1] );
		$year            = absint( $cols[2] );
		$val1_raw        = $cols[3];
		$val2_raw        = $cols[4];
		$consumed_raw    = $cols[5];
		$sum_raw         = $cols[6] ?? '';

		$dbg['account'] = $account_number;
		$dbg['month']   = $month;
		$dbg['year']    = $year;
		$dbg['sum_raw'] = $sum_raw;

		// Нормалізація десяткового роздільника (кома → крапка)
		$val1     = '' !== $val1_raw     ? (float) str_replace( ',', '.', $val1_raw )     : null;
		$val2     = '' !== $val2_raw     ? (float) str_replace( ',', '.', $val2_raw )     : null;
		$consumed = '' !== $consumed_raw ? (float) str_replace( ',', '.', $consumed_raw ) : null;
		$sum      = '' !== $sum_raw      ? (float) str_replace( ',', '.', $sum_raw )      : null;

		// ── Валідація ─────────────────────────────────────────────────────────
		if ( '' === $account_number ) {
			$dbg['action'] = 'error'; $dbg['error'] = 'порожній номер рахунку';
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: порожній номер рахунку — {$raw}";
		}
		if ( $month < 1 || $month > 12 ) {
			$dbg['action'] = 'error'; $dbg['error'] = "некоректний місяць: {$cols[1]}";
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: некоректний місяць ({$cols[1]}) — {$raw}";
		}
		if ( $year < 1960 || $year > 2060 ) {
			$dbg['action'] = 'error'; $dbg['error'] = "некоректний рік: {$cols[2]}";
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: некоректний рік ({$cols[2]}) — {$raw}";
		}
		if ( null === $consumed || $consumed < 0 ) {
			$dbg['action'] = 'error'; $dbg['error'] = "некоректне Спожито: {$cols[5]}";
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: некоректне поле «Спожито» ({$cols[5]}) — {$raw}";
		}
		if ( null !== $val1 && $val1 < 0 ) {
			$dbg['action'] = 'error'; $dbg['error'] = 'від\'ємне val1';
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: від'ємне значення лічильника 1 — {$raw}";
		}
		if ( null !== $val2 && $val2 < 0 ) {
			$dbg['action'] = 'error'; $dbg['error'] = 'від\'ємне val2';
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: від'ємне значення лічильника 2 — {$raw}";
		}

		// ── Пошук особового рахунку ───────────────────────────────────────────
		$pa_id = self::find_personal_account( $account_number, $uid );
		$dbg['pa_id']  = $pa_id;
		$dbg['pa_sql'] = $wpdb->last_query;

		if ( ! $pa_id ) {
			$dbg['action'] = 'error'; $dbg['error'] = "рахунок «{$account_number}» не знайдено";
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: рахунок «{$account_number}» не знайдено або не належить вам — {$raw}";
		}

		// ── Перевірка існуючого запису ────────────────────────────────────────
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, indicators_import
			 FROM {$wpdb->prefix}indicators
			 WHERE id_personal_accounts = %d
			   AND indicators_month     = %d
			   AND indicators_year      = %d
			 LIMIT 1",
			$pa_id, $month, $year
		) );

		if ( $existing && 0 === (int) $existing->indicators_import ) {
			$dbg['action'] = 'skip'; $dbg['ind_id'] = (int) $existing->id;
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: показник {$account_number}/{$month}/{$year} вже існує (ручне введення, пропущено) — {$raw}";
		}

		// ── Вставка або оновлення ─────────────────────────────────────────────
		if ( $existing ) {
			$ind_id = self::update_indicator( (int) $existing->id, $consumed, $val1, $val2 );
			$dbg['action'] = 'update';
		} else {
			$ind_id = self::insert_indicator( $pa_id, $month, $year, $consumed, $val1, $val2 );
			$dbg['action'] = 'insert';
		}
		$dbg['ind_id'] = $ind_id;

		if ( ! $ind_id ) {
			$dbg['error'] = 'DB error: ' . $wpdb->last_error;
			$debug_log[] = $dbg;
			return "Рядок {$row_num}: помилка запису в базу даних ({$wpdb->last_error}) — {$raw}";
		}

		// ── Автоматична прив'язка платежу ─────────────────────────────────────
		if ( null !== $sum && $sum > 0 ) {
			$sum_str            = number_format( $sum, 2, '.', '' );
			$dbg['sum_str']     = $sum_str;

			[ $amount_id, $amount_sql, $matches ] = self::find_unique_amount( $sum_str, $uid );

			$dbg['amount_sql'] = $amount_sql;
			$dbg['matches']    = $matches;
			$dbg['amount_id']  = $amount_id;

			if ( $amount_id ) {
				self::link_indicator_amount( $ind_id, $amount_id );
				$dbg['linked'] = true;
			}
		}

		++$imported;
		$debug_log[] = $dbg;
		return null;
	}

	// =========================================================================
	// ДОПОМІЖНІ МЕТОДИ — БАЗА ДАНИХ
	// =========================================================================

	/**
	 * Знаходить ID особового рахунку за номером у межах родин поточного користувача.
	 *
	 * Ланцюжок ізоляції: personal_accounts → houses → house_family → UserFamily → User.
	 *
	 * @since  1.2.0
	 * @param  string $number Номер особового рахунку з CSV.
	 * @param  int    $uid    ID поточного користувача WordPress.
	 * @return int ID рахунку або 0 якщо не знайдено.
	 */
	private static function find_personal_account( string $number, int $uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT pa.id
			 FROM {$wpdb->prefix}personal_accounts pa
			 INNER JOIN {$wpdb->prefix}houses       h  ON pa.id_houses  = h.id
			 INNER JOIN {$wpdb->prefix}house_family hf ON h.id          = hf.id_houses
			 INNER JOIN {$wpdb->prefix}UserFamily   uf ON hf.id_family  = uf.Family_ID
			 WHERE pa.personal_accounts_number = %s
			   AND uf.User_ID = %d
			 LIMIT 1",
			$number,
			$uid
		) );
	}

	/**
	 * Вставляє новий запис показника з прапорцем indicators_import = 1.
	 *
	 * @since  1.2.0
	 * @param  int        $pa_id    ID особового рахунку.
	 * @param  int        $month    Місяць (1–12).
	 * @param  int        $year     Рік.
	 * @param  float      $consumed Спожито.
	 * @param  float|null $val1     Показник лічильника 1 (null = пропустити поле).
	 * @param  float|null $val2     Показник лічильника 2 (null = пропустити поле).
	 * @return int ID вставленого запису або 0 у разі помилки.
	 */
	private static function insert_indicator(
		int $pa_id,
		int $month,
		int $year,
		float $consumed,
		?float $val1,
		?float $val2
	): int {
		global $wpdb;

		$data    = array();
		$formats = array();

		$data['id_personal_accounts'] = $pa_id;                $formats[] = '%d';
		$data['indicators_month']     = $month;                 $formats[] = '%d';
		$data['indicators_year']      = $year;                  $formats[] = '%d';
		$data['indicators_consumed']  = $consumed;              $formats[] = '%f';
		$data['indicators_import']    = 1;                      $formats[] = '%d';
		$data['created_at']           = current_time( 'mysql' ); $formats[] = '%s';

		if ( null !== $val1 ) { $data['indicators_value1'] = $val1; $formats[] = '%f'; }
		if ( null !== $val2 ) { $data['indicators_value2'] = $val2; $formats[] = '%f'; }

		$wpdb->insert( "{$wpdb->prefix}indicators", $data, $formats );

		if ( $wpdb->last_error ) {
			error_log( '[FB Import Ind] INSERT error: ' . $wpdb->last_error );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Оновлює існуючий (раніше імпортований) показник.
	 *
	 * val1 та val2 оновлюються лише якщо задані в CSV (не порожній рядок).
	 * Порожнє поле у CSV = зберегти існуюче значення в БД.
	 *
	 * @since  1.2.0
	 * @param  int        $id       ID запису для оновлення.
	 * @param  float      $consumed Спожито.
	 * @param  float|null $val1     Показник 1 або null (не змінювати).
	 * @param  float|null $val2     Показник 2 або null (не змінювати).
	 * @return int ID запису або 0 у разі помилки БД.
	 */
	private static function update_indicator( int $id, float $consumed, ?float $val1, ?float $val2 ): int {
		global $wpdb;

		$data    = array( 'indicators_consumed' => $consumed, 'indicators_import' => 1 );
		$formats = array( '%f', '%d' );

		if ( null !== $val1 ) { $data['indicators_value1'] = $val1; $formats[] = '%f'; }
		if ( null !== $val2 ) { $data['indicators_value2'] = $val2; $formats[] = '%f'; }

		$result = $wpdb->update(
			"{$wpdb->prefix}indicators",
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			error_log( '[FB Import Ind] UPDATE error: ' . $wpdb->last_error );
			return 0;
		}

		// $result === 0 означає "значення ідентичні" — це не помилка
		return $id;
	}

	/**
	 * Шукає рівно один платіж у таблиці Amount лише за сумою.
	 *
	 * Фільтр по місяцю/року ВИДАЛЕНО — пошук виключно по сумі та родині.
	 * Правило одного збігу: РІВНО ОДИН запис → повертає його ID.
	 * Нуль або кілька збігів → повертає 0 (прив'язка пропускається).
	 *
	 * Повертає масив: [amount_id, sql_що_виконувався, кількість_збігів].
	 *
	 * @since  1.2.0
	 * @param  string $sum_str Нормалізована сума (формат '318.67').
	 * @param  int    $uid     ID поточного користувача (ізоляція родини).
	 * @return array{0:int, 1:string, 2:int} [amount_id, executed_sql, matches_count].
	 */
	private static function find_unique_amount( string $sum_str, int $uid ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT a.id
			 FROM {$wpdb->prefix}Amount a
			 INNER JOIN {$wpdb->prefix}Account    acc ON a.Account_ID  = acc.id
			 INNER JOIN {$wpdb->prefix}UserFamily  uf ON acc.Family_ID = uf.Family_ID
			 WHERE a.Amount_Value = %s
			   AND uf.User_ID    = %d",
			$sum_str,
			$uid
		);

		$matches = $wpdb->get_col( $sql );
		$count   = count( $matches );

		return array(
			1 === $count ? (int) $matches[0] : 0,
			$sql,
			$count,
		);
	}

	/**
	 * Прив'язує показник до платежу через таблицю indicator_amount.
	 *
	 * Ідемпотентний: перевіряє дублікат перед вставкою — повторний виклик безпечний.
	 *
	 * @since  1.2.0
	 * @param  int $ind_id    ID запису indicators.
	 * @param  int $amount_id ID запису Amount.
	 * @return void
	 */
	private static function link_indicator_amount( int $ind_id, int $amount_id ): void {
		global $wpdb;

		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}indicator_amount
			 WHERE id_indicators = %d AND id_amount = %d",
			$ind_id,
			$amount_id
		) );

		if ( ! $exists ) {
			$wpdb->insert(
				"{$wpdb->prefix}indicator_amount",
				array(
					'id_indicators' => $ind_id,
					'id_amount'     => $amount_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	// =========================================================================
	// ЗБЕРЕЖЕННЯ ФАЙЛУ
	// =========================================================================

	/**
	 * Зберігає завантажений CSV-файл у захищену директорію uploads/fb-imports/.
	 *
	 * Директорія захищена від прямого доступу через .htaccess (Deny from all).
	 * Ім'я файлу містить ID користувача та timestamp для унікальності.
	 *
	 * @since  1.2.0
	 * @param  array $file Масив $_FILES['csv_file'].
	 * @return string|\WP_Error Абсолютний шлях до збереженого файлу або WP_Error.
	 */
	private static function save_uploaded_file( array $file ): string|\WP_Error {
		$upload_dir = wp_upload_dir();
		$import_dir = trailingslashit( $upload_dir['basedir'] ) . 'fb-imports/';

		if ( ! wp_mkdir_p( $import_dir ) ) {
			return new \WP_Error( 'mkdir_failed', 'Не вдалося створити директорію для імпорту.' );
		}

		// Захист від прямого доступу через браузер
		$htaccess = $import_dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, 'Deny from all' . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$filename    = 'fb_ind_' . get_current_user_id() . '_' . time() . '.csv';
		$destination = $import_dir . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
			return new \WP_Error( 'move_failed', 'Не вдалося зберегти файл на сервер.' );
		}

		return $destination;
	}
}

// ============================================================================
// Реєстрація AJAX-хуків
// ============================================================================
FB_Import_Ind::register_hooks();
