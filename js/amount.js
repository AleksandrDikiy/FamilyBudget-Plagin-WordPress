/**
 * Family Budget — Модуль «Баланс» (amount.js)
 *
 * Version:     1.0.1
 * Date_update: 2026-04-30
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
				action:    'fb_filter_transactions',
				security:  fbAmountData.nonce,
				search:    $( '#fb-search' ).val(),
				date_from: $( '#fb-filter-date-from' ).val(),
				date_to:   $( '#fb-filter-date-to' ).val(),
				type:      $( '#fb-filter-type' ).val(),
				account:   $( '#fb-filter-account' ).val(),
				category:  $( '#fb-filter-category' ).val(),
				page:      currentPage,
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
	$( '#fb-filter-date-from, #fb-filter-date-to, #fb-filter-type, #fb-filter-account, #fb-filter-category' ).on( 'change', function () {
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
/* =========================================================================
 * FB Import v3.2 — чанковий AJAX-імпорт транзакцій
 * ========================================================================= */
;(function ($) {
	'use strict';

	if ( typeof fbAmountData === 'undefined' ) {
		console.error('[FB Import] CRITICAL: fbAmountData not found.');
		return;
	}

	var FBImport = {
		ajaxUrl  : fbAmountData.ajax_url,
		nonce    : fbAmountData.nonce,
		token    : null,
		total    : 0,
		offset   : 0,
		inserted : 0,
		errors   : 0,

		init: function () {
			$(document).on( 'click', '#fb-import-btn', function (e) {
				e.preventDefault();
				e.stopPropagation();
				FBImport.start();
			});
		},

		showOverlay: function ( text ) {
			$('#fb-import-overlay-text').text( text );
			$('#fb-import-overlay').addClass( 'fb-overlay-visible' );
			$('body').css( 'overflow', 'hidden' );
		},

		hideOverlay: function () {
			$('#fb-import-overlay').removeClass( 'fb-overlay-visible' );
			$('body').css( 'overflow', '' );
		},

		updateProgress: function ( processed ) {
			var pct = FBImport.total > 0 ? Math.round( processed / FBImport.total * 100 ) : 0;
			$('#fb-import-bar').css( 'width', pct + '%' );
			$('#fb-import-status').text(
				'Оброблено: ' + processed + ' / ' + FBImport.total +
				' (' + pct + '%)  |  Додано: ' + FBImport.inserted +
				'  |  Помилок: ' + FBImport.errors
			);
			FBImport.showOverlay( 'Імпорт... ' + pct + '% (' + processed + ' / ' + FBImport.total + ')' );
		},

		start: function () {
			var fileInput = document.getElementById('fb-import-file');

			if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
				alert( 'Оберіть CSV-файл для імпорту.' );
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

			$('#fb-import-progress').show();
			$('#fb-import-bar').css({ 'width': '0%', 'background': '#0073aa' });
			$('#fb-import-status').text( 'Завантаження файлу...' );
			FBImport.showOverlay( 'Завантаження файлу на сервер...' );

			$.ajax({
				url         : FBImport.ajaxUrl,
				method      : 'POST',
				data        : fd,
				processData : false,
				contentType : false,
				success: function ( resp ) {
					if ( ! resp.success ) {
						FBImport.hideOverlay();
						alert( 'Помилка завантаження: ' + ( resp.data ? resp.data.message : JSON.stringify(resp) ) );
						return;
					}
					FBImport.token  = resp.data.token;
					FBImport.total  = resp.data.total_rows;
					FBImport.offset = 0;
					FBImport.processChunk();
				},
				error: function ( xhr, status, err ) {
					FBImport.hideOverlay();
					alert( 'Помилка підключення до сервера: ' + status );
				}
			});
		},

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
						alert( 'Помилка чанку: ' + ( resp.data ? resp.data.message : JSON.stringify(resp) ) );
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
						setTimeout( function() { FBImport.processChunk(); }, 100 );
					}
				}
			).fail(function ( xhr ) {
				var isLastChunk = ( FBImport.offset >= FBImport.total );
				FBImport.hideOverlay();

				if ( isLastChunk ) {
					$( '#fb-import-bar' ).css({ 'width': '100%', 'background': '#46b450' });
					$( '#fb-import-status' ).html(
						'✅ <strong>Імпорт завершено!</strong> ' +
						'Додано: ' + FBImport.inserted + ' | ' +
						'Помилок: ' + FBImport.errors +
						' <span style="color:#e67e22">(курси валют синхронізуються у фоні)</span>'
					);
				} else {
					$( '#fb-import-bar' ).css('background', '#dc3545');
					$( '#fb-import-status' ).html(
						'❌ <strong>Помилка сервера</strong> (offset ' + FBImport.offset + '). ' +
						'Додано: ' + FBImport.inserted + '. Перевірте error_log.'
					);
				}

				var fi = document.getElementById('fb-import-file');
				if ( fi ) { fi.value = ''; }
			});
		},

		onDone: function () {
			FBImport.hideOverlay();
			$('#fb-import-bar').css({ 'width': '100%', 'background': '#46b450' });
			$('#fb-import-status').html(
				'✅ <strong>Імпорт завершено!</strong> ' +
				'Додано: ' + FBImport.inserted + ' | ' +
				'Помилок: ' + FBImport.errors
			);
			var fi = document.getElementById('fb-import-file');
			if ( fi ) { fi.value = ''; }
		}
	};

	$( document ).ready(function () {
		FBImport.init();
	});

}(jQuery));