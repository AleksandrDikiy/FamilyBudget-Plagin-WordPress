/**
 * Модуль "Головна" — Family Budget Plugin.
 *
 * ЧАСТИНА 1: DOM Layout Fixer — одноразове виправлення layout теми.
 * ЧАСТИНА 2: AJAX-сабміт форми → success-блок з кнопкою "Бюджет".
 *
 * @package FamilyBudget
 */

( function ( $, cfg ) {
	'use strict';

	var FONT    = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';
	var fbFixed = false; // Прапор: виправлення вже виконано, повторно не запускати.

	/* ===========================================================
	 * ЧАСТИНА 1: DOM LAYOUT FIXER (запускається лише один раз)
	 * ========================================================= */

	/**
	 * Встановлює CSS-властивість з !important через setProperty.
	 *
	 * @param {Element} el   DOM-елемент.
	 * @param {string}  prop CSS-властивість (kebab-case).
	 * @param {string}  val  Значення.
	 */
	function fi( el, prop, val ) {
		try { el.style.setProperty( prop, val, 'important' ); } catch ( e ) {}
	}

	/**
	 * Одноразове виправлення layout батьківських та дочірніх елементів.
	 * Нейтралізує display:grid, column-count, float від теми WordPress.
	 * Після першого успішного виконання більше не запускається.
	 *
	 * @returns {void}
	 */
	function fbFixLayout() {
		if ( fbFixed ) { return; }

		var wrap = document.getElementById( 'fb-home-wrap' );
		if ( ! wrap ) { return; }

		// Позначаємо до будь-яких змін стилів — щоб не спрацювала рекурсія.
		fbFixed = true;

		// --- Стилі на обгортці ---
		[
			[ 'display',             'block'   ],
			[ 'float',               'none'    ],
			[ 'clear',               'both'    ],
			[ 'width',               '100%'    ],
			[ 'max-width',           '600px'   ],
			[ 'margin-left',         'auto'    ],
			[ 'margin-right',        'auto'    ],
			[ 'grid-column',         '1 / -1'  ],
			[ 'column-span',         'all'     ],
			[ '-webkit-column-span', 'all'     ],
			[ 'letter-spacing',      'normal'  ],
			[ 'word-spacing',        'normal'  ],
			[ 'font-family',         FONT      ],
		].forEach( function ( p ) { fi( wrap, p[0], p[1] ); } );

		// --- Обхід батьків — скидання column/grid теми ---
		var parent = wrap.parentElement;
		var depth  = 0;

		while ( parent && parent.tagName !== 'BODY' && depth < 12 ) {
			var cs       = window.getComputedStyle( parent );
			var display  = cs.display;
			var colCount = parseInt( cs.columnCount, 10 );

			if ( ! isNaN( colCount ) && colCount > 1 ) {
				fi( parent, 'column-count', '1'    );
				fi( parent, 'columns',      'auto' );
			}
			if ( display === 'grid' || display === 'inline-grid' ) {
				fi( wrap, 'grid-column',  '1 / -1'  );
				fi( wrap, 'align-self',   'start'   );
				fi( wrap, 'justify-self', 'stretch' );
			}
			if ( display === 'flex' || display === 'inline-flex' ) {
				fi( wrap, 'flex',       '1 1 100%'   );
				fi( wrap, 'align-self', 'flex-start' );
			}

			fi( parent, 'letter-spacing', 'normal' );
			parent = parent.parentElement;
			depth++;
		}

		// --- Обхід нащадків — скидання float/grid/width ---
		wrap.querySelectorAll( 'h2,p,form,div:not(.fb-cur-row),label' ).forEach( function ( child ) {
			var cs = window.getComputedStyle( child );

			if ( cs.display === 'grid' || cs.display === 'inline-grid' ) {
				fi( child, 'display',               'block' );
				fi( child, 'grid-template-columns', 'unset' );
			}
			if ( cs.float !== 'none' ) {
				fi( child, 'float', 'none' );
				fi( child, 'clear', 'both' );
			}

			fi( child, 'width',          '100%'  );
			fi( child, 'letter-spacing', 'normal' );
			fi( child, 'font-family',    FONT     );
		} );

		wrap.querySelectorAll( 'input,button,span,a' ).forEach( function ( el ) {
			fi( el, 'font-family',    FONT     );
			fi( el, 'letter-spacing', 'normal' );
		} );

		// Flex-рядок валюти.
		var curRow = wrap.querySelector( '.fb-cur-row' );
		if ( curRow ) {
			fi( curRow, 'display',   'flex'       );
			fi( curRow, 'flex-flow', 'row nowrap' );
			fi( curRow, 'width',     '100%'       );
			fi( curRow, 'float',     'none'       );
		}
	}

	// Запускаємо один раз — одразу та після завантаження DOM.
	document.addEventListener( 'DOMContentLoaded', fbFixLayout );

	// Якщо скрипт підключений у footer і DOM вже готовий.
	if ( document.readyState === 'complete' || document.readyState === 'interactive' ) {
		fbFixLayout();
	}

	/* ===========================================================
	 * ЧАСТИНА 2: AJAX-САБМІТ
	 * ========================================================= */

	$( function () {
		var $form   = $( '#fb-form' );
		var $notice = $( '#fb-notice' );
		var $btn    = $( '#fb-save' );
		var $lbl    = $( '#fb-save-label' );

		if ( ! $form.length ) { return; }

		/**
		 * Показує повідомлення у блоці #fb-notice.
		 *
		 * @param {string} text HTML-вміст.
		 * @param {string} mod  'ok' або 'err'.
		 */
		function showNotice( text, mod ) {
			$notice.removeClass( 'ok err' ).addClass( mod ).html( text ).show();
		}

		/**
		 * Приховує повідомлення.
		 */
		function hideNotice() {
			$notice.hide().removeClass( 'ok err' ).html( '' );
		}

		/**
		 * Перемикає стан кнопки збереження.
		 *
		 * @param {boolean} on true — режим завантаження.
		 */
		function setLoading( on ) {
			$btn.prop( 'disabled', on );
			$lbl.text( on ? cfg.i18n.saving : 'ЗБЕРЕГТИ' );
		}

		/**
		 * Клієнтська валідація обов'язкових полів.
		 *
		 * @returns {boolean}
		 */
		function validate() {
			var ok = true;
			$form.find( 'input[required]' ).each( function () {
				var $f = $( this );
				if ( ! $.trim( $f.val() ) ) {
					$f.addClass( 'is-invalid' );
					ok = false;
				} else {
					$f.removeClass( 'is-invalid' );
				}
			} );
			return ok;
		}

		/**
		 * Відображає success-стан: ховає форму, показує зелений блок
		 * з повідомленням та кнопкою переходу на сторінку бюджету.
		 *
		 * @param {string} message   Текст повідомлення.
		 * @param {string} budgetUrl URL сторінки бюджету.
		 */
		function showSuccess( message, budgetUrl ) {
			$form.hide();

			var safeMsg = $( '<span>' ).text( message ).html();
			var url     = budgetUrl || cfg.budgetUrl || '/budget/';

			var btnStyle = [
				'display:inline-flex', 'align-items:center', 'justify-content:center',
				'gap:8px', 'height:48px', 'padding:0 28px', 'margin:14px 0 0',
				'background:#4f6bf4', 'color:#fff',
				'font-size:15px', 'font-family:' + FONT, 'font-weight:700',
				'line-height:1', 'letter-spacing:.06em', 'text-transform:uppercase',
				'border:none', 'border-radius:8px', 'cursor:pointer',
				'text-decoration:none', '-webkit-appearance:none', 'appearance:none',
			].join( ';' );

			var html =
				'<div style="display:block;padding:18px 22px;background:#f0fff4;' +
					'border:1.5px solid #68d391;border-radius:10px;box-sizing:border-box;">' +
					'<p style="display:block;margin:0 0 4px;padding:0;font-size:17px;' +
						'font-weight:700;color:#276749;line-height:1.3;font-family:' + FONT + ';">' +
						'✅ ' + safeMsg +
					'</p>' +
					'<p style="display:block;margin:0;padding:0;font-size:14px;' +
						'color:#2f855a;line-height:1.5;font-family:' + FONT + ';">' +
						'Родину, валюту, рахунок та категорію збережено.' +
					'</p>' +
					'<a href="' + url + '" style="' + btnStyle + '">' +
						'<span style="font-size:17px;line-height:1;">📊</span>' +
						'<span>БЮДЖЕТ</span>' +
					'</a>' +
				'</div>';

			$notice.removeClass( 'ok err' ).html( html ).show();

			$( 'html, body' ).animate(
				{ scrollTop: $notice.offset().top - 40 },
				300
			);
		}

		// Знімаємо is-invalid при введенні.
		$form.on( 'input', 'input[required]', function () {
			if ( $.trim( $( this ).val() ) ) {
				$( this ).removeClass( 'is-invalid' );
			}
		} );

		// Сабміт форми.
		$form.on( 'submit', function ( e ) {
			e.preventDefault();
			hideNotice();

			if ( ! validate() ) {
				showNotice( $( '<span>' ).text( cfg.i18n.errReq ).html(), 'err' );
				return;
			}

			setLoading( true );

			$.post( cfg.ajaxUrl, {
				action          : 'fb_ajax_save_onboarding',
				security        : cfg.nonce,
				family_name     : $.trim( $( '#fb_family' ).val() ),
				currency_name   : $.trim( $( '#fb_currency' ).val() ),
				currency_code   : $.trim( $( '#fb_code' ).val() ),
				currency_symbol : $.trim( $( '#fb_symbol' ).val() ),
				account_name    : $.trim( $( '#fb_account' ).val() ),
				category_name   : $.trim( $( '#fb_category' ).val() ),
			} )
			.done( function ( res ) {
				if ( res.success ) {
					showSuccess(
						res.data.message,
						res.data.budget_url || cfg.budgetUrl
					);
				} else {
					var msg = ( res.data && res.data.message )
						? res.data.message
						: cfg.i18n.errSrv;
					showNotice( $( '<span>' ).text( msg ).html(), 'err' );
					setLoading( false );
				}
			} )
			.fail( function ( xhr ) {
				var detail = xhr.responseText
					? xhr.responseText.substring( 0, 200 )
					: '';
				showNotice(
					$( '<span>' ).text( cfg.i18n.errSrv + ( detail ? ' | ' + detail : '' ) ).html(),
					'err'
				);
				setLoading( false );
			} );
		} );

	} );

} )( jQuery, window.fbHome || {} );
