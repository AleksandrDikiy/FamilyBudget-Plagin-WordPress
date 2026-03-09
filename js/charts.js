/**
 * Family Budget — Модуль "Графіки"  v5
 *
 * Зміни v5:
 *  - [ВИПРАВЛЕННЯ] collectFilters(): guard — якщо amount_type_id не готовий
 *    (dropdown ще завантажується), fetchAndRender() повертає overlay-повідомлення
 *    і НЕ відправляє запит з amount_type_id = 0.
 *  - [ВИПРАВЛЕННЯ] bindEvents(): додано авто-оновлення графіка при зміні
 *    #fb-amount-type, #fb-category-type, #fb-group-by без кнопки "Оновити".
 *  - [ВИПРАВЛЕННЯ] document.ready: видалено передчасний виклик fetchAndRender().
 *    Перший рендер тепер запускається через trigger('change') з inline-скрипту
 *    charts.php після успішного AJAX-завантаження типів рахунків.
 *
 * Зміни v4:
 *  - Дати завжди видимі: toggle disabled замість show/hide
 *  - При виборі "Довільний" → enabled; інакше → disabled
 *
 * @package    FamilyBudget
 * @subpackage JS\Charts
 * @since      1.0.0
 */

/* global fbChartsConfig, Chart */
( function ( $ ) {
	'use strict';

	const CFG = window.fbChartsConfig || {};

	/** Палітра кольорів для категорій (16 відтінків) */
	const PAL = [
		'rgba( 31,119,180,.80)', 'rgba(255,127, 14,.80)', 'rgba(214, 39, 40,.80)',
		'rgba( 44,160, 44,.80)', 'rgba(148,103,189,.80)', 'rgba(140, 86, 75,.80)',
		'rgba(227,119,194,.80)', 'rgba(127,127,127,.80)', 'rgba(188,189, 34,.80)',
		'rgba( 23,190,207,.80)', 'rgba(255,187, 51,.80)', 'rgba( 76,153,  0,.80)',
		'rgba(  0,128,128,.80)', 'rgba(153,  0, 76,.80)', 'rgba(255, 99,132,.80)',
		'rgba( 54,162,235,.80)',
	];
	const PAL_B = PAL.map( c => c.replace( '.80', '1' ) );

	/** @type {Chart|null} */
	let fbChart = null;

	/** Стан вибраних ID у мультивиборах */
	const ms = { categories: [], accounts: [] };

	// ── УТИЛІТИ ─────────────────────────────────────────────

	/**
	 * AJAX-запит до WordPress admin-ajax.php
	 * nonce передається у полі 'security' (check_ajax_referer у PHP).
	 *
	 * @param  {string} action
	 * @param  {object} data
	 * @return {jQuery.Promise}
	 */
	function ajax( action, data ) {
		return $.ajax( {
			url    : CFG.ajaxUrl,
			method : 'POST',
			data   : Object.assign( {}, data, { action, security: CFG.security } ),
		} );
	}

	/**
	 * Збирає значення всіх фільтрів.
	 * Поля дат відправляються завжди — PHP ігнорує їх якщо period != 'custom'.
	 *
	 * amount_type_id: нормалізується до integer.
	 * Якщо dropdown ще не завантажено (val = null / ""), повертає 0.
	 * fetchAndRender() перевіряє це значення та блокує передчасний запит.
	 *
	 * @return {object}
	 */
	function collectFilters() {
		return {
			family_id        : $( '#fb-family' ).val(),
			group_by         : $( '#fb-group-by' ).val(),
			category_type_id : $( '#fb-category-type' ).val(),
			amount_type_id   : parseInt( $( '#fb-amount-type' ).val(), 10 ) || 0,
			period           : $( '#fb-period' ).val(),
			date_begin       : $( '#fb-date-begin' ).val(),
			date_end         : $( '#fb-date-end' ).val(),
			categories       : ms.categories,
			accounts         : ms.accounts,
		};
	}

	/**
	 * Форматує число у локалізований рядок з двома знаками після коми
	 *
	 * @param  {number} v
	 * @return {string} Наприклад «57 000,00»
	 */
	function fmt( v ) {
		return parseFloat( v ).toLocaleString( 'uk-UA', {
			minimumFractionDigits  : 2,
			maximumFractionDigits  : 2,
		} );
	}

	// ── DEBUG ────────────────────────────────────────────────

	/**
	 * Виводить SQL та параметри у debug-блок і консоль
	 *
	 * @param {object} data Поле data з AJAX-відповіді
	 */
	function showDebug( data ) {
		if ( ! CFG.debug ) { return; }

		const $block = $( '#fb-cht-debug' );
		if ( ! $block.length ) { return; }

		$block.show();
		$( '#fb-debug-sql' ).text( data.debug_sql    || '—' );
		$( '#fb-debug-error' ).text( data.debug_db_error || '—' );
		$( '#fb-debug-params' ).text( data.debug_params ? JSON.stringify( data.debug_params, null, 2 ) : '—' );

		console.group( '[FB Charts DEBUG]' );
		console.log( 'SQL:', data.debug_sql || '—' );
		console.log( 'DB Error:', data.debug_db_error || 'none' );
		console.log( 'Params:', data.debug_params || {} );
		console.groupEnd();
	}

	// ── PIVOT ────────────────────────────────────────────────

	/**
	 * Зводить плоский масив рядків у Chart.js datasets
	 *
	 * Вхід: [ { period, cat_id, cat_name, amount }, ... ]
	 * Вихід: { labels, datasets }
	 *
	 * @param  {Array}  rows
	 * @return {{ labels: string[], datasets: object[] }}
	 */
	function pivot( rows ) {
		const pMap = new Map();
		const cMap = new Map();

		rows.forEach( r => {
			if ( ! pMap.has( r.period ) ) { pMap.set( r.period, pMap.size ); }
			if ( ! cMap.has( r.cat_id ) ) { cMap.set( r.cat_id, r.cat_name ); }
		} );

		const labels  = [ ...pMap.keys() ];
		const catKeys = [ ...cMap.keys() ];
		const lookup  = {};
		rows.forEach( r => { lookup[ r.cat_id + '|' + r.period ] = r.amount; } );

		return {
			labels,
			datasets : catKeys.map( ( id, i ) => ( {
				label           : cMap.get( id ),
				data            : labels.map( p => lookup[ id + '|' + p ] || 0 ),
				backgroundColor : PAL[ i % PAL.length ],
				borderColor     : PAL_B[ i % PAL_B.length ],
				borderWidth     : 1,
				borderRadius    : 2,
			} ) ),
		};
	}

	// ── МУЛЬТИВИБІР ──────────────────────────────────────────

	/**
	 * Заповнює список мультивибору переданими елементами
	 *
	 * @param {string} sel
	 * @param {Array}  items
	 * @param {string} nameKey
	 */
	function msPop( sel, items, nameKey ) {
		const $ms   = $( sel );
		const $drop = $ms.find( '.fb-ms__drop' );
		const name  = $ms.data( 'name' );

		$drop.empty();

		const $all = $( '<label class="fb-ms__item fb-ms__item--all"></label>' );
		$( '<input type="checkbox" value="" checked>' ).appendTo( $all );
		$all.append( ' ' + CFG.i18n.allSelected );
		$drop.append( $all );

		( items || [] ).forEach( item => {
			const $l = $( '<label class="fb-ms__item"></label>' );
			$( '<input type="checkbox">' ).val( item.id ).appendTo( $l );
			$l.append( ' ' + ( item[ nameKey ] || '' ) );
			$drop.append( $l );
		} );

		ms[ name ] = [];
		msLabel( $ms );
		$drop.off( 'change', 'input' ).on( 'change', 'input', function () {
			msChange( $ms, $( this ) );
		} );
	}

	/**
	 * Обробляє зміну стану чекбоксів у мультивиборі
	 *
	 * @param {jQuery} $ms
	 * @param {jQuery} $chk
	 */
	function msChange( $ms, $chk ) {
		const name    = $ms.data( 'name' );
		const $allChk = $ms.find( '.fb-ms__item--all input' );
		const $items  = $ms.find( '.fb-ms__item:not(.fb-ms__item--all) input' );
		const isAll   = $chk.closest( '.fb-ms__item--all' ).length > 0;

		if ( isAll ) {
			$items.prop( 'checked', false );
			ms[ name ] = [];
		} else {
			const sel = [];
			$items.each( function () {
				if ( $( this ).is( ':checked' ) ) { sel.push( parseInt( $( this ).val(), 10 ) ); }
			} );
			ms[ name ] = sel;
			$allChk.prop( 'checked', sel.length === 0 );
		}
		msLabel( $ms );
	}

	/**
	 * Оновлює текст кнопки мультивибору
	 *
	 * @param {jQuery} $ms
	 */
	function msLabel( $ms ) {
		const n = ms[ $ms.data( 'name' ) ].length;
		$ms.find( '.fb-ms__lbl' ).text( n === 0 ? CFG.i18n.allSelected : n + ' ' + CFG.i18n.nSelected );
	}

	/** Ініціалізує toggle-поведінку мультивиборів */
	function msInitToggle() {
		$( document ).on( 'click', '.fb-ms__btn', function ( e ) {
			e.stopPropagation();
			const $ms  = $( this ).closest( '.fb-ms' );
			const open = $ms.hasClass( 'fb-ms--open' );
			$( '.fb-ms--open' ).not( $ms ).removeClass( 'fb-ms--open' ).find( '.fb-ms__btn' ).attr( 'aria-expanded', 'false' );
			$ms.toggleClass( 'fb-ms--open', ! open );
			$( this ).attr( 'aria-expanded', String( ! open ) );
		} );
		$( document ).on( 'click', () => $( '.fb-ms--open' ).removeClass( 'fb-ms--open' ).find( '.fb-ms__btn' ).attr( 'aria-expanded', 'false' ) );
		$( document ).on( 'click', '.fb-ms__drop', e => e.stopPropagation() );
	}

	// ── ЗАЛЕЖНІ AJAX ФІЛЬТРИ ─────────────────────────────────

	/**
	 * Завантажує категорії для родини
	 *
	 * @param {number} fid
	 * @return {jQuery.Promise}
	 */
	function loadCats( fid ) {
		return ajax( 'fb_charts_get_filter_data', { family_id: fid, data_type: 'categories' } )
			.done( r => { if ( r.success ) { msPop( '#fb-ms-categories', r.data, 'Category_Name' ); } } );
	}

	/**
	 * Завантажує рахунки для родини
	 *
	 * @param {number} fid
	 * @return {jQuery.Promise}
	 */
	function loadAcc( fid ) {
		return ajax( 'fb_charts_get_filter_data', { family_id: fid, data_type: 'accounts' } )
			.done( r => { if ( r.success ) { msPop( '#fb-ms-accounts', r.data, 'Account_Name' ); } } );
	}

	// ── ГРАФІК ───────────────────────────────────────────────

	/**
	 * Керує оверлеєм стану (завантаження / помилка / «нема даних»)
	 *
	 * Три стани:
	 *  overlay('Завантаження...', true)  → спінер + текст + canvas dimmed
	 *  overlay('Дані відсутні',   false) → тільки текст, спінер ПРИХОВАНИЙ
	 *  overlay('',                false) → оверлей знімається, canvas повний
	 *
	 * Причина явного $sp.hide(): CSS-анімація (@keyframes) спінера продовжує
	 * рендеритись навіть при display:none якщо батьківський елемент має
	 * opacity > 0 або visibility:visible — тому скидаємо обидва атрибути.
	 *
	 * @param {string}  msg
	 * @param {boolean} loading
	 */
	function overlay( msg, loading ) {
		const $ov = $( '#fb-chart-overlay' );
		const $sp = $( '#fb-chart-spinner' );
		const $m  = $( '#fb-chart-msg' );
		const $cv = $( '#fb-budget-chart' );

		if ( msg ) {
			$m.text( msg );
			$ov.addClass( 'fb-cht-overlay--on' );

			if ( loading ) {
				// Стан "завантаження": спінер видимий, canvas приглушений
				$sp.show();
				$cv.css( 'opacity', 0.3 );
			} else {
				// Стан "повідомлення" (нема даних / помилка): спінер явно прихований
				$sp.hide();
				$sp.css( 'visibility', 'hidden' ); // подвійний захист від CSS-анімації
				$cv.css( 'opacity', 0 );
			}
		} else {
			// Стан "дані є": повністю знімаємо оверлей, відновлюємо спінер для наступного разу
			$ov.removeClass( 'fb-cht-overlay--on' );
			$sp.show().css( 'visibility', 'visible' );
			$cv.css( 'opacity', 1 );
		}
	}

	/**
	 * Рендерить або оновлює екземпляр Chart.js
	 *
	 * @param {string[]} labels
	 * @param {object[]} datasets
	 */
	function renderChart( labels, datasets ) {
		const el = document.getElementById( 'fb-budget-chart' );
		if ( ! el ) { return; }
		if ( fbChart ) { fbChart.destroy(); fbChart = null; }

		fbChart = new Chart( el, {
			type : 'bar',
			data : { labels, datasets },
			options : {
				responsive          : true,
				maintainAspectRatio : false,
				animation           : { duration: 300 },
				layout              : { padding: { top: 2, right: 2 } },
				plugins : {
					legend  : { display: true, position: 'top', labels: { boxWidth: 12, boxHeight: 12, padding: 8, font: { size: 11 } } },
					tooltip : { callbacks: {
						title : c => c[0].label,
						label : c => ' ' + c.dataset.label + ': ' + fmt( c.parsed.y ) + ' ₴',
					} },
				},
				scales : {
					x : { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 11 }, maxRotation: 45 } },
					y : { beginAtZero: true, grid: { color: 'rgba(0,0,0,.06)' }, ticks: { color: '#6b7280', font: { size: 11 }, callback: v => fmt( v ) } },
				},
			},
		} );
	}

	/**
	 * Завантажує дані через AJAX та перерендерює графік.
	 *
	 * Guard: якщо amount_type_id = 0 (dropdown ще завантажується через AJAX),
	 * запит НЕ відправляється — показується overlay-повідомлення.
	 * Це запобігає передчасному запиту до PHP з некоректним параметром
	 * та помилці 'Необхідно вказати тип рахунку'.
	 */
	function fetchAndRender() {
		const filters = collectFilters();

		// Guard: amount_type_id є обов'язковим — не відправляємо запит поки dropdown не готовий
		if ( filters.amount_type_id < 1 ) {
			overlay( CFG.i18n.loading, true );
			return;
		}

		overlay( CFG.i18n.loading, true );
		$( '#fb-chart-footer' ).empty();

		ajax( 'fb_charts_get_data', filters )
			.done( function ( r ) {
				if ( r.data ) { showDebug( r.data ); }

				if ( ! r.success ) {
					overlay( ( r.data && r.data.message ) || CFG.i18n.errorLoad, false );
					return;
				}

				const rows  = r.data.rows  || [];
				const total = r.data.total || 0;

				if ( rows.length === 0 ) {
					overlay( CFG.i18n.noData, false );
					return;
				}

				const cd = pivot( rows );
				overlay( '', false );
				renderChart( cd.labels, cd.datasets );

				$( '#fb-chart-footer' ).html(
					'<strong>' + CFG.i18n.total + ': <span class="fb-cht-total">' + fmt( total ) + ' ₴</span></strong>'
				);
			} )
			.fail( function ( xhr ) {
				overlay( 'HTTP ' + xhr.status + ': ' + CFG.i18n.errorLoad, false );
				if ( CFG.debug ) { console.error( '[FB Charts] AJAX fail:', xhr.status, xhr.responseText ); }
			} );
	}

	// ── ОБРОБНИКИ ПОДІЙ ──────────────────────────────────────

	/**
	 * Прив'язує обробники подій до елементів фільтра.
	 *
	 * Логіка оновлення графіка:
	 *  - #fb-family          → перезавантажує залежні фільтри (категорії, рахунки),
	 *                          але НЕ тригерить fetchAndRender напряму —
	 *                          мультивибори скидаються через msPop(), що є достатньо.
	 *  - #fb-amount-type     → авто-оновлення графіка (обов'язковий фільтр)
	 *  - #fb-category-type   → авто-оновлення графіка
	 *  - #fb-group-by        → авто-оновлення графіка
	 *  - #fb-period          → toggle disabled на полях дат (fetch НЕ запускає —
	 *                          користувач ще має обрати дати або натиснути "Оновити")
	 *  - #fb-apply-btn       → ручний запуск (для custom-дат та мультивиборів)
	 */
	function bindEvents() {

		// Зміна родини → перезавантаження категорій та рахунків
		$( '#fb-family' ).on( 'change', function () {
			const fid = parseInt( $( this ).val(), 10 );
			$.when( loadCats( fid ), loadAcc( fid ) );
		} );

		// Зміна типу рахунку / типу операцій / групування → авто-оновлення графіка
		$( '#fb-amount-type, #fb-category-type, #fb-group-by' ).on( 'change', fetchAndRender );

		// Зміна "Період" → toggle disabled на полях дат
		$( '#fb-period' ).on( 'change', function () {
			const isCustom = $( this ).val() === 'custom';

			$( '#fb-date-begin, #fb-date-end' )
				.prop( 'disabled', ! isCustom )
				.toggleClass( 'fb-cht-date--active', isCustom );
		} );

		// Кнопка «Оновити» — ручний запуск (custom-дати, мультивибори)
		$( '#fb-apply-btn' ).on( 'click', fetchAndRender );
	}

	// ── ІНІЦІАЛІЗАЦІЯ ────────────────────────────────────────

	$( document ).ready( function () {
		if ( ! CFG.ajaxUrl ) { return; }

		msPop( '#fb-ms-categories', CFG.initialCategories || [], 'Category_Name' );
		msPop( '#fb-ms-accounts',   CFG.initialAccounts   || [], 'Account_Name' );
		msInitToggle();
		bindEvents();

		/*
		 * fetchAndRender() тут НЕ викликається навмисно.
		 *
		 * Причина: #fb-amount-type ще порожній у цей момент — dropdown
		 * заповнюється асинхронно через AJAX в inline-скрипті charts.php
		 * (fb_charts_inline_init_script → fb_charts_get_amount_types).
		 *
		 * Перший рендер графіка запускається так:
		 *   inline-скрипт → AJAX успішний → $sel.val(defaultId) →
		 *   $sel.trigger('change') → bindEvents: #fb-amount-type change →
		 *   fetchAndRender() → collectFilters() → amount_type_id > 0 ✓
		 */
	} );

}( jQuery ) );
