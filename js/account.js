/**
 * Family Budget — Модуль «Рахунки» (account.js)
 *
 * Містить всю JS-логіку для сторінки управління рахунками:
 *  - AJAX-завантаження та фільтрація таблиці
 *  - Додавання рахунку (форма)
 *  - Inline-редагування назви та MonoID (account_id)
 *  - Встановлення «Головного» рахунку (зірочка)
 *  - Модальне вікно прив'язки категорії (⚙️)
 *  - Видалення рахунку
 *  - Drag & Drop сортування (jQuery UI Sortable)
 *
 * Залежності: jQuery, jQuery UI Sortable, fbAccountObj (ajax_url, nonce, categories, i18n).
 *
 * @package    FamilyBudget
 * @subpackage Assets/JS
 * @version    1.7.0
 * @since      1.0.0
 */

/* global fbAccountObj */
( function ( $ ) {
	'use strict';

	// ─── Перевірка локалізованих даних ────────────────────────────────────────
	if ( typeof fbAccountObj === 'undefined' ) {
		console.error( '[FB Accounts] Відсутні локалізовані дані (fbAccountObj).' );
		return;
	}

	var i18n = fbAccountObj.i18n || {};

	// =========================================================================
	// ІНІЦІАЛІЗАЦІЯ
	// =========================================================================

	$( document ).ready( function () {
		loadTableData();
	} );

	// =========================================================================
	// ФІЛЬТРАЦІЯ — миттєва реакція на зміну select-фільтрів
	// =========================================================================

	$( '#fb-filter-family, #fb-filter-type' ).on( 'change', function () {
		loadTableData();
	} );

	// =========================================================================
	// ФОРМА ДОДАВАННЯ РАХУНКУ
	// =========================================================================

	/**
	 * Submit форми додавання: збирає поля, відправляє через AJAX.
	 * Передає account_id (MonoID) якщо заповнений.
	 */
	$( '#fb-add-account-form' ).on( 'submit', function ( e ) {
		e.preventDefault();

		var $form      = $( this );
		var $submitBtn = $form.find( 'button[type="submit"]' );

		$submitBtn.prop( 'disabled', true ).css( 'opacity', '0.6' );

		$.post(
			fbAccountObj.ajax_url,
			{
				action:       'fb_add_account',
				security:     fbAccountObj.nonce,
				family_id:    $form.find( '[name="family_id"]' ).val(),
				type_id:      $form.find( '[name="type_id"]' ).val(),
				account_name: $form.find( '[name="account_name"]' ).val(),
				account_id:   $form.find( '[name="account_id"]' ).val(),
			},
			function ( response ) {
				$submitBtn.prop( 'disabled', false ).css( 'opacity', '1' );
				if ( response.success ) {
					$form[ 0 ].reset();
					loadTableData();
				} else {
					window.alert( ( response.data && response.data.message ) || i18n.addError );
				}
			}
		).fail( function () {
			$submitBtn.prop( 'disabled', false ).css( 'opacity', '1' );
			window.alert( i18n.netError );
		} );
	} );

	// =========================================================================
	// ТАБЛИЦЯ: ЗІРОЧКА — встановлення «Головного» рахунку
	// =========================================================================

	$( '#fb-accounts-tbody' ).on( 'click', '.fb-star', function () {
		var $star = $( this );
		var id    = $star.closest( 'tr' ).data( 'id' );

		// Оптимістичний UI: миттєво переносимо клас.
		$( '.fb-star' ).removeClass( 'is-default' );
		$star.addClass( 'is-default' );

		$.post(
			fbAccountObj.ajax_url,
			{
				action:   'fb_set_default_account',
				security: fbAccountObj.nonce,
				id:       id,
			},
			function ( response ) {
				if ( response.success ) {
					loadTableData(); // перезавантажуємо для правильного сортування
				} else {
					loadTableData(); // відкочуємо UI у разі помилки
					window.alert( ( response.data && response.data.message ) || i18n.saveError );
				}
			}
		);
	} );

	// =========================================================================
	// ТАБЛИЦЯ: ВИДАЛЕННЯ рахунку
	// =========================================================================

	$( '#fb-accounts-tbody' ).on( 'click', '.fb-delete-btn', function () {
		if ( ! window.confirm( fbAccountObj.confirm ) ) {
			return;
		}

		var $row = $( this ).closest( 'tr' );
		var id   = $row.data( 'id' );

		$row.css( 'opacity', '0.5' );

		$.post(
			fbAccountObj.ajax_url,
			{
				action:   'fb_delete_account',
				security: fbAccountObj.nonce,
				id:       id,
			},
			function ( response ) {
				if ( response.success ) {
					$row.fadeOut( 300, function () { $( this ).remove(); } );
				} else {
					$row.css( 'opacity', '1' );
					window.alert( ( response.data && response.data.message ) || i18n.delError );
				}
			}
		);
	} );

	// =========================================================================
	// ТАБЛИЦЯ: INLINE-РЕДАГУВАННЯ — назва + MonoID
	// =========================================================================

	/**
	 * Клік «✎» — вмикає режим редагування назви та MonoID рядка.
	 */
	$( '#fb-accounts-tbody' ).on( 'click', '.fb-edit-btn', function () {
		var $row = $( this ).closest( 'tr' );

		// Перемикаємо текст ↔ input для обох полів.
		$row.find( '.fb-acc-name-text, .fb-acc-mono-text, .fb-edit-btn' ).addClass( 'hidden' );
		$row.find( '.fb-acc-name-input, .fb-acc-mono-input, .fb-save-btn' ).removeClass( 'hidden' );

		$row.find( '.fb-acc-name-input' ).focus();
	} );

	/**
	 * Клік «✔» — зберігає нові значення назви та MonoID через AJAX.
	 */
	$( '#fb-accounts-tbody' ).on( 'click', '.fb-save-btn', function () {
		var $row      = $( this ).closest( 'tr' );
		var id        = $row.data( 'id' );
		var $nameInp  = $row.find( '.fb-acc-name-input' );
		var $monoInp  = $row.find( '.fb-acc-mono-input' );
		var newName   = $nameInp.val().trim();
		var newMono   = $monoInp.val().trim();

		if ( '' === newName ) {
			window.alert( i18n.emptyName );
			$nameInp.focus();
			return;
		}

		$row.css( 'opacity', '0.6' );

		$.post(
			fbAccountObj.ajax_url,
			{
				action:     'fb_edit_account',
				security:   fbAccountObj.nonce,
				id:         id,
				name:       newName,
				account_id: newMono,
			},
			function ( response ) {
				$row.css( 'opacity', '1' );

				if ( response.success ) {
					// Оновлюємо назву.
					$row.find( '.fb-acc-name-text' ).text( newName ).removeClass( 'hidden' );

					// Оновлюємо замаскований MonoID — сервер повертає masked_id.
					var maskedId = ( response.data && null !== response.data.masked_id )
						? response.data.masked_id
						: $row.find( '.fb-acc-mono-text' ).text(); // fallback: без змін

					$row.find( '.fb-acc-mono-text' ).text( maskedId ).removeClass( 'hidden' );

					// Повертаємо кнопки у початковий стан.
					$row.find( '.fb-acc-name-input, .fb-acc-mono-input, .fb-save-btn' ).addClass( 'hidden' );
					$row.find( '.fb-edit-btn' ).removeClass( 'hidden' );
				} else {
					window.alert( ( response.data && response.data.message ) || i18n.saveError );
				}
			}
		).fail( function () {
			$row.css( 'opacity', '1' );
			window.alert( i18n.netError );
		} );
	} );

	/**
	 * Збереження по натисканню Enter у будь-якому inline-інпуті рядка.
	 */
	$( '#fb-accounts-tbody' ).on( 'keydown', '.fb-acc-name-input, .fb-acc-mono-input', function ( e ) {
		if ( 13 === e.which ) { // Enter
			$( this ).closest( 'tr' ).find( '.fb-save-btn' ).trigger( 'click' );
		}
		if ( 27 === e.which ) { // Escape — скасовуємо редагування
			cancelRowEdit( $( this ).closest( 'tr' ) );
		}
	} );

	/**
	 * Скасовує режим редагування рядка без збереження.
	 *
	 * @param {jQuery} $row Рядок таблиці.
	 * @return {void}
	 */
	function cancelRowEdit( $row ) {
		$row.find( '.fb-acc-name-text, .fb-acc-mono-text, .fb-edit-btn' ).removeClass( 'hidden' );
		$row.find( '.fb-acc-name-input, .fb-acc-mono-input, .fb-save-btn' ).addClass( 'hidden' );
	}

	// =========================================================================
	// МОДАЛЬНЕ ВІКНО: Прив'язка категорії (⚙️)
	// =========================================================================

	/**
	 * Клік «⚙️» — заповнює та відкриває модальне вікно прив'язки категорії.
	 */
	$( '#fb-accounts-tbody' ).on( 'click', '.fb-cat-btn', function () {
		var $row          = $( this ).closest( 'tr' );
		var accountId     = $row.data( 'id' );
		var currentCatId  = parseInt( $row.data( 'category-id' ), 10 ) || 0;
		var $select       = $( '#fb-cat-modal-select' );

		// Заповнюємо select категоріями з локалізованих даних.
		$select.empty().append(
			$( '<option>' ).val( '0' ).text( i18n.noCat )
		);

		$.each( fbAccountObj.categories || [], function ( _i, cat ) {
			var $option = $( '<option>' ).val( cat.id ).text( cat.name );
			if ( cat.id === currentCatId ) {
				$option.prop( 'selected', true );
			}
			$select.append( $option );
		} );

		$( '#fb-cat-modal-account-id' ).val( accountId );
		openAccModal();
	} );

	/**
	 * Клік «Зберегти» у модалі — відправляє AJAX-запит на прив'язку категорії.
	 */
	$( '#fb-cat-modal-save' ).on( 'click', function () {
		var $btn       = $( this );
		var origText   = $btn.text();
		var accountId  = $( '#fb-cat-modal-account-id' ).val();
		var categoryId = $( '#fb-cat-modal-select' ).val();

		$btn.prop( 'disabled', true ).text( i18n.saving );

		$.post(
			fbAccountObj.ajax_url,
			{
				action:      'fb_set_account_category',
				security:    fbAccountObj.nonce,
				id:          accountId,
				category_id: categoryId,
			},
			function ( response ) {
				$btn.prop( 'disabled', false ).text( origText );

				if ( response.success ) {
					// Оновлюємо клітинку категорії в рядку без перезавантаження таблиці.
					var $row     = $( '#fb-accounts-tbody tr[data-id="' + accountId + '"]' );
					var catName  = response.data.category_name || '';
					var catId    = parseInt( response.data.category_id, 10 ) || 0;

					$row.attr( 'data-category-id', catId );
					$row.find( '.fb-acc-cat-name' ).html(
						'' !== catName
							? esc( catName )
							: '<span class="fb-cat-empty">—</span>'
					);

					closeAccModal();
				} else {
					window.alert( ( response.data && response.data.message ) || i18n.catError );
				}
			}
		).fail( function () {
			$btn.prop( 'disabled', false ).text( origText );
			window.alert( i18n.netError );
		} );
	} );

	/**
	 * Закриття модалі через кнопку «Скасувати».
	 */
	$( '#fb-cat-modal-close' ).on( 'click', closeAccModal );

	/**
	 * Закриття модалі кліком по overlay.
	 */
	$( document ).on( 'click', '.fb-acc-modal-overlay', closeAccModal );

	/**
	 * Закриття модалі клавішею Escape.
	 */
	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.which && $( '#fb-acc-cat-modal' ).is( ':visible' ) ) {
			closeAccModal();
		}
	} );

	/**
	 * Відкриває модальне вікно прив'язки категорії.
	 *
	 * @return {void}
	 */
	function openAccModal() {
		$( '#fb-acc-cat-modal' )
			.fadeIn( 180 )
			.attr( 'aria-hidden', 'false' );
	}

	/**
	 * Закриває модальне вікно прив'язки категорії.
	 *
	 * @return {void}
	 */
	function closeAccModal() {
		$( '#fb-acc-cat-modal' )
			.fadeOut( 180 )
			.attr( 'aria-hidden', 'true' );
	}

	// =========================================================================
	// ЗАВАНТАЖЕННЯ ДАНИХ ТАБЛИЦІ
	// =========================================================================

	/**
	 * Завантажує HTML-рядки таблиці через AJAX з поточними фільтрами.
	 * Після отримання даних переініціалізує Sortable.
	 *
	 * @return {void}
	 */
	function loadTableData() {
		var $tbody = $( '#fb-accounts-tbody' );
		$tbody.css( 'opacity', '0.5' );

		$.post(
			fbAccountObj.ajax_url,
			{
				action:    'fb_load_accounts',
				security:  fbAccountObj.nonce,
				family_id: $( '#fb-filter-family' ).val(),
				type_id:   $( '#fb-filter-type' ).val(),
			},
			function ( response ) {
				if ( response.success ) {
					$tbody.html( response.data.html ).css( 'opacity', '1' );
					initSortable();
				} else {
					$tbody
						.html( '<tr><td colspan="7" class="text-center">' + i18n.loadError + '</td></tr>' )
						.css( 'opacity', '1' );
				}
			}
		).fail( function () {
			$tbody
				.html( '<tr><td colspan="7" class="text-center">' + i18n.netError + '</td></tr>' )
				.css( 'opacity', '1' );
		} );
	}

	// =========================================================================
	// DRAG & DROP СОРТУВАННЯ
	// =========================================================================

	/**
	 * Ініціалізує jQuery UI Sortable на tbody.
	 * Викликається після кожного оновлення DOM (loadTableData).
	 *
	 * @return {void}
	 */
	function initSortable() {
		$( '#fb-accounts-tbody' ).sortable( {
			handle: '.fb-drag-handle',
			helper: function ( _e, tr ) {
				// Фіксуємо ширини колонок під час перетягування.
				var $originals = tr.children();
				var $helper    = tr.clone();
				$helper.children().each( function ( index ) {
					$( this ).width( $originals.eq( index ).width() );
				} );
				return $helper;
			},
			update: function () {
				var order = [];
				$( '#fb-accounts-tbody tr' ).each( function () {
					var rowId = $( this ).data( 'id' );
					if ( rowId ) {
						order.push( rowId );
					}
				} );

				$( '#fb-accounts-tbody' ).css( 'opacity', '0.7' );

				$.post(
					fbAccountObj.ajax_url,
					{
						action:   'fb_reorder_accounts',
						security: fbAccountObj.nonce,
						order:    order,
					},
					function () {
						$( '#fb-accounts-tbody' ).css( 'opacity', '1' );
					}
				);
			},
		} ).disableSelection();
	}

	// =========================================================================
	// УТИЛІТИ
	// =========================================================================

	/**
	 * Мінімальне екранування HTML для безпечного вставлення тексту в innerHTML.
	 * Використовується лише для значень, що повертаються сервером.
	 *
	 * @param  {string} str Рядок для екранування.
	 * @return {string} Екранований рядок.
	 */
	function esc( str ) {
		return $( '<span>' ).text( str ).html();
	}

}( jQuery ) );
