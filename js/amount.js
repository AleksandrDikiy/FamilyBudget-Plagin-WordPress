/**
 * Family Budget — Модуль «Баланс» (amount.js)
 *
 * Містить всю JS-логіку для сторінки управління транзакціями:
 *  - Завантаження балансу через AJAX
 *  - Фільтрація та пагінація таблиці транзакцій
 *  - Динамічні параметри категорії у формі створення
 *  - Модальне вікно редагування транзакції
 *  - Модальне вікно редагування параметрів категорії
 *  - Підтвердження видалення транзакції
 *
 * Залежності: jQuery, fbAmountData (ajax_url, nonce), fbAmountI18n (рядки UI).
 *
 * @package    FamilyBudget
 * @subpackage Assets/JS
 * @version    1.1.1.0
 * @since      1.0.0
 */

/* global fbAmountData, fbAmountI18n */
( function ( $ ) {
	'use strict';

	// ─── Перевірка наявності локалізованих даних ─────────────────────────────
	if ( typeof fbAmountData === 'undefined' || typeof fbAmountI18n === 'undefined' ) {
		console.error( '[FB Budget] Відсутні локалізовані дані. Перевірте wp_localize_script.' );
		return;
	}

	/** @type {number} Поточна сторінка пагінації */
	var currentPage = 1;

	/** @type {number|null} Таймер дебаунсу для поля пошуку */
	var searchTimeout = null;

	// =========================================================================
	// ІНІЦІАЛІЗАЦІЯ
	// =========================================================================

	$( document ).ready( function () {

		// Завантаження балансу при старті.
		loadBalance();

		// Завантаження першої сторінки транзакцій.
		loadTransactions( 1 );

		// Підтвердження видалення через data-confirm атрибут.
		$( document ).on( 'click', '.fb-delete-btn', function ( e ) {
			var msg = $( this ).data( 'confirm' ) || fbAmountI18n.deleteConfirm;
			if ( ! window.confirm( msg ) ) {
				e.preventDefault();
			}
		} );

	} );

	// =========================================================================
	// БАЛАНС
	// =========================================================================

	/**
	 * Завантажує блок балансу через AJAX і вставляє HTML у #fb-balance-loader.
	 *
	 * @return {void}
	 */
	function loadBalance() {
		$.post(
			fbAmountData.ajax_url,
			{ action: 'fb_get_main_balance' },
			function ( response ) {
				$( '#fb-balance-loader' ).html( response );
			}
		);
	}

	// =========================================================================
	// ТАБЛИЦЯ ТРАНЗАКЦІЙ — Фільтрація та пагінація
	// =========================================================================

	/**
	 * Завантажує рядки таблиці транзакцій через AJAX з поточними фільтрами.
	 *
	 * @param {number} page Номер сторінки для завантаження.
	 * @return {void}
	 */
	function loadTransactions( page ) {
		currentPage = page || 1;

		$( '#fb-transactions-body' ).html(
			'<tr><td colspan="7" class="fb-empty-state"><div class="fb-spinner"></div></td></tr>'
		);

		$.ajax( {
			url:      fbAmountData.ajax_url,
			type:     'POST',
			data:     {
				action:   'fb_filter_transactions',
				security: fbAmountData.nonce,
				search:   $( '#fb-search' ).val(),
				date:     $( '#fb-filter-date' ).val(),
				type:     $( '#fb-filter-type' ).val(),
				account:  $( '#fb-filter-account' ).val(),
				category: $( '#fb-filter-category' ).val(),
				page:     currentPage,
			},
			success:  function ( response ) {
				$( '#fb-transactions-body' ).html( response );
			},
			error:    function () {
				$( '#fb-transactions-body' ).html(
					'<tr><td colspan="7" class="fb-empty-state fb-error">' +
					fbAmountI18n.loadError +
					'</td></tr>'
				);
			},
		} );
	}

	// Реакція на зміну фільтрів — скидаємо на першу сторінку.
	$( '#fb-filter-date, #fb-filter-type, #fb-filter-account, #fb-filter-category' ).on( 'change', function () {
		loadTransactions( 1 );
	} );

	// Дебаунс пошуку — 500мс після останнього натискання.
	$( '#fb-search' ).on( 'keyup', function () {
		clearTimeout( searchTimeout );
		searchTimeout = setTimeout( function () {
			loadTransactions( 1 );
		}, 500 );
	} );

	// AJAX-пагінація (делегована, бо кнопки рендеряться динамічно).
	$( document ).on( 'click', '.fb-prev-page, .fb-next-page', function () {
		loadTransactions( $( this ).data( 'page' ) );
	} );

	// =========================================================================
	// ДИНАМІЧНІ ПАРАМЕТРИ КАТЕГОРІЇ (форма створення)
	// =========================================================================

	/**
	 * При зміні категорії у формі створення завантажує поля параметрів через AJAX.
	 * Якщо параметрів немає — приховує контейнер.
	 */
	$( '#fb-cat-select' ).on( 'change', function () {
		var cid  = $( this ).val();
		var $con = $( '#fb-params-container' );

		if ( ! cid ) {
			$con.hide().empty();
			return;
		}

		$.post(
			fbAmountData.ajax_url,
			{ action: 'fb_load_cat_params', cat_id: cid },
			function ( response ) {
				if ( response.trim() ) {
					$con.html( response ).show();
				} else {
					$con.hide().empty();
				}
			}
		);
	} );

	// =========================================================================
	// МОДАЛЬНЕ ВІКНО: Редагування транзакції
	// =========================================================================

	/**
	 * Клік по кнопці «Редагувати» — завантажує дані транзакції та відкриває модал.
	 */
	$( document ).on( 'click', '.fb-edit-btn', function ( e ) {
		e.preventDefault();

		var tid = $( this ).data( 'transaction-id' );
		if ( ! tid ) {
			return;
		}

		$.ajax( {
			url:      fbAmountData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:    'fb_get_amount',
				amount_id: tid,
				security:  fbAmountData.nonce,
			},
			success:  function ( res ) {
				if ( res.success ) {
					var d = res.data;
					$( '#edit-id' ).val( d.id );
					$( '#edit-type' ).val( d.type_id );
					$( '#edit-account' ).val( d.account_id );
					$( '#edit-category' ).val( d.category_id );
					$( '#edit-currency' ).val( d.currency_id );
					$( '#edit-amount' ).val( d.amount );
					$( '#edit-date' ).val( d.date );
					$( '#edit-note' ).val( d.note );

					openModal( '#fb-edit-modal' );
					$( '#edit-amount' ).focus();
				} else {
					window.alert( res.data || fbAmountI18n.txLoadError );
				}
			},
			error:    function () {
				window.alert( fbAmountI18n.networkError );
			},
		} );
	} );

	/**
	 * Клік по кнопці «Зберегти» у модалі редагування — відправляє дані через AJAX.
	 */
	$( '#fb-save-btn' ).on( 'click', function () {
		var $btn     = $( this );
		var origText = $btn.text();
		$btn.prop( 'disabled', true ).text( fbAmountI18n.saving );

		$.ajax( {
			url:      fbAmountData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:      'fb_update_amount',
				amount_id:   $( '#edit-id' ).val(),
				type_id:     $( '#edit-type' ).val(),
				account_id:  $( '#edit-account' ).val(),
				category_id: $( '#edit-category' ).val(),
				currency_id: $( '#edit-currency' ).val(),
				amount:      $( '#edit-amount' ).val(),
				date:        $( '#edit-date' ).val(),
				note:        $( '#edit-note' ).val(),
				security:    fbAmountData.nonce,
			},
			success:  function ( res ) {
				if ( res.success ) {
					closeAllModals();
					loadBalance();
					loadTransactions( currentPage );
				} else {
					window.alert( res.data || fbAmountI18n.saveError );
				}
			},
			error:    function () {
				window.alert( fbAmountI18n.networkError );
			},
			// Завжди розблоковуємо кнопку — незалежно від результату запиту.
			complete: function () {
				$btn.prop( 'disabled', false ).text( origText );
			},
		} );
	} );

	// =========================================================================
	// МОДАЛЬНЕ ВІКНО: Редагування параметрів категорії
	// =========================================================================

	/**
	 * Клік по кнопці «⚙️ Параметри» — завантажує параметри категорії та відкриває модал.
	 */
	$( document ).on( 'click', '.fb-edit-params-btn', function ( e ) {
		e.preventDefault();

		var tid = $( this ).data( 'transaction-id' );
		if ( ! tid ) {
			return;
		}

		$.ajax( {
			url:      fbAmountData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:    'fb_get_category_params',
				amount_id: tid,
				security:  fbAmountData.nonce,
			},
			success:  function ( res ) {
				if ( res.success ) {
					$( '#params-amount-id' ).val( tid );
					var html = '';

					$.each( res.data, function ( i, param ) {
						var type      = ( param.ParameterType_Name || '' ).toLowerCase();
						var inputType = 'число' === type ? 'number' : ( 'дата' === type ? 'date' : 'text' );
						var step      = 'число' === type ? ' step="0.01"' : '';
						var label     = $( '<span>' ).text( param.CategoryParam_Name ).html();
						var value     = $( '<span>' ).text( param.current_value || '' ).html();
						var paramId   = parseInt( param.id, 10 );

						html += '<div class="fb-form-field">';
						html += '<label>' + label + '</label>';
						html += '<input type="' + inputType + '" name="param_' + paramId +
						        '" value="' + value + '" class="fb-form-control"' + step + '>';
						html += '</div>';
					} );

					$( '#fb-params-fields' ).html( html );
					openModal( '#fb-params-modal' );
				} else {
					window.alert( res.data || fbAmountI18n.paramsNotFound );
				}
			},
			error:    function () {
				window.alert( fbAmountI18n.networkError );
			},
		} );
	} );

	/**
	 * Клік по кнопці «Зберегти параметри» — відправляє значення параметрів через AJAX.
	 */
	$( '#fb-save-params-btn' ).on( 'click', function () {
		var $btn     = $( this );
		var origText = $btn.text();
		$btn.prop( 'disabled', true ).text( fbAmountI18n.saving );

		var params = {};
		$( '#fb-params-fields input' ).each( function () {
			var paramId      = $( this ).attr( 'name' ).replace( 'param_', '' );
			params[ paramId ] = $( this ).val();
		} );

		$.ajax( {
			url:      fbAmountData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:    'fb_update_category_params',
				amount_id: $( '#params-amount-id' ).val(),
				params:    params,
				security:  fbAmountData.nonce,
			},
			success:  function ( res ) {
				if ( res.success ) {
					closeAllModals();
					loadTransactions( currentPage );
				} else {
					window.alert( res.data || fbAmountI18n.paramsError );
				}
			},
			error:    function () {
				window.alert( fbAmountI18n.networkError );
			},
			// Завжди розблоковуємо кнопку — незалежно від результату запиту.
			complete: function () {
				$btn.prop( 'disabled', false ).text( origText );
			},
		} );
	} );

	// =========================================================================
	// УПРАВЛІННЯ МОДАЛЬНИМИ ВІКНАМИ
	// =========================================================================

	/**
	 * Відкриває модальне вікно за CSS-селектором.
	 *
	 * @param {string} selector CSS-селектор модального вікна.
	 * @return {void}
	 */
	function openModal( selector ) {
		$( selector )
			.fadeIn( 200 )
			.attr( 'aria-hidden', 'false' )
			.addClass( 'fb-modal-show' );
	}

	/**
	 * Закриває всі відкриті модальні вікна.
	 *
	 * @return {void}
	 */
	function closeAllModals() {
		$( '.fb-modal' )
			.fadeOut( 200 )
			.attr( 'aria-hidden', 'true' )
			.removeClass( 'fb-modal-show' );
	}

	// Кнопки «Скасувати» у модалях.
	$( '#fb-close-btn, #fb-close-params-btn' ).on( 'click', closeAllModals );

	// Клік по затемненій підложці закриває модал.
	$( document ).on( 'click', '.fb-modal-overlay', closeAllModals );

	// Закриття клавішею Escape.
	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.keyCode && $( '.fb-modal:visible' ).length ) {
			closeAllModals();
		}
	} );

}( jQuery ) );
