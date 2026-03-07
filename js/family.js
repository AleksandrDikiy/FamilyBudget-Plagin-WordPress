/**
 * Family Budget — Модуль «Родини» (family.js)
 *
 * Містить всю JS-логіку для сторінки управління родинами:
 *  - Завантаження та відображення списку родин у sidebar
 *  - Створення нової родини (inline form)
 *  - Inline-редагування назви родини
 *  - Видалення родини (з підтвердженням)
 *  - Завантаження учасників обраної родини
 *  - Модальне вікно додавання користувача до родини
 *  - Видалення користувача з родини
 *
 * Залежності: jQuery, fbFamilyData (ajax_url, nonce), fbFamilyI18n (рядки UI).
 *
 * @package    FamilyBudget
 * @subpackage Assets/JS
 * @version    1.0.0
 * @since      1.0.0
 */

/* global fbFamilyData, fbFamilyI18n */
( function ( $ ) {
	'use strict';

	// ─── Перевірка наявності локалізованих даних ─────────────────────────────
	if ( typeof fbFamilyData === 'undefined' || typeof fbFamilyI18n === 'undefined' ) {
		console.error( '[FB Family] Відсутні локалізовані дані. Перевірте wp_localize_script.' );
		return;
	}

	/** @type {number} ID поточної обраної родини */
	var currentFamilyId = 0;

	/** @type {string} Назва поточної обраної родини */
	var currentFamilyName = '';

	// =========================================================================
	// ІНІЦІАЛІЗАЦІЯ
	// =========================================================================

	$( document ).ready( function () {
		loadFamilyList();
	} );

	// =========================================================================
	// ДОПОМІЖНІ ФУНКЦІЇ
	// =========================================================================

	/**
	 * Відображає повідомлення у блоку #fb-family-notice.
	 *
	 * @param {string}  message Текст повідомлення.
	 * @param {string}  type    'success' | 'error' | 'info'
	 * @param {boolean} autoHide Автоматично сховати через 4 секунди.
	 * @return {void}
	 */
	function showNotice( message, type, autoHide ) {
		var $notice = $( '#fb-family-notice' );
		$notice
			.removeClass( 'fb-notice-success fb-notice-error fb-notice-info' )
			.addClass( 'fb-notice-' + ( type || 'info' ) )
			.text( message )
			.slideDown( 200 );

		if ( false !== autoHide ) {
			setTimeout( function () {
				$notice.slideUp( 200 );
			}, 4000 );
		}
	}

	/**
	 * Приховує повідомлення.
	 *
	 * @return {void}
	 */
	function hideNotice() {
		$( '#fb-family-notice' ).slideUp( 200 );
	}

	// =========================================================================
	// СПИСОК РОДИН
	// =========================================================================

	/**
	 * Завантажує список родин поточного користувача через AJAX і рендерить у sidebar.
	 *
	 * @return {void}
	 */
	function loadFamilyList() {
		var $list = $( '#fb-families-list' );
		$list.html( '<li class="fb-family-loading"><div class="fb-spinner"></div></li>' );

		$.ajax( {
			url:  fbFamilyData.ajax_url,
			type: 'POST',
			data: {
				action:   'fb_get_family_list',
				security: fbFamilyData.nonce,
			},
			success: function ( response ) {
				$list.html( response );

				// Якщо раніше була обрана родина — відновлюємо активний стан.
				if ( currentFamilyId ) {
					$list.find( '[data-family-id="' + currentFamilyId + '"]' ).addClass( 'fb-active' );
				}
			},
			error: function () {
				$list.html(
					'<li class="fb-family-error">' + fbFamilyI18n.networkError + '</li>'
				);
			},
		} );
	}

	// =========================================================================
	// СТВОРЕННЯ РОДИНИ
	// =========================================================================

	/**
	 * Обробка кліку по кнопці «Створити родину».
	 * Валідує назву, відправляє AJAX-запит, оновлює список.
	 */
	$( '#fb-create-family-btn' ).on( 'click', function () {
		var $btn  = $( this );
		var $input = $( '#fb-new-family-name' );
		var name  = $.trim( $input.val() );

		if ( ! name ) {
			$input.focus().addClass( 'fb-input-error' );
			return;
		}

		$input.removeClass( 'fb-input-error' );
		$btn.prop( 'disabled', true ).text( fbFamilyI18n.saving );

		$.ajax( {
			url:      fbFamilyData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:      'fb_create_family',
				security:    fbFamilyData.nonce,
				family_name: name,
			},
			success: function ( res ) {
				if ( res.success ) {
					$input.val( '' );
					showNotice( res.data.message, 'success' );
					loadFamilyList();
				} else {
					showNotice( res.data || fbFamilyI18n.saveError, 'error' );
				}
			},
			error: function () {
				showNotice( fbFamilyI18n.networkError, 'error' );
			},
			complete: function () {
				$btn.prop( 'disabled', false ).text(
					$( '#fb-create-family-btn' ).data( 'original-text' ) || 'Створити родину'
				);
			},
		} );
	} );

	// Enter у полі назви — теж запускає створення.
	$( '#fb-new-family-name' ).on( 'keydown', function ( e ) {
		if ( 13 === e.keyCode ) {
			$( '#fb-create-family-btn' ).trigger( 'click' );
		}
		$( this ).removeClass( 'fb-input-error' );
	} );

	// =========================================================================
	// РЕДАГУВАННЯ НАЗВИ РОДИНИ (inline)
	// =========================================================================

	/**
	 * Перехід у режим редагування при кліку на ✏️.
	 */
	$( document ).on( 'click', '.fb-edit-family-btn', function ( e ) {
		e.stopPropagation(); // Не перемикати активну родину.
		var $item = $( this ).closest( '.fb-family-item' );
		$item.find( '.fb-family-name-view' ).hide();
		$item.find( '.fb-family-actions' ).hide();
		$item.find( '.fb-family-name-edit' ).show();
		$item.find( '.fb-family-name-input' ).focus().select();
	} );

	/**
	 * Скасування редагування при кліку на ✕ у рядку.
	 */
	$( document ).on( 'click', '.fb-inline-cancel-btn', function ( e ) {
		e.stopPropagation();
		var $item = $( this ).closest( '.fb-family-item' );
		$item.find( '.fb-family-name-view' ).show();
		$item.find( '.fb-family-actions' ).show();
		$item.find( '.fb-family-name-edit' ).hide();
	} );

	/**
	 * Збереження нової назви родини при кліку на ✓ або Enter у полі.
	 */
	$( document ).on( 'click', '.fb-inline-save-btn', function ( e ) {
		e.stopPropagation();
		saveFamilyName( $( this ).data( 'family-id' ) );
	} );

	$( document ).on( 'keydown', '.fb-family-name-input', function ( e ) {
		if ( 13 === e.keyCode ) {
			saveFamilyName( $( this ).data( 'family-id' ) );
		}
		if ( 27 === e.keyCode ) {
			$( this ).closest( '.fb-family-item' ).find( '.fb-inline-cancel-btn' ).trigger( 'click' );
		}
	} );

	/**
	 * Відправляє AJAX-запит на перейменування родини.
	 *
	 * @param {number} familyId ID родини.
	 * @return {void}
	 */
	function saveFamilyName( familyId ) {
		var $item  = $( '.fb-family-item[data-family-id="' + familyId + '"]' );
		var $input = $item.find( '.fb-family-name-input' );
		var name   = $.trim( $input.val() );

		if ( ! name ) {
			$input.addClass( 'fb-input-error' ).focus();
			return;
		}

		$input.prop( 'disabled', true );

		$.ajax( {
			url:      fbFamilyData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:      'fb_update_family',
				security:    fbFamilyData.nonce,
				family_id:   familyId,
				family_name: name,
			},
			success: function ( res ) {
				if ( res.success ) {
					// Оновлюємо назву в інтерфейсі без повного перезавантаження.
					$item.find( '.fb-family-name-view' ).text( res.data.family_name ).show();
					$item.find( '.fb-family-actions' ).show();
					$item.find( '.fb-family-name-edit' ).hide();
					showNotice( res.data.message, 'success' );

					// Оновлюємо заголовок у main-частині якщо обрана ця родина.
					if ( currentFamilyId === familyId ) {
						currentFamilyName = res.data.family_name;
						$( '#fb-selected-family-name' ).text( res.data.family_name );
					}
				} else {
					showNotice( res.data || fbFamilyI18n.saveError, 'error' );
				}
			},
			error: function () {
				showNotice( fbFamilyI18n.networkError, 'error' );
			},
			complete: function () {
				$input.prop( 'disabled', false );
			},
		} );
	}

	// =========================================================================
	// ВИДАЛЕННЯ РОДИНИ
	// =========================================================================

	/**
	 * Обробка кліку по кнопці «Видалити» (🗑️).
	 * Disabled-кнопки ігноруємо. Показуємо confirm перед видаленням.
	 */
	$( document ).on( 'click', '.fb-delete-family-btn', function ( e ) {
		e.stopPropagation();

		if ( $( this ).is( ':disabled' ) || $( this ).hasClass( 'fb-btn-disabled' ) ) {
			showNotice( fbFamilyI18n.deleteBlocked, 'error' );
			return;
		}

		var familyId = $( this ).data( 'family-id' );

		if ( ! window.confirm( fbFamilyI18n.deleteConfirm ) ) {
			return;
		}

		$.ajax( {
			url:      fbFamilyData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:    'fb_delete_family',
				security:  fbFamilyData.nonce,
				family_id: familyId,
			},
			success: function ( res ) {
				if ( res.success ) {
					showNotice( res.data, 'success' );

					// Якщо видалена родина була обрана — скидаємо main.
					if ( currentFamilyId === familyId ) {
						currentFamilyId = 0;
						resetMembersPanel();
					}

					loadFamilyList();
				} else {
					showNotice( res.data || fbFamilyI18n.saveError, 'error' );
				}
			},
			error: function () {
				showNotice( fbFamilyI18n.networkError, 'error' );
			},
		} );
	} );

	// =========================================================================
	// ВИБІР РОДИНИ ТА ЗАВАНТАЖЕННЯ УЧАСНИКІВ
	// =========================================================================

	/**
	 * Клік по рядку родини у sidebar — завантажує її учасників у main.
	 */
	$( document ).on( 'click', '.fb-members-btn', function ( e ) {
		e.stopPropagation();
		var familyId   = $( this ).data( 'family-id' );
		var familyName = $( this ).closest( '.fb-family-item' )
			.find( '.fb-family-name-view' ).text();

		selectFamily( familyId, familyName );
	} );

	/**
	 * Встановлює обрану родину та завантажує її учасників.
	 *
	 * @param {number} familyId   ID родини.
	 * @param {string} familyName Назва родини для заголовка.
	 * @return {void}
	 */
	function selectFamily( familyId, familyName ) {
		currentFamilyId   = familyId;
		currentFamilyName = familyName;

		// Підсвічуємо активну родину у sidebar.
		$( '.fb-family-item' ).removeClass( 'fb-active' );
		$( '.fb-family-item[data-family-id="' + familyId + '"]' ).addClass( 'fb-active' );

		// Показуємо панель учасників.
		$( '#fb-no-family-selected' ).hide();
		$( '#fb-members-header' ).show();
		$( '#fb-members-table-wrap' ).show();

		// Оновлюємо заголовок та data-family-id на кнопці «Додати».
		$( '#fb-selected-family-name' ).text( familyName );
		$( '#fb-open-add-user-btn' ).data( 'family-id', familyId );

		loadMembers( familyId );
	}

	/**
	 * Завантажує учасників родини через AJAX і рендерить у tbody.
	 *
	 * @param {number} familyId ID родини.
	 * @return {void}
	 */
	function loadMembers( familyId ) {
		var $body = $( '#fb-members-body' );
		$body.html(
			'<tr><td colspan="4" class="fb-empty-state"><div class="fb-spinner"></div></td></tr>'
		);

		$.ajax( {
			url:  fbFamilyData.ajax_url,
			type: 'POST',
			data: {
				action:    'fb_get_family_members',
				security:  fbFamilyData.nonce,
				family_id: familyId,
			},
			success: function ( response ) {
				$body.html( response );
			},
			error: function () {
				$body.html(
					'<tr><td colspan="4" class="fb-empty-state fb-error">'
					+ fbFamilyI18n.networkError
					+ '</td></tr>'
				);
			},
		} );
	}

	/**
	 * Скидає панель учасників до початкового стану «Оберіть родину».
	 *
	 * @return {void}
	 */
	function resetMembersPanel() {
		$( '.fb-family-item' ).removeClass( 'fb-active' );
		$( '#fb-members-header' ).hide();
		$( '#fb-members-table-wrap' ).hide();
		$( '#fb-no-family-selected' ).show();
		$( '#fb-members-body' ).html( '' );
	}

	// =========================================================================
	// ВИДАЛЕННЯ УЧАСНИКА З РОДИНИ
	// =========================================================================

	/**
	 * Обробка кліку по кнопці «✕» видалення учасника.
	 */
	$( document ).on( 'click', '.fb-remove-member-btn', function () {
		if ( ! window.confirm( fbFamilyI18n.removeConfirm ) ) {
			return;
		}

		var $btn       = $( this );
		var userId     = $btn.data( 'user-id' );
		var familyId   = $btn.data( 'family-id' );

		$btn.prop( 'disabled', true );

		$.ajax( {
			url:      fbFamilyData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:          'fb_remove_user_from_family',
				security:        fbFamilyData.nonce,
				family_id:       familyId,
				target_user_id:  userId,
			},
			success: function ( res ) {
				if ( res.success ) {
					showNotice( res.data, 'success' );
					loadMembers( familyId );
				} else {
					showNotice( res.data || fbFamilyI18n.saveError, 'error' );
					$btn.prop( 'disabled', false );
				}
			},
			error: function () {
				showNotice( fbFamilyI18n.networkError, 'error' );
				$btn.prop( 'disabled', false );
			},
		} );
	} );

	// =========================================================================
	// МОДАЛЬНЕ ВІКНО: Додавання користувача
	// =========================================================================

	/**
	 * Відкриває модальне вікно додавання користувача.
	 */
	$( document ).on( 'click', '#fb-open-add-user-btn', function () {
		var familyId = $( this ).data( 'family-id' );
		if ( ! familyId ) {
			return;
		}
		$( '#fb-modal-family-label' ).text( currentFamilyName );
		$( '#fb-user-query' ).val( '' ).removeClass( 'fb-input-error' );
		openModal( '#fb-add-user-modal' );
		setTimeout( function () {
			$( '#fb-user-query' ).focus();
		}, 220 );
	} );

	/**
	 * Підтвердження додавання користувача.
	 */
	$( '#fb-confirm-add-user-btn' ).on( 'click', function () {
		var $btn   = $( this );
		var query  = $.trim( $( '#fb-user-query' ).val() );

		if ( ! query ) {
			$( '#fb-user-query' ).addClass( 'fb-input-error' ).focus();
			return;
		}

		$btn.prop( 'disabled', true ).text( fbFamilyI18n.saving );

		$.ajax( {
			url:      fbFamilyData.ajax_url,
			type:     'POST',
			dataType: 'json',
			data:     {
				action:    'fb_add_user_to_family',
				security:  fbFamilyData.nonce,
				family_id: currentFamilyId,
				user_query: query,
			},
			success: function ( res ) {
				if ( res.success ) {
					closeAllModals();
					showNotice( res.data.message, 'success' );
					if ( currentFamilyId ) {
						loadMembers( currentFamilyId );
					}
				} else {
					showNotice( res.data || fbFamilyI18n.userNotFound, 'error' );
				}
			},
			error: function () {
				showNotice( fbFamilyI18n.networkError, 'error' );
			},
			complete: function () {
				$btn.prop( 'disabled', false ).text( 'Додати' );
			},
		} );
	} );

	// Enter у полі пошуку користувача.
	$( '#fb-user-query' ).on( 'keydown', function ( e ) {
		if ( 13 === e.keyCode ) {
			$( '#fb-confirm-add-user-btn' ).trigger( 'click' );
		}
		$( this ).removeClass( 'fb-input-error' );
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

	$( '#fb-close-add-user-btn' ).on( 'click', closeAllModals );
	$( document ).on( 'click', '.fb-modal-overlay', closeAllModals );
	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.keyCode && $( '.fb-modal:visible' ).length ) {
			closeAllModals();
		}
	} );

}( jQuery ) );
