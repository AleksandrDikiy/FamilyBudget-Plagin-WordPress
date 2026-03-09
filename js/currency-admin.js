/**
 * Family Budget — Модуль управління валютами
 *
 * AJAX-логіка для CRUD-операцій над довідником валют.
 * Залежності: jQuery, wp_localize_script (fbCurrencyAdmin).
 *
 * @package    FamilyBudget
 * @since      1.0.0
 */

/* global fbCurrencyAdmin */
( function ( $ ) {
	'use strict';

	// ── Кешування DOM-елементів ─────────────────────────────────────────────
	const $wrap    = $( '#fb-currency-wrap' );
	const $tbody   = $( '#fb-currency-tbody' );
	const $idInput = $( '#fb-currency-id' );
	const $code    = $( '#fb-currency-code' );
	const $name    = $( '#fb-currency-name' );
	const $symbol  = $( '#fb-currency-symbol' );
	const $btnSave = $( '#fb-currency-save' );
	const $btnCancel = $( '#fb-currency-cancel' );
	const $notice  = $( '#fb-currency-notice' );
	const i18n     = fbCurrencyAdmin.i18n;

	// ── Допоміжні функції ───────────────────────────────────────────────────

	/**
	 * Відображає повідомлення користувачу.
	 *
	 * @param {string}  msg   Текст повідомлення.
	 * @param {boolean} isErr true — помилка (червоний), false — успіх (зелений).
	 */
	function fbShowNotice( msg, isErr ) {
		$notice
			.removeClass( 'fb-notice-success fb-notice-error' )
			.addClass( isErr ? 'fb-notice-error' : 'fb-notice-success' )
			.text( msg )
			.show();

		clearTimeout( fbShowNotice._timer );
		fbShowNotice._timer = setTimeout( function () {
			$notice.fadeOut();
		}, 4000 );
	}

	/**
	 * Будує HTML-рядок таблиці з об'єкта валюти.
	 *
	 * @param  {Object} c Об'єкт валюти з полями id, Currency_Code, Currency_Name, Currency_Symbol.
	 * @return {string}   HTML-рядок <tr>.
	 */
	function fbBuildRow( c ) {
		return '<tr data-id="' + parseInt( c.id, 10 ) + '">' +
			'<td>' + parseInt( c.id, 10 ) + '</td>' +
			'<td><strong>' + fbEscHtml( c.Currency_Code ) + '</strong></td>' +
			'<td>' + fbEscHtml( c.Currency_Name ) + '</td>' +
			'<td>' + fbEscHtml( c.Currency_Symbol ) + '</td>' +
			'<td>' +
				'<button type="button" class="fb-btn-icon fb-edit-btn" data-id="' + parseInt( c.id, 10 ) + '" title="' + fbEscAttr( i18n.btn_update ) + '">✏️</button> ' +
				'<button type="button" class="fb-btn-icon fb-delete-btn" data-id="' + parseInt( c.id, 10 ) + '" title="' + fbEscAttr( i18n.confirm_delete ) + '" style="color:red;">🗑️</button>' +
			'</td>' +
		'</tr>';
	}

	/**
	 * Мінімальне HTML-екранування для рядків (аналог esc_html у PHP).
	 *
	 * @param  {string} str Вхідний рядок.
	 * @return {string}     Безпечний HTML-рядок.
	 */
	function fbEscHtml( str ) {
		return $( '<span>' ).text( str || '' ).html();
	}

	/**
	 * Мінімальне екранування для атрибутів HTML.
	 *
	 * @param  {string} str Вхідний рядок.
	 * @return {string}     Безпечний рядок для атрибута.
	 */
	function fbEscAttr( str ) {
		return ( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/** Скидає форму до стану "Додати". */
	function fbResetForm() {
		$idInput.val( '0' );
		$code.val( '' );
		$name.val( '' );
		$symbol.val( '' );
		$btnSave.text( i18n.btn_add );
		$btnCancel.hide();
		$code.focus();
	}

	/** Перемикає стан кнопки збереження (вкл/викл). */
	function fbToggleSaveBtn( loading ) {
		$btnSave.prop( 'disabled', loading );
	}

	// ── AJAX: Завантаження списку ───────────────────────────────────────────

	/**
	 * Завантажує список валют та рендерить таблицю.
	 */
	function fbLoadCurrencies() {
		$tbody.html( '<tr><td colspan="5">Завантаження…</td></tr>' );

		$.post( fbCurrencyAdmin.ajax_url, {
			action:   'fb_currency_list',
			security: fbCurrencyAdmin.nonce,
		} )
		.done( function ( resp ) {
			if ( ! resp.success ) {
				$tbody.html( '<tr><td colspan="5">' + fbEscHtml( resp.data.message ) + '</td></tr>' );
				return;
			}

			const currencies = resp.data.currencies;

			if ( ! currencies || 0 === currencies.length ) {
				$tbody.html( '<tr><td colspan="5">Записів не знайдено.</td></tr>' );
				return;
			}

			let html = '';
			$.each( currencies, function ( i, c ) {
				html += fbBuildRow( c );
			} );
			$tbody.html( html );
		} )
		.fail( function () {
			$tbody.html( '<tr><td colspan="5">Помилка з\'єднання.</td></tr>' );
		} );
	}

	// ── AJAX: Збереження (додавання / редагування) ──────────────────────────

	$btnSave.on( 'click', function () {
		const id     = parseInt( $idInput.val(), 10 ) || 0;
		const code   = $.trim( $code.val() );
		const name   = $.trim( $name.val() );
		const symbol = $.trim( $symbol.val() );

		if ( ! code || ! name ) {
			fbShowNotice( i18n.error_required, true );
			return;
		}

		fbToggleSaveBtn( true );

		$.post( fbCurrencyAdmin.ajax_url, {
			action:          'fb_currency_save',
			security:        fbCurrencyAdmin.nonce,
			id:              id,
			currency_code:   code,
			currency_name:   name,
			currency_symbol: symbol,
		} )
		.done( function ( resp ) {
			if ( ! resp.success ) {
				fbShowNotice( resp.data.message || 'Помилка.', true );
				return;
			}

			fbShowNotice( resp.data.message, false );
			const c = resp.data.currency;

			if ( id > 0 ) {
				// Оновлюємо існуючий рядок без перезавантаження всієї таблиці.
				$tbody.find( 'tr[data-id="' + id + '"]' ).replaceWith( fbBuildRow( c ) );
			} else {
				// Додаємо новий рядок у кінець таблиці.
				if ( $tbody.find( 'tr[data-id]' ).length === 0 ) {
					$tbody.empty();
				}
				$tbody.append( fbBuildRow( c ) );
			}

			fbResetForm();
		} )
		.fail( function () {
			fbShowNotice( 'Помилка з\'єднання.', true );
		} )
		.always( function () {
			fbToggleSaveBtn( false );
		} );
	} );

	// ── AJAX: Редагування (заповнення форми) ────────────────────────────────

	$tbody.on( 'click', '.fb-edit-btn', function () {
		const id  = parseInt( $( this ).data( 'id' ), 10 );
		const $tr = $tbody.find( 'tr[data-id="' + id + '"]' );
		const tds = $tr.find( 'td' );

		$idInput.val( id );
		$code.val( $.trim( tds.eq( 1 ).text() ) );
		$name.val( $.trim( tds.eq( 2 ).text() ) );
		$symbol.val( $.trim( tds.eq( 3 ).text() ) );

		$btnSave.text( i18n.btn_update );
		$btnCancel.show();

		// Плавний скрол до форми.
		$wrap[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
		$code.focus();
	} );

	// ── Скасування редагування ──────────────────────────────────────────────

	$btnCancel.on( 'click', function () {
		fbResetForm();
	} );

	// ── AJAX: Видалення ─────────────────────────────────────────────────────

	$tbody.on( 'click', '.fb-delete-btn', function () {
		if ( ! window.confirm( i18n.confirm_delete ) ) {
			return;
		}

		const $btn = $( this );
		const id   = parseInt( $btn.data( 'id' ), 10 );

		$btn.prop( 'disabled', true );

		$.post( fbCurrencyAdmin.ajax_url, {
			action:   'fb_currency_delete',
			security: fbCurrencyAdmin.nonce,
			id:       id,
		} )
		.done( function ( resp ) {
			if ( ! resp.success ) {
				fbShowNotice( resp.data.message || 'Помилка видалення.', true );
				$btn.prop( 'disabled', false );
				return;
			}

			fbShowNotice( resp.data.message, false );
			$tbody.find( 'tr[data-id="' + id + '"]' ).fadeOut( 300, function () {
				$( this ).remove();
				if ( 0 === $tbody.find( 'tr[data-id]' ).length ) {
					$tbody.html( '<tr><td colspan="5">Записів не знайдено.</td></tr>' );
				}
			} );

			// Якщо форма відкрита для редагування цього запису — скидаємо її.
			if ( parseInt( $idInput.val(), 10 ) === id ) {
				fbResetForm();
			}
		} )
		.fail( function () {
			fbShowNotice( 'Помилка з\'єднання.', true );
			$btn.prop( 'disabled', false );
		} );
	} );

	// ── Ініціалізація ────────────────────────────────────────────────────────

	fbLoadCurrencies();

}( jQuery ) );
