/* global Chart */
( function ( $, window ) {
	'use strict';

	const INSTANCES = window.fbUtilityAnalyticsInstances || {};
	const PALETTE = [
		{ fill: 'rgba(37, 99, 235, 0.72)', border: 'rgba(29, 78, 216, 1)' },
		{ fill: 'rgba(14, 165, 233, 0.72)', border: 'rgba(3, 105, 161, 1)' },
		{ fill: 'rgba(16, 185, 129, 0.72)', border: 'rgba(4, 120, 87, 1)' },
		{ fill: 'rgba(245, 158, 11, 0.72)', border: 'rgba(180, 83, 9, 1)' },
		{ fill: 'rgba(239, 68, 68, 0.72)', border: 'rgba(185, 28, 28, 1)' },
		{ fill: 'rgba(168, 85, 247, 0.72)', border: 'rgba(126, 34, 206, 1)' },
		{ fill: 'rgba(236, 72, 153, 0.72)', border: 'rgba(190, 24, 93, 1)' },
		{ fill: 'rgba(132, 204, 22, 0.72)', border: 'rgba(77, 124, 15, 1)' },
	];

	Object.keys( INSTANCES ).forEach( function ( key ) {
		initModule( INSTANCES[ key ] );
	} );

	function initModule( cfg ) {
		const $root = $( '#' + cfg.rootId );
		if ( ! $root.length ) {
			return;
		}

		const root = $root.get( 0 );
		const els = {
			family: root.querySelector( '[data-role="family"]' ),
			house: root.querySelector( '[data-role="house"]' ),
			accountType: root.querySelector( '[data-role="account-type"]' ),
			groupBy: root.querySelector( '[data-role="group-by"]' ),
			period: root.querySelector( '[data-role="period"]' ),
			dateFrom: root.querySelector( '[data-role="date-from"]' ),
			dateTo: root.querySelector( '[data-role="date-to"]' ),
			refresh: root.querySelector( '[data-role="refresh"]' ),
			summary: root.querySelector( '[data-role="summary"]' ),
			status: root.querySelector( '[data-role="status"]' ),
			statusSpinner: root.querySelector( '[data-role="status-spinner"]' ),
			statusMessage: root.querySelector( '[data-role="status-message"]' ),
			chartWrap: root.querySelector( '[data-role="chart-wrap"]' ),
			canvas: document.getElementById( cfg.canvasId ),
		};

		let chart = null;
		let refreshTimer = null;
		let requestId = 0;

		if ( ! els.canvas || 'undefined' === typeof Chart ) {
			return;
		}

		bindEvents();
		toggleDateInputs();
		fetchData();

		function bindEvents() {
			$( els.refresh ).on( 'click', fetchData );

			$( [ els.accountType, els.groupBy, els.period ] ).on( 'change', function () {
				toggleDateInputs();
				scheduleRefresh();
			} );

			$( [ els.dateFrom, els.dateTo ] ).on( 'change', function () {
				if ( 'custom' === els.period.value ) {
					scheduleRefresh();
				}
			} );

			$( els.family ).on( 'change', function () {
				loadHouses().always( scheduleRefresh );
			} );

			$( els.house ).on( 'change', function () {
				scheduleRefresh();
			} );

		}

		function scheduleRefresh() {
			window.clearTimeout( refreshTimer );
			refreshTimer = window.setTimeout( fetchData, 180 );
		}

		function toggleDateInputs() {
			const customSelected = 'custom' === els.period.value;
			els.dateFrom.disabled = ! customSelected;
			els.dateTo.disabled = ! customSelected;
		}

		function ajax( action, data ) {
			return $.ajax( {
				url: cfg.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: Object.assign( {}, data, {
					action: action,
					security: cfg.security,
				} ),
			} );
		}

		function loadHouses() {
			const selected = parseInt( els.house.value, 10 ) || 0;

			return ajax( 'fb_utility_analytics_get_houses', {
				family_id: parseInt( els.family.value, 10 ) || 0,
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return;
				}

				const houses = response.data && response.data.houses ? response.data.houses : [];
				const options = [ '<option value="0">' + escHtml( cfg.i18n.allHouses ) + '</option>' ];

				houses.forEach( function ( house ) {
					const houseId = parseInt( house.id, 10 ) || 0;
					const isSelected = selected === houseId ? ' selected' : '';
					options.push(
						'<option value="' + houseId + '"' + isSelected + '>' + escHtml( house.house_name || '' ) + '</option>'
					);
				} );

				els.house.innerHTML = options.join( '' );

				if ( selected > 0 && ! houses.some( function ( house ) {
					return selected === ( parseInt( house.id, 10 ) || 0 );
				} ) ) {
					els.house.value = '0';
				}
			} );
		}

		function collectFilters() {
			return {
				family_id: parseInt( els.family.value, 10 ) || 0,
				house_id: parseInt( els.house.value, 10 ) || 0,
				account_type_id: parseInt( els.accountType.value, 10 ) || 0,
				group_by: els.groupBy.value,
				period: els.period.value,
				date_from: els.dateFrom.value,
				date_to: els.dateTo.value,
			};
		}

		function fetchData() {
			const currentRequestId = ++requestId;

			showStatus( cfg.i18n.loading, true );
			els.summary.innerHTML = '';

			ajax( 'fb_utility_analytics_get_chart_data', collectFilters() )
				.done( function ( response ) {
					if ( currentRequestId !== requestId ) {
						return;
					}

					if ( ! response.success ) {
						showStatus( resolveMessage( response ), false );
						renderEmpty();
						return;
					}

					const data = response.data || {};
					const labels = data.labels || [];
					const datasets = data.datasets || [];

					if ( ! labels.length || ! datasets.length ) {
						showStatus( buildNoDataMessage( data ), false );
						renderEmpty();
						return;
					}

					hideStatus();
					renderChart( labels, datasets );
					renderSummary( data );
				} )
				.fail( function ( xhr ) {
					if ( currentRequestId !== requestId ) {
						return;
					}

					showStatus( 'HTTP ' + xhr.status + ': ' + cfg.i18n.errorLoad, false );
					renderEmpty();
				} );
		}

		function renderChart( labels, datasetRows ) {
			if ( chart ) {
				chart.destroy();
			}

			const datasets = datasetRows.map( function ( dataset, index ) {
				const colors = PALETTE[ index % PALETTE.length ];

				return {
					label: dataset.label || cfg.i18n.consumed,
					data: Array.isArray( dataset.data ) ? dataset.data : [],
					backgroundColor: colors.fill,
					borderColor: colors.border,
					borderWidth: 1,
					borderRadius: 4,
					maxBarThickness: 42,
					houseName: dataset.house_name || '',
					accountTypeName: dataset.account_type_name || '',
				};
			} );

			chart = new Chart( els.canvas.getContext( '2d' ), {
				type: 'bar',
				data: {
					labels: labels,
					datasets: datasets,
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: true,
							position: 'bottom',
							labels: {
								boxWidth: 14,
								boxHeight: 14,
								padding: 14,
								color: '#334155',
							},
						},
						tooltip: {
							callbacks: {
								label: function ( context ) {
									const dataset = context.dataset || {};
									return ( dataset.label || cfg.i18n.consumed ) + ': ' + formatNumber( context.parsed.y );
								},
								afterLabel: function ( context ) {
									const dataset = context.dataset || {};
									const details = [];

									if ( dataset.houseName ) {
										details.push( cfg.i18n.house + ': ' + dataset.houseName );
									}

									if ( dataset.accountTypeName ) {
										details.push( cfg.i18n.accountType + ': ' + dataset.accountTypeName );
									}

									return details;
								},
							},
						},
					},
					scales: {
						x: {
							grid: {
								display: false,
							},
						},
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: cfg.i18n.consumed,
							},
							ticks: {
								callback: function ( value ) {
									return formatNumber( value );
								},
							},
						},
					},
				},
			} );

		}

		function renderEmpty() {
			if ( chart ) {
				chart.destroy();
				chart = null;
			}
		}

		function renderSummary( data ) {
			const total = formatNumber( data.total_consumed || 0 );
			const periodsCount = parseInt( data.periods_count, 10 ) || 0;
			const seriesCount = parseInt( data.series_count, 10 ) || 0;

			els.summary.innerHTML =
				'<strong>' + escHtml( cfg.i18n.total ) + ':</strong> ' + total +
				' <strong>' + escHtml( cfg.i18n.periods ) + ':</strong> ' + periodsCount +
				' <strong>' + escHtml( cfg.i18n.series ) + ':</strong> ' + seriesCount;
		}

		function showStatus( message, loading ) {
			if ( ! els.status ) {
				return;
			}

			els.status.classList.remove( 'is-hidden' );
			els.status.classList.toggle( 'is-loading', Boolean( loading ) );
			if ( els.statusMessage ) {
				els.statusMessage.textContent = message || '';
			}
			if ( els.statusSpinner ) {
				els.statusSpinner.style.display = loading ? 'inline-block' : 'none';
			}
		}

		function hideStatus() {
			if ( ! els.status ) {
				return;
			}

			els.status.classList.add( 'is-hidden' );
			els.status.classList.remove( 'is-loading' );
			if ( els.statusMessage ) {
				els.statusMessage.textContent = '';
			}
			if ( els.statusSpinner ) {
				els.statusSpinner.style.display = 'none';
			}
		}

		function resolveMessage( response ) {
			return response && response.data && response.data.message
				? response.data.message
				: cfg.i18n.errorLoad;
		}

		function buildNoDataMessage( data ) {
			const requested = data && data.requested_range ? data.requested_range : null;
			const available = data && data.available_range ? data.available_range : null;

			if ( requested && available && available.min && available.max ) {
				return cfg.i18n.requestedRange + ' (' +
					formatRange( requested.start, requested.end ) + '). ' +
					cfg.i18n.availableRange + ' ' +
					formatRange( available.min, available.max ) + '.';
			}

			return cfg.i18n.noData;
		}

	}

	function formatRange( start, end ) {
		return String( start || '' ) + ' - ' + String( end || '' );
	}

	function formatNumber( value ) {
		return parseFloat( value || 0 ).toLocaleString( 'uk-UA', {
			minimumFractionDigits: 0,
			maximumFractionDigits: 3,
		} );
	}

	function escHtml( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}
}( jQuery, window ) );
