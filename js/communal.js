/**
 * communal.js – Frontend logic for the Family Budget Communal Services module.
 *
 * Responsibilities:
 *  - Populate the Parameters multiselect via AJAX when the Category changes.
 *  - Fetch chart data from the backend via AJAX on filter changes / Apply click.
 *  - Render a vertical Bar Chart using Chart.js 4.
 *  - Re-render smoothly when filters change without full page reload.
 *
 * Depends on: jQuery (bundled with WP), Chart.js 4 (CDN), fbCommunal (wp_localize_script).
 *
 * @package    FamilyBudget
 * @subpackage Communal
 * @version    1.0.27.3
 */

/* global jQuery, fbCommunal, Chart */
( function ( $ ) {
    'use strict';

    // ─── DOM references ────────────────────────────────────────────────────────

    var $wrapper    = $( '#fb-communal-wrapper' );

    // Guard: only run when the module is present on the page.
    if ( ! $wrapper.length ) { return; }

    var $category   = $( '#fb-communal-category',    $wrapper );
    var $years      = $( '#fb-communal-years',        $wrapper );
    var $params     = $( '#fb-communal-params',       $wrapper );
    var $byMonth    = $( '#fb-communal-by-month',     $wrapper );
    var $applyBtn   = $( '#fb-communal-apply',        $wrapper );
    var $spinner    = $( '#fb-communal-spinner',      $wrapper );
    var $noData     = $( '#fb-communal-no-data',      $wrapper );
    var $chartTitle = $( '#fb-communal-chart-title',  $wrapper );
    var canvas      = document.getElementById( 'fb-communal-chart' );

    // ─── State ─────────────────────────────────────────────────────────────────

    var communalChart     = null;  // Chart.js instance (singleton – destroyed & re-created)
    var paramsXhr         = null;  // Pending XHR for param loading (abortable)
    var chartXhr          = null;  // Pending XHR for chart data  (abortable)

    // ─── Internationalisation (injected by wp_localize_script) ─────────────────

    var i18n = fbCommunal.i18n || {};

    function t( key, fallback ) {
        return i18n[ key ] || fallback || key;
    }

    // ─── Utility: spinner ──────────────────────────────────────────────────────

    function showSpinner( show ) {
        $spinner.toggle( !! show );
    }

    // ─── Utility: collect selected values from a <select> ─────────────────────

    function getSelected( $select ) {
        var vals = $select.val();
        return Array.isArray( vals ) ? vals : ( vals ? [ vals ] : [] );
    }

    // ─── Utility: show / hide the "no data" message ────────────────────────────

    function showMessage( msg ) {
        $noData.text( msg ).show();
    }

    function hideMessage() {
        $noData.hide().text( '' );
    }

    // ─── Utility: destroy existing Chart instance ─────────────────────────────

    function destroyChart() {
        if ( communalChart ) {
            communalChart.destroy();
            communalChart = null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  STEP 1 – Load parameters for the selected category (Filter 3)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Fetch CategoryParam rows for the currently selected category from the
     * backend AJAX endpoint and rebuild the Parameters <select>.
     *
     * @param {Function} [onComplete] Optional callback invoked after the select
     *                                is populated (used to chain chart loading).
     */
    function loadParams( onComplete ) {
        var categoryId = $category.val();
        if ( ! categoryId ) { return; }

        // Abort any in-flight param request
        if ( paramsXhr ) { paramsXhr.abort(); }

        showSpinner( true );
        $params.empty().append(
            $( '<option>', { disabled: true, text: t( 'loading', 'Loading…' ) } )
        );

        paramsXhr = $.ajax( {
            url    : fbCommunal.ajaxUrl,
            method : 'POST',
            data   : {
                action      : 'fb_ajax_communal_get_params',
                nonce       : fbCommunal.nonce,
                category_id : categoryId,
            },

            success : function ( response ) {
                $params.empty();

                if ( response.success && response.data && response.data.length ) {
                    $.each( response.data, function ( i, param ) {
                        $params.append(
                            $( '<option>', {
                                value    : param.id,
                                text     : param.CategoryParam_Name,
                                selected : true,          // select all by default
                            } )
                        );
                    } );
                } else {
                    $params.append(
                        $( '<option>', {
                            disabled : true,
                            text     : t( 'noParams', 'No parameters found' ),
                        } )
                    );
                }

                showSpinner( false );

                if ( typeof onComplete === 'function' ) {
                    onComplete();
                }
            },

            error : function ( jqXHR, status ) {
                if ( status === 'abort' ) { return; }
                showSpinner( false );
                $params.empty().append(
                    $( '<option>', { disabled: true, text: t( 'error', 'Error loading parameters' ) } )
                );
            },
        } );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  STEP 2 – Fetch chart data & render
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Collect filter values, request chart data from the backend, then render
     * (or update) the Chart.js instance.
     */
    function loadChartData() {
        var categoryId = $category.val();
        var years      = getSelected( $years );
        var paramIds   = getSelected( $params );
        var byMonth    = $byMonth.is( ':checked' ) ? 1 : 0;

        // Nothing to fetch if mandatory filters are empty
        if ( ! categoryId || ! paramIds.length ) { return; }

        // Abort previous chart request
        if ( chartXhr ) { chartXhr.abort(); }

        showSpinner( true );
        hideMessage();

        chartXhr = $.ajax( {
            url    : fbCommunal.ajaxUrl,
            method : 'POST',
            data   : {
                action      : 'fb_ajax_communal_get_chart_data',
                nonce       : fbCommunal.nonce,
                category_id : categoryId,
                years       : years,
                param_ids   : paramIds,
                by_month    : byMonth,
            },

            success : function ( response ) {
                showSpinner( false );

                if ( ! response.success ) {
                    destroyChart();
                    showMessage(
                        ( response.data && response.data.message )
                            ? response.data.message
                            : t( 'error', 'An error occurred.' )
                    );
                    return;
                }

                var labels   = response.data.labels   || [];
                var datasets = response.data.datasets || [];

                if ( ! labels.length ) {
                    destroyChart();
                    showMessage( t( 'noData', 'No data for selected filters.' ) );
                    return;
                }

                hideMessage();
                renderChart( labels, datasets );
                updateTitle( byMonth );
            },

            error : function ( jqXHR, status ) {
                if ( status === 'abort' ) { return; }
                showSpinner( false );
                destroyChart();
                showMessage( t( 'error', 'Network error. Please retry.' ) );
            },
        } );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  Chart.js rendering
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Create a new Chart.js bar chart, or update the existing instance in-place
     * to avoid expensive teardown/setup on every filter change.
     *
     * @param {string[]} labels   X-axis period labels (e.g. '2024', '2024-03').
     * @param {Object[]} datasets Chart.js dataset objects from the backend.
     */
    function renderChart( labels, datasets ) {
        if ( communalChart ) {
            // Smooth in-place update – avoids canvas teardown flicker
            communalChart.data.labels   = labels;
            communalChart.data.datasets = datasets;
            communalChart.update( 'active' );
            return;
        }

        communalChart = new Chart( canvas, {
            type : 'bar',
            data : {
                labels   : labels,
                datasets : datasets,
            },
            options : {
                responsive          : true,
                maintainAspectRatio : false,
                animation           : { duration: 400 },

                plugins : {
                    legend : {
                        position : 'top',
                        labels   : {
                            boxWidth  : 12,
                            padding   : 16,
                            font      : { size: 12 },
                        },
                    },
                    tooltip : {
                        callbacks : {
                            label : function ( ctx ) {
                                return '  ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            },
                        },
                    },
                },

                scales : {
                    x : {
                        stacked : false,
                        grid    : { display: false },
                        ticks   : {
                            font       : { size: 11 },
                            maxRotation: 45,
                            minRotation: 0,
                        },
                    },
                    y : {
                        beginAtZero : true,
                        title       : {
                            display : true,
                            text    : 'Використано (різниця)',
                            font    : { size: 11 },
                        },
                        ticks : { font: { size: 11 } },
                        grid  : { color: 'rgba(0,0,0,0.06)' },
                    },
                },
            },
        } );
    }

    // ─── Dynamic chart title ───────────────────────────────────────────────────

    function updateTitle( byMonth ) {
        var catName = $category.find( 'option:selected' ).text().trim();
        var mode    = byMonth ? t( 'byMonth', 'by Month' ) : t( 'byYear', 'by Year' );
        $chartTitle.text( catName + ' — ' + mode );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  Event listeners
    // ═══════════════════════════════════════════════════════════════════════════

    // Category change → reload parameters → reload chart
    $category.on( 'change', function () {
        loadParams( loadChartData );
    } );

    // "Apply" button → reload chart with current filter state
    $applyBtn.on( 'click', loadChartData );

    // "By Months" toggle → immediate chart refresh (no param reload needed)
    $byMonth.on( 'change', loadChartData );

    // ═══════════════════════════════════════════════════════════════════════════
    //  Initialisation
    // ═══════════════════════════════════════════════════════════════════════════

    ( function init() {
        // Pre-select current year (server already marks it selected; this is a JS safety net)
        var curYear = String( new Date().getFullYear() );
        $years.find( 'option[value="' + curYear + '"]' ).prop( 'selected', true );

        // Load params for the first (default) category, then auto-render chart
        var firstCategoryId = $category.val();
        if ( firstCategoryId ) {
            loadParams( loadChartData );
        }
    } )();

} )( jQuery );
