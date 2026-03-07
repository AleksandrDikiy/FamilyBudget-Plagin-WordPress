/**
 * Family Budget — Модуль графіків (Charts JS)
 *
 * Відповідає за:
 *  - Ініціалізацію при DOMContentLoaded
 *  - Керування фільтрами (тип категорій, мультивибір, period, by_days)
 *  - AJAX-запити до wp_ajax_fb_get_chart_data
 *  - Рендеринг вертикальної стовпчастої діаграми через Chart.js 4.x
 *  - UI-стани: завантаження / немає даних / помилка мережі
 *
 * Залежності (WordPress enqueue):
 *  - jquery
 *  - chart-js (Chart.js 4.4.1)
 *  - fbChartsData (wp_localize_script)
 *
 * @package    FamilyBudget
 * @subpackage Assets/JS
 * @version    1.0.27.0
 * @since      1.0.27.0
 */
/* global fbChartsData, Chart */
( function ( $ ) {
    'use strict';

    // ============================================================
    // СТАН МОДУЛЯ
    // ============================================================

    /** @type {Chart|null} Поточний екземпляр Chart.js */
    var chartInstance = null;

    /** @type {jqXHR|null} Поточний AJAX-запит (скасовується при нових) */
    var currentXhr = null;

    /** @type {number|null} ID таймера дебаунсу для полів кастомних дат */
    var dateDebounce = null;

    // ============================================================
    // ІНІЦІАЛІЗАЦІЯ
    // ============================================================

    $( document ).ready( function () {
        // Перевіряємо наявність wp_localize_script даних
        if ( typeof fbChartsData === 'undefined' ) {
            if ( window.console && window.console.error ) {
                console.error( '[FB Charts] Відсутні дані fbChartsData. Перевірте wp_localize_script.' );
            }
            return;
        }

        // Перевіряємо що шорткод [fb_charts] є на сторінці
        if ( ! $( '#fb-chart-canvas' ).length ) {
            return;
        }

        bindEvents();

        // Початкова видимість блоку кастомних дат
        applyDateRangeVisibility( $( '#fb-filter-period' ).val() );

        // Початкове завантаження графіка
        loadChartData();
    } );

    // ============================================================
    // ПРИВ'ЯЗКА ПОДІЙ
    // ============================================================

    /**
     * Прив'язує обробники до всіх елементів фільтрів
     * @returns {void}
     */
    function bindEvents() {

        // Тип категорій
        $( '#fb-filter-category-type' ).on( 'change', function () {
            filterCategoriesByType( $( this ).val() );
            loadChartData();
        } );

        // Категорії (мультивибір)
        $( '#fb-filter-category' ).on( 'change', function () {
            resolveAllOption( $( this ) );
            loadChartData();
        } );

        // Рахунки (мультивибір)
        $( '#fb-filter-account' ).on( 'change', function () {
            resolveAllOption( $( this ) );
            loadChartData();
        } );

        // Валюта
        $( '#fb-filter-currency' ).on( 'change', function () {
            loadChartData();
        } );

        // Період
        $( '#fb-filter-period' ).on( 'change', function () {
            var period = $( this ).val();
            applyDateRangeVisibility( period );
            // При "вручну" — чекаємо введення дат перш ніж запитувати
            if ( 'custom' !== period ) {
                loadChartData();
            }
        } );

        // Аналіз по днях
        $( '#fb-filter-by-days' ).on( 'change', function () {
            loadChartData();
        } );

        // Кастомні дати — дебаунс 600ms
        $( '#fb-filter-date-from, #fb-filter-date-to' ).on( 'change', function () {
            clearTimeout( dateDebounce );
            dateDebounce = setTimeout( function () {
                var from = $( '#fb-filter-date-from' ).val();
                var to   = $( '#fb-filter-date-to' ).val();
                if ( from && to ) {
                    loadChartData();
                }
            }, 600 );
        } );
    }

    // ============================================================
    // ЛОГІКА ФІЛЬТРІВ
    // ============================================================

    /**
     * Показує або приховує блок кастомного діапазону дат
     *
     * [ПРИМІТКА] Використовуємо клас .is-visible (не inline style),
     * щоб CSS міг перевизначати через display:none !important.
     *
     * @param {string} period Обраний тип периоду
     * @returns {void}
     */
    function applyDateRangeVisibility( period ) {
        var $block = $( '#fb-date-range-block' );
        if ( 'custom' === period ) {
            $block.addClass( 'is-visible' ).attr( 'aria-hidden', 'false' );
        } else {
            $block.removeClass( 'is-visible' ).attr( 'aria-hidden', 'true' );
        }
    }

    /**
     * Вмикає/вимикає опції мультивибору категорій залежно від типу
     *
     * [BUG-FIX] В Firefox .toggle() на <option> не працює.
     * Використовуємо prop('disabled') + знімаємо виділення з вимкнених.
     *
     * @param {string} typeId ID типу або 'all'
     * @returns {void}
     */
    function filterCategoriesByType( typeId ) {
        var $sel = $( '#fb-filter-category' );

        $sel.find( 'option[data-type-id]' ).each( function () {
            var $opt    = $( this );
            var optType = String( $opt.data( 'type-id' ) );
            var disable = ( 'all' !== typeId && optType !== typeId );

            $opt.prop( 'disabled', disable );

            // Знімаємо виділення якщо опцію вимкнено
            if ( disable && $opt.prop( 'selected' ) ) {
                $opt.prop( 'selected', false );
            }
        } );

        // Скидаємо на «Всі» при зміні типу
        $sel.find( 'option[value="all"]' ).prop( 'selected', true );
    }

    /**
     * Керує логікою опції «— Всі —» у <select multiple>
     *
     * Правила:
     *  - «Всі» + конкретні → знімаємо «Всі»
     *  - Нічого → повертаємо «Всі»
     *  - Тільки «Всі» → лишаємо «Всі»
     *
     * @param {jQuery} $select Елемент <select multiple>
     * @returns {void}
     */
    function resolveAllOption( $select ) {
        var $allOpt      = $select.find( 'option[value="all"]' );
        var $specificOpts = $select.find( 'option:not([value="all"])' );
        var allSelected  = $allOpt.prop( 'selected' );
        var anySelected  = $specificOpts.filter( ':selected:not(:disabled)' ).length > 0;

        if ( allSelected && anySelected ) {
            $allOpt.prop( 'selected', false );
        } else if ( ! allSelected && ! anySelected ) {
            $allOpt.prop( 'selected', true );
        }
    }

    /**
     * Збирає значення всіх фільтрів для AJAX-запиту
     *
     * @returns {Object} Параметри запиту включно з nonce та family_id
     */
    function getFilters() {
        var catVal = $( '#fb-filter-category' ).val() || [ 'all' ];
        var accVal = $( '#fb-filter-account' ).val()  || [ 'all' ];

        return {
            action        : 'fb_get_chart_data',
            nonce         : fbChartsData.nonce,
            family_id     : fbChartsData.familyId,
            category_type : $( '#fb-filter-category-type' ).val() || 'all',
            category      : catVal,
            account       : accVal,
            currency_id   : $( '#fb-filter-currency' ).val() || 0,
            period        : $( '#fb-filter-period' ).val()    || 'current_month',
            date_from     : $( '#fb-filter-date-from' ).val(),
            date_to       : $( '#fb-filter-date-to' ).val(),
            by_days       : $( '#fb-filter-by-days' ).is( ':checked' ) ? 'true' : 'false',
        };
    }

    // ============================================================
    // AJAX: ЗАВАНТАЖЕННЯ ДАНИХ
    // ============================================================

    /**
     * Запускає AJAX-запит для отримання даних графіка
     *
     * - Скасовує попередній запит (запобігає гонкам відповідей)
     * - При period='custom' без дат — очікує без запиту
     * - Показує оверлей завантаження на весь час запиту
     *
     * @returns {void}
     */
    function loadChartData() {
        var filters = getFilters();

        // При кастомному діапазоні — чекаємо обидві дати
        if ( 'custom' === filters.period &&
             ( ! filters.date_from || ! filters.date_to ) ) {
            return;
        }

        // Скасовуємо попередній незавершений запит
        if ( currentXhr ) {
            currentXhr.abort();
            currentXhr = null;
        }

        showOverlay( 'loading', fbChartsData.strings.loading );

        currentXhr = $.ajax( {
            url      : fbChartsData.ajaxUrl,
            type     : 'POST',
            dataType : 'json',
            data     : filters,
        } );

        currentXhr
            .done( function ( resp ) {
                if ( resp && resp.success ) {
                    renderChart( resp.data );
                } else {
                    var msg = ( resp && resp.data && resp.data.message )
                        ? resp.data.message
                        : fbChartsData.strings.error;
                    showOverlay( 'error', msg );
                    destroyChart();
                    updateStatus( '' );
                }
            } )
            .fail( function ( xhr ) {
                // Не показуємо помилку якщо запит скасований навмисне
                if ( 'abort' !== xhr.statusText ) {
                    showOverlay( 'error', fbChartsData.strings.networkErr );
                    destroyChart();
                    updateStatus( '' );
                }
            } )
            .always( function () {
                currentXhr = null;
            } );
    }

    // ============================================================
    // РЕНДЕРИНГ ГРАФІКА
    // ============================================================

    /**
     * Рендерить вертикальну стовпчасту діаграму
     *
     * При порожніх даних → showOverlay('nodata', ...)
     * Перед рендерингом — знищує попередній екземпляр.
     *
     * Конфігурація Chart.js:
     *  - type: 'bar' (вертикальний)
     *  - Легенда: top, обмежена висота 60px
     *  - Вісь X: без сітки, малий шрифт
     *  - Вісь Y: починається з 0, тисячний роздільник + символ валюти
     *  - Підказки: назва датасету + значення + символ валюти
     *
     * @param {Object} data Відповідь AJAX: { labels, datasets, total, currency }
     * @returns {void}
     */
    function renderChart( data ) {
        // Порожня відповідь
        if ( ! data || ! data.labels || 0 === data.labels.length ) {
            showOverlay( 'nodata', fbChartsData.strings.noData );
            destroyChart();
            updateStatus( '' );
            return;
        }

        hideOverlay();
        destroyChart();

        var ctx = document.getElementById( 'fb-chart-canvas' );
        if ( ! ctx ) {
            return;
        }

        var sym = data.currency || '';

        chartInstance = new Chart( ctx, {
            type : 'bar',
            data : {
                labels   : data.labels,
                datasets : data.datasets,
            },
            options : {
                responsive          : true,
                maintainAspectRatio : false,
                animation           : { duration : 400 },
                plugins             : {
                    legend  : {
                        position  : 'top',
                        maxHeight : 60,
                        labels    : {
                            boxWidth : 12,
                            font     : { size : 11 },
                            padding  : 8,
                        },
                    },
                    tooltip : {
                        callbacks : {
                            label : function ( context ) {
                                var val = context.parsed.y;
                                if ( null === val || isNaN( val ) ) {
                                    return '';
                                }
                                var formatted = val.toFixed( 2 )
                                    .replace( /\B(?=(\d{3})+(?!\d))/g, '\u00a0' ); // NBSP
                                return ' ' + context.dataset.label + ': ' + formatted +
                                    ( sym ? ' ' + sym : '' );
                            },
                        },
                    },
                },
                scales : {
                    x : {
                        stacked : false,
                        grid    : { display : false },
                        ticks   : { font : { size : 11 } },
                    },
                    y : {
                        beginAtZero : true,
                        stacked     : false,
                        ticks       : {
                            font     : { size : 11 },
                            callback : function ( value ) {
                                return value.toLocaleString( 'uk-UA', {
                                    maximumFractionDigits : 0,
                                } ) + ( sym ? '\u00a0' + sym : '' );
                            },
                        },
                    },
                },
            },
        } );

        // Рядок стану із загальною сумою
        if ( null != data.total ) {
            var totalStr = parseFloat( data.total )
                .toFixed( 2 )
                .replace( /\B(?=(\d{3})+(?!\d))/g, '\u00a0' );
            updateStatus( fbChartsData.strings.total + ' ' + totalStr + ( sym ? '\u00a0' + sym : '' ) );
        }
    }

    /**
     * Знищує поточний екземпляр Chart.js і звільняє пам'ять canvas
     * @returns {void}
     */
    function destroyChart() {
        if ( chartInstance ) {
            chartInstance.destroy();
            chartInstance = null;
        }
    }

    // ============================================================
    // UI-ПОМІЧНИКИ
    // ============================================================

    /**
     * Показує оверлей на канвасі
     *
     * @param {'loading'|'nodata'|'error'} type    Тип стану
     * @param {string}                    message Текст повідомлення
     * @returns {void}
     */
    function showOverlay( type, message ) {
        var $overlay = $( '#fb-charts-overlay' );

        $overlay
            .removeClass( 'fb-charts-overlay--loading fb-charts-overlay--nodata fb-charts-overlay--error' )
            .addClass( 'fb-charts-overlay--' + type )
            .addClass( 'is-visible' );

        $overlay.find( '.fb-charts-overlay-text' ).text( message || '' );
        $overlay.find( '.fb-spinner' ).toggle( 'loading' === type );
    }

    /**
     * Приховує оверлей після успішного рендерингу
     * @returns {void}
     */
    function hideOverlay() {
        $( '#fb-charts-overlay' ).removeClass( 'is-visible' );
    }

    /**
     * Оновлює рядок стану (загальна сума)
     * @param {string} text Текст
     * @returns {void}
     */
    function updateStatus( text ) {
        $( '#fb-charts-status' ).text( text );
    }

} )( jQuery );
