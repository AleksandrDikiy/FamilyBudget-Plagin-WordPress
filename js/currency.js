/* global fbCurrencyObj */
jQuery( function ( $ ) {
	'use strict';

	const $tbody = $( '#fb-currency-tbody' );
	const $status = $( '#fb-currency-status' );
	const $filterFamily = $( '#fb-currency-filter-family' );
	const $addForm = $( '#fb-currency-add-form' );

	init();

	function init() {
		bindEvents();
		syncFamilySelect();
		loadCurrencyData();
	}

	function bindEvents() {
		$filterFamily.on( 'change', function () {
			syncFamilySelect();
			loadCurrencyData();
		} );

		$addForm.on( 'submit', function ( event ) {
			event.preventDefault();

			const familyId = $( '#fb-currency-family-id' ).val();
			const currencyId = $( '#fb-currency-catalog-id' ).val();

			if ( ! familyId ) {
				showStatus( fbCurrencyObj.i18n.select_family, 'error' );
				return;
			}

			if ( ! currencyId ) {
				showStatus( fbCurrencyObj.i18n.select_currency, 'error' );
				return;
			}

			setFormState( true );

			$.post( fbCurrencyObj.ajax_url, {
				action: 'fb_add_currency',
				security: fbCurrencyObj.nonce,
				family_id: familyId,
				currency_id: currencyId,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						$addForm.get( 0 ).reset();
						syncFamilySelect();
						showStatus( response.data.message || fbCurrencyObj.i18n.added, 'success' );
						loadCurrencyData();
						return;
					}

					showStatus( response.data.message || fbCurrencyObj.i18n.server_error, 'error' );
				} )
				.fail( function () {
					showStatus( fbCurrencyObj.i18n.server_error, 'error' );
				} )
				.always( function () {
					setFormState( false );
				} );
		} );

		$tbody.on( 'click', '[data-action="set-primary"]', function () {
			const $button = $( this );
			const id = parseInt( $button.closest( 'tr' ).data( 'id' ), 10 ) || 0;

			if ( ! id ) {
				return;
			}

			$button.prop( 'disabled', true );

			$.post( fbCurrencyObj.ajax_url, {
				action: 'fb_set_primary_currency',
				security: fbCurrencyObj.nonce,
				id: id,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						loadCurrencyData();
						return;
					}

					showStatus( response.data.message || fbCurrencyObj.i18n.server_error, 'error' );
				} )
				.fail( function () {
					showStatus( fbCurrencyObj.i18n.server_error, 'error' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );

		$tbody.on( 'click', '[data-action="delete"]', function () {
			const $button = $( this );
			const id = parseInt( $button.closest( 'tr' ).data( 'id' ), 10 ) || 0;

			if ( ! id || ! window.confirm( fbCurrencyObj.i18n.confirm_delete ) ) {
				return;
			}

			$button.prop( 'disabled', true );

			$.post( fbCurrencyObj.ajax_url, {
				action: 'fb_delete_currency',
				security: fbCurrencyObj.nonce,
				id: id,
			} )
				.done( function ( response ) {
					if ( response.success ) {
						showStatus( '', '' );
						loadCurrencyData();
						return;
					}

					showStatus( response.data.message || fbCurrencyObj.i18n.server_error, 'error' );
				} )
				.fail( function () {
					showStatus( fbCurrencyObj.i18n.server_error, 'error' );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	}

	function loadCurrencyData() {
		$tbody.addClass( 'is-loading' );
		showStatus( '', '' );

		$.post( fbCurrencyObj.ajax_url, {
			action: 'fb_load_currencies',
			security: fbCurrencyObj.nonce,
			family_id: $filterFamily.val() || 0,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					$tbody.html( response.data.html );
					initSortable();
					if ( response.data.count > 0 ) {
						showStatus( 'Показано валют: ' + response.data.count, 'success' );
					}
					return;
				}

				renderErrorRow( fbCurrencyObj.i18n.load_error );
			} )
			.fail( function () {
				renderErrorRow( fbCurrencyObj.i18n.server_error );
			} )
			.always( function () {
				$tbody.removeClass( 'is-loading' );
			} );
	}

	function initSortable() {
		if ( ! $.fn.sortable ) {
			return;
		}

		if ( $tbody.data( 'ui-sortable' ) ) {
			$tbody.sortable( 'destroy' );
		}

		if ( '0' === String( $filterFamily.val() || '0' ) ) {
			$tbody.find( '.fb-currency-drag' ).addClass( 'is-disabled' );
			return;
		}

		$tbody.find( '.fb-currency-drag' ).removeClass( 'is-disabled' );

		$tbody.sortable( {
			handle: '.fb-currency-drag',
			items: 'tr[data-id]',
			helper: function ( event, tr ) {
				const $originals = tr.children();
				const $helper = tr.clone();
				$helper.children().each( function ( index ) {
					$( this ).width( $originals.eq( index ).outerWidth() );
				} );
				return $helper;
			},
			update: function () {
				const order = [];
				$tbody.find( 'tr[data-id]' ).each( function () {
					order.push( $( this ).data( 'id' ) );
				} );

				$.post( fbCurrencyObj.ajax_url, {
					action: 'fb_reorder_currencies',
					security: fbCurrencyObj.nonce,
					order: order,
				} )
					.done( function ( response ) {
						if ( ! response.success ) {
							showStatus( response.data.message || fbCurrencyObj.i18n.server_error, 'error' );
							loadCurrencyData();
						}
					} )
					.fail( function () {
						showStatus( fbCurrencyObj.i18n.server_error, 'error' );
						loadCurrencyData();
					} );
			},
		} );
	}

	function syncFamilySelect() {
		const filterValue = String( $filterFamily.val() || '0' );
		const $familyInput = $( '#fb-currency-family-id' );

		if ( '0' !== filterValue ) {
			$familyInput.val( filterValue );
		}
	}

	function setFormState( disabled ) {
		$addForm.find( ':input' ).prop( 'disabled', disabled );
	}

	function showStatus( message, type ) {
		$status.removeClass( 'is-error is-success is-hidden' );

		if ( ! message ) {
			$status.addClass( 'is-hidden' ).text( '' );
			return;
		}

		if ( 'error' === type ) {
			$status.addClass( 'is-error' );
		}

		if ( 'success' === type ) {
			$status.addClass( 'is-success' );
		}

		$status.text( message );
	}

	function renderErrorRow( message ) {
		$tbody.html(
			'<tr><td colspan="7" class="fb-currency-empty">' + escapeHtml( message ) + '</td></tr>'
		);
	}

	function escapeHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}
} );
