/**
 * Скрипти для модуля "Категорії"
 *
 * Структура:
 * 1. Ініціалізація
 * 2. Категорії — фільтр, додавання, видалення
 * 3. Параметри категорій — модальне вікно CRUD
 * 4. MCC-маппінг — модальне вікно CRUD (v1.5.0)
 * 5. Утиліти — loadCategoryData, loadParamsData, loadMccData, initSortable
 *
 * @package FamilyBudget
 * @since   1.5.0
 */
jQuery( document ).ready( function ( $ ) {
	'use strict';

	// =========================================================
	// 1. ІНІЦІАЛІЗАЦІЯ
	// =========================================================

	loadCategoryData();

	// =========================================================
	// 2. КАТЕГОРІЇ — ФІЛЬТР, ДОДАВАННЯ, ВИДАЛЕННЯ
	// =========================================================

	/** Фільтрація за типом: перезавантажує таблицю при зміні селекту. */
	$( '#fb-filter-cat-type' ).on( 'change', function () {
		loadCategoryData();
	} );

	/** Форма додавання нової категорії. */
	$( '#fb-add-category-form' ).on( 'submit', function ( e ) {
		e.preventDefault();

		var $form      = $( this );
		var $submitBtn = $form.find( 'button[type="submit"]' );

		$submitBtn.prop( 'disabled', true ).css( 'opacity', '0.6' );

		$.post( fbCatObj.ajax_url, {
			action:        'fb_ajax_add_category',
			security:      fbCatObj.nonce,
			type_id:       $form.find( '[name="type_id"]' ).val(),
			category_name: $form.find( '[name="category_name"]' ).val(),
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$form[ 0 ].reset();
				loadCategoryData();
			} else {
				alert( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_save );
			}
		} )
		.fail( function () {
			alert( fbCatObj.err_connect );
		} )
		.always( function () {
			$submitBtn.prop( 'disabled', false ).css( 'opacity', '1' );
		} );
	} );

	/** Видалення категорії (делегований обробник для динамічного DOM). */
	$( document ).on( 'click.fbCatDelete', '#fb-category-tbody .fb-delete-btn[data-action="delete"]', function ( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();

		if ( ! window.confirm( fbCatObj.confirm_delete ) ) {
			return;
		}

		var $row = $( this ).closest( 'tr' );

		$.post( fbCatObj.ajax_url, {
			action:   'fb_ajax_delete_category',
			security: fbCatObj.nonce,
			id:       $row.data( 'id' ),
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$row.fadeOut( 300, function () { $( this ).remove(); } );
			} else {
				alert( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_save );
			}
		} )
		.fail( function () {
			alert( fbCatObj.err_connect );
		} );
	} );

	// =========================================================
	// 3. ПАРАМЕТРИ КАТЕГОРІЙ — МОДАЛЬНЕ ВІКНО
	// =========================================================

	/** Відкриття модального вікна параметрів (клік по ⚙️ у колонці дій). */
	$( document ).on( 'click.fbParams', '#fb-category-tbody .fb-param-btn[data-action="params"]', function () {
		var $row = $( this ).closest( 'tr' );

		$( '#modal_category_id' ).val( $row.data( 'id' ) );
		$( '#fb-modal-cat-name' ).text( 'Параметри: ' + $row.data( 'cat-name' ) );

		loadParamsData( $row.data( 'id' ) );
		$( '#fb-params-modal' ).removeClass( 'hidden' ).fadeIn( 200 );
	} );

	/** Закриття модального вікна параметрів. */
	$( '#fb-params-modal .fb-modal-close' ).on( 'click', function () {
		$( '#fb-params-modal' ).fadeOut( 200, function () {
			$( this ).addClass( 'hidden' );
		} );
		loadCategoryData(); // Оновлюємо бейджі.
	} );

	/** Закриття модального вікна кліком на тло. */
	$( '#fb-params-modal' ).on( 'click', function ( e ) {
		if ( $( e.target ).is( '#fb-params-modal' ) ) {
			$( this ).fadeOut( 200, function () { $( this ).addClass( 'hidden' ); } );
			loadCategoryData();
		}
	} );

	/** Форма додавання параметра (всередині модального вікна). */
	$( '#fb-add-param-form' ).on( 'submit', function ( e ) {
		e.preventDefault();

		var $form = $( this );

		$.post( fbCatObj.ajax_url, {
			action:        'fb_ajax_add_category_param',
			security:      fbCatObj.nonce,
			category_id:   $( '#modal_category_id' ).val(),
			param_type_id: $form.find( '[name="param_type_id"]' ).val(),
			param_name:    $form.find( '[name="param_name"]' ).val(),
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$form.find( '[name="param_name"]' ).val( '' );
				loadParamsData( $( '#modal_category_id' ).val() );
			} else {
				alert( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_save );
			}
		} );
	} );

	/** Inline-редагування параметра: вхід у режим. */
	$( document ).on( 'click.fbParamEdit', '#fb-params-tbody [data-action="edit-param"]', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( 'tr' );
		$row.find( '.fb-text-val, [data-action="edit-param"]' ).addClass( 'hidden' );
		$row.find( '.fb-input-val, [data-action="save-param"], [data-action="cancel-param"]' ).removeClass( 'hidden' );
		$row.find( '.fb-p-name-input' ).trigger( 'focus' );
	} );

	/** Inline-редагування параметра: скасування. */
	$( document ).on( 'click.fbParamEdit', '#fb-params-tbody [data-action="cancel-param"]', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( 'tr' );
		$row.find( '.fb-text-val, [data-action="edit-param"]' ).removeClass( 'hidden' );
		$row.find( '.fb-input-val, [data-action="save-param"], [data-action="cancel-param"]' ).addClass( 'hidden' );
	} );

	/** Inline-редагування параметра: збереження. */
	$( document ).on( 'click.fbParamEdit', '#fb-params-tbody [data-action="save-param"]', function ( e ) {
		e.preventDefault();
		var $btn     = $( this );
		var $row     = $btn.closest( 'tr' );
		var newName  = $.trim( $row.find( '.fb-p-name-input' ).val() );

		if ( ! newName ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( fbCatObj.ajax_url, {
			action:   'fb_ajax_edit_category_param',
			security: fbCatObj.nonce,
			id:       $row.data( 'id' ),
			name:     newName,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$row.find( '.fb-p-name-val' ).text( newName );
				$row.find( '.fb-p-name-input' ).val( newName );
				$row.find( '.fb-text-val, [data-action="edit-param"]' ).removeClass( 'hidden' );
				$row.find( '.fb-input-val, [data-action="save-param"], [data-action="cancel-param"]' ).addClass( 'hidden' );
			} else {
				alert( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_save );
			}
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	/** Видалення параметра. */
	$( document ).on( 'click.fbParamDelete', '#fb-params-tbody [data-action="delete-param"]', function ( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();

		if ( ! window.confirm( fbCatObj.confirm_delete ) ) {
			return;
		}

		var $row = $( this ).closest( 'tr' );

		$.post( fbCatObj.ajax_url, {
			action:   'fb_ajax_delete_category_param',
			security: fbCatObj.nonce,
			id:       $row.data( 'id' ),
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$row.fadeOut( 200, function () { $( this ).remove(); } );
			}
		} );
	} );

	// =========================================================
	// 4. MCC-МАППІНГ — МОДАЛЬНЕ ВІКНО CRUD
	// =========================================================

	/**
	 * Відкриття модального вікна MCC.
	 * Тригер: клік по бейджу-лічильнику MCC у таблиці категорій.
	 */
	$( document ).on( 'click.fbMcc', '#fb-category-tbody .fb-mcc-badge', function () {
		var catId   = $( this ).data( 'cat-id' );
		var catName = $( this ).data( 'cat-name' );

		$( '#fb-mcc-category-id' ).val( catId );
		$( '#fb-mcc-modal-title' ).text( fbCatObj.mcc_modal_title + catName );

		$( '#fb-add-mcc-form' )[ 0 ].reset();
		$( '#fb-mcc-category-id' ).val( catId ); // Відновлюємо після reset().

		loadMccData( catId );
		$( '#fb-mcc-modal' ).removeClass( 'hidden' ).fadeIn( 200 );
	} );

	/** Закриття MCC-модалі через хрестик. */
	$( document ).on( 'click.fbMccClose', '.fb-mcc-modal-close', function () {
		$( '#fb-mcc-modal' ).fadeOut( 200, function () {
			$( this ).addClass( 'hidden' );
		} );
		loadCategoryData(); // Оновлюємо бейджі MCC у головній таблиці.
	} );

	/** Закриття MCC-модалі кліком на тло. */
	$( '#fb-mcc-modal' ).on( 'click', function ( e ) {
		if ( $( e.target ).is( '#fb-mcc-modal' ) ) {
			$( this ).fadeOut( 200, function () { $( this ).addClass( 'hidden' ); } );
			loadCategoryData();
		}
	} );

	/** Форма додавання нового MCC-запису. */
	$( '#fb-add-mcc-form' ).on( 'submit', function ( e ) {
		e.preventDefault();

		var $form      = $( this );
		var $submitBtn = $form.find( 'button[type="submit"]' );
		var catId      = $( '#fb-mcc-category-id' ).val();
		var mccCode    = parseInt( $form.find( '[name="mcc_code"]' ).val(), 10 );

		if ( isNaN( mccCode ) || mccCode < 1 || mccCode > 9999 ) {
			alert( fbCatObj.err_mcc_code );
			return;
		}

		$submitBtn.prop( 'disabled', true ).css( 'opacity', '0.6' );

		$.post( fbCatObj.ajax_url, {
			action:      'fb_ajax_add_mcc_mapping',
			security:    fbCatObj.nonce,
			category_id: catId,
			mcc_code:    mccCode,
			mcc_desc:    $form.find( '[name="mcc_desc"]' ).val(),
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$form.find( '[name="mcc_code"], [name="mcc_desc"]' ).val( '' );
				loadMccData( catId );
			} else {
				alert( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_save );
			}
		} )
		.fail( function () {
			alert( fbCatObj.err_connect );
		} )
		.always( function () {
			$submitBtn.prop( 'disabled', false ).css( 'opacity', '1' );
		} );
	} );

	/** Inline-редагування MCC: вхід у режим. */
	$( document ).on( 'click.fbMccEdit', '#fb-mcc-tbody [data-action="edit-mcc"]', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( 'tr' );
		$row.find( '.fb-text-val, [data-action="edit-mcc"]' ).addClass( 'hidden' );
		$row.find( '.fb-input-val, [data-action="save-mcc"], [data-action="cancel-mcc"]' ).removeClass( 'hidden' );
		$row.find( '.fb-mcc-desc-input-edit' ).trigger( 'focus' );
	} );

	/** Inline-редагування MCC: скасування. */
	$( document ).on( 'click.fbMccEdit', '#fb-mcc-tbody [data-action="cancel-mcc"]', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( 'tr' );
		$row.find( '.fb-text-val, [data-action="edit-mcc"]' ).removeClass( 'hidden' );
		$row.find( '.fb-input-val, [data-action="save-mcc"], [data-action="cancel-mcc"]' ).addClass( 'hidden' );
	} );

	/** Inline-редагування MCC: збереження змін (код та опис). */
	$( document ).on( 'click.fbMccEdit', '#fb-mcc-tbody [data-action="save-mcc"]', function ( e ) {
		e.preventDefault();

		var $btn     = $( this );
		var $row     = $btn.closest( 'tr' );
		var oldMcc   = parseInt( $row.data( 'mcc' ), 10 );
		var newMcc   = parseInt( $row.find( '.fb-mcc-code-input-edit' ).val(), 10 );
		var newDesc  = $.trim( $row.find( '.fb-mcc-desc-input-edit' ).val() );
		var catId    = $row.data( 'cat-id' );

		if ( isNaN( newMcc ) || newMcc < 1 || newMcc > 9999 ) {
			alert( fbCatObj.err_mcc_code );
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( fbCatObj.ajax_url, {
			action:      'fb_ajax_update_mcc_mapping',
			security:    fbCatObj.nonce,
			old_mcc:     oldMcc,
			new_mcc:     newMcc,
			category_id: catId,
			mcc_desc:    newDesc,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				// Оновлюємо відображення та data-атрибути.
				$row.attr( 'data-mcc', newMcc ).data( 'mcc', newMcc );
				$row.find( '.fb-mcc-code-val strong' ).text( newMcc );
				$row.find( '.fb-mcc-code-input-edit' ).val( newMcc );
				$row.find( '.fb-mcc-desc-val' ).text( newDesc );
				$row.find( '.fb-mcc-desc-input-edit' ).val( newDesc );

				// Повертаємо режим перегляду.
				$row.find( '.fb-text-val, [data-action="edit-mcc"]' ).removeClass( 'hidden' );
				$row.find( '.fb-input-val, [data-action="save-mcc"], [data-action="cancel-mcc"]' ).addClass( 'hidden' );
			} else {
				alert( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_save );
			}
		} )
		.fail( function () {
			alert( fbCatObj.err_connect );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	/** Видалення MCC-запису. */
	$( document ).on( 'click.fbMccDelete', '#fb-mcc-tbody [data-action="delete-mcc"]', function ( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();

		if ( ! window.confirm( fbCatObj.confirm_mcc_del ) ) {
			return;
		}

		var $row = $( this ).closest( 'tr' );

		$.post( fbCatObj.ajax_url, {
			action:      'fb_ajax_delete_mcc_mapping',
			security:    fbCatObj.nonce,
			mcc_code:    $row.data( 'mcc' ),
			category_id: $row.data( 'cat-id' ),
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$row.fadeOut( 200, function () { $( this ).remove(); } );
			} else {
				alert( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_save );
			}
		} )
		.fail( function () {
			alert( fbCatObj.err_connect );
		} );
	} );

	// =========================================================
	// 5. УТИЛІТИ
	// =========================================================

	/**
	 * Завантажує та рендерить рядки таблиці категорій через AJAX.
	 * Після завантаження ініціалізує sortable на tbody.
	 */
	function loadCategoryData() {
		var $tbody = $( '#fb-category-tbody' );
		$tbody.css( 'opacity', '0.5' );

		$.post( fbCatObj.ajax_url, {
			action:   'fb_ajax_load_categories',
			security: fbCatObj.nonce,
			type_id:  $( '#fb-filter-cat-type' ).val(),
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$tbody.html( response.data.html ).css( 'opacity', '1' );
				initSortable( '#fb-category-tbody', '.fb-drag-handle', 'fb_ajax_move_category' );
			}
		} );
	}

	/**
	 * Завантажує та рендерить параметри обраної категорії (для модального вікна).
	 *
	 * @param {number} categoryId ID категорії.
	 */
	function loadParamsData( categoryId ) {
		var $tbody = $( '#fb-params-tbody' );
		$tbody.css( 'opacity', '0.5' );

		$.post( fbCatObj.ajax_url, {
			action:      'fb_ajax_load_category_params',
			security:    fbCatObj.nonce,
			category_id: categoryId,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$tbody.html( response.data.html ).css( 'opacity', '1' );
				initSortable( '#fb-params-tbody', '.fb-drag-handle-param', 'fb_ajax_move_category_param' );
			}
		} );
	}

	/**
	 * Завантажує та рендерить MCC-записи обраної категорії (для модального вікна).
	 *
	 * @param {number} categoryId ID категорії.
	 */
	function loadMccData( categoryId ) {
		var $tbody = $( '#fb-mcc-tbody' );
		$tbody.css( 'opacity', '0.5' );

		$.post( fbCatObj.ajax_url, {
			action:      'fb_ajax_load_mcc_mapping',
			security:    fbCatObj.nonce,
			category_id: categoryId,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$tbody.html( response.data.html ).css( 'opacity', '1' );
			} else {
				$tbody.html(
					'<tr><td colspan="3" class="text-center fb-mcc-error">' +
					( ( response.data && response.data.message ) ? response.data.message : fbCatObj.err_connect ) +
					'</td></tr>'
				).css( 'opacity', '1' );
			}
		} )
		.fail( function () {
			$tbody.html(
				'<tr><td colspan="3" class="text-center">' + fbCatObj.err_connect + '</td></tr>'
			).css( 'opacity', '1' );
		} );
	}

	/**
	 * Ініціалізує jQuery UI Sortable для drag-and-drop сортування рядків таблиці.
	 *
	 * @param {string} container   CSS-селектор контейнера <tbody>.
	 * @param {string} handleClass CSS-селектор елемента-ручки для перетягування.
	 * @param {string} ajaxAction  Ім'я WordPress AJAX-дії для збереження порядку.
	 */
	function initSortable( container, handleClass, ajaxAction ) {
		$( container ).sortable( {
			handle: handleClass,
			helper: function ( e, tr ) {
				var $originals = tr.children();
				var $helper    = tr.clone();
				$helper.children().each( function ( i ) {
					$( this ).width( $originals.eq( i ).width() );
				} );
				return $helper;
			},
			update: function () {
				var order = [];
				$( container + ' tr' ).each( function () {
					var rowId = $( this ).data( 'id' );
					if ( rowId ) {
						order.push( rowId );
					}
				} );

				$( container ).css( 'opacity', '0.7' );

				$.post( fbCatObj.ajax_url, {
					action:   ajaxAction,
					security: fbCatObj.nonce,
					order:    order,
				} )
				.always( function () {
					$( container ).css( 'opacity', '1' );
				} );
			},
		} ).disableSelection();
	}

} );
