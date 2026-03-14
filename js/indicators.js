/**
 * Family Budget — Модуль Показників лічильників
 * Версія з пагінацією (20 рядків на сторінку)
 */
jQuery( document ).ready( function ( $ ) {

    const $tbody      = $( '#fb-ind-tbody' );
    const $pagination = $( '#fb-ind-pagination' );

    // Guard: запобігає подвійній ініціалізації
    if ( $tbody.data( 'fb-ind-init' ) ) return;
    $tbody.data( 'fb-ind-init', true );

    $tbody.off( '.fbind' );
    $( document ).off( '.fbind' );
    $( '#fb-ind-add-form' ).off( '.fbind' );

    let currentPage  = 1;
    let currentIndId = 0;
    let searchTimer  = null;
    /* ─── ЗАВАНТАЖЕННЯ ТАБЛИЦІ ───────────────────────────────────────────── */

    function loadIndicators( page ) {
        currentPage = page || 1;
        $tbody.css( 'opacity', '0.5' );

        $.post( fbIndObj.ajax_url, {
            action:     'fb_ajax_ind_load',
            security:   fbIndObj.nonce,
            family_id:  $( '#fb-ind-f-family' ).val(),
            account_id: $( '#fb-ind-f-account' ).val(),
            year:       $( '#fb-ind-f-year' ).val(),
            month:      $( '#fb-ind-f-month' ).val(),
            page:       currentPage,
        }, function ( res ) {
            $tbody.css( 'opacity', '1' );
            if ( ! res.success ) return;

            $tbody.html( res.data.html );

            // Рендер пагінації
            renderPagination( res.data.page, res.data.total_pages, res.data.total );
        } );
    }

    loadIndicators( 1 );

    $( document ).on( 'change.fbind',
        '#fb-ind-f-family, #fb-ind-f-account, #fb-ind-f-year, #fb-ind-f-month',
        function () { loadIndicators( 1 ); }
    );

    /* ─── ПАГІНАЦІЯ ──────────────────────────────────────────────────────── */

    function renderPagination( page, totalPages, total ) {
        if ( totalPages <= 1 ) {
            $pagination.hide().empty();
            return;
        }

        $pagination.show();
        let html = '<div class="fb-ind-pag-info">Показано сторінку ' + page + ' з ' + totalPages
                 + ' (всього: ' + total + ')</div><div class="fb-ind-pag-btns">';

        // Кнопка "Перша"
        if ( page > 1 ) {
            html += '<button class="fb-ind-pag-btn" data-page="1">«</button>';
            html += '<button class="fb-ind-pag-btn" data-page="' + ( page - 1 ) + '">‹</button>';
        }

        // Кнопки сторінок (±2 від поточної)
        const from = Math.max( 1, page - 2 );
        const to   = Math.min( totalPages, page + 2 );

        for ( let i = from; i <= to; i++ ) {
            const active = i === page ? ' active' : '';
            html += '<button class="fb-ind-pag-btn' + active + '" data-page="' + i + '">' + i + '</button>';
        }

        // Кнопка "Остання"
        if ( page < totalPages ) {
            html += '<button class="fb-ind-pag-btn" data-page="' + ( page + 1 ) + '">›</button>';
            html += '<button class="fb-ind-pag-btn" data-page="' + totalPages + '">»</button>';
        }

        html += '</div>';
        $pagination.html( html );
    }

    // Клік по кнопці пагінації
    $( document ).on( 'click.fbind', '.fb-ind-pag-btn', function () {
        const page = parseInt( $( this ).data( 'page' ) );
        if ( page ) loadIndicators( page );
    } );

    /* ─── ФОРМА ДОДАВАННЯ ────────────────────────────────────────────────── */

    $( '#fb-ind-add-form' ).on( 'submit.fbind', function ( e ) {
        e.preventDefault();

        const $btn = $( this ).find( '.fb-btn-primary' ).prop( 'disabled', true );

        $.post( fbIndObj.ajax_url,
            $( this ).serialize() + '&action=fb_ajax_ind_add&security=' + fbIndObj.nonce,
            function ( res ) {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    $( '#fb-ind-add-form' )[ 0 ].reset();
                    $( '#fb-ind-f-month' ).val( '0' );
                    loadIndicators( 1 );
                } else {
                    alert( res.data.message );
                }
            }
        );
    } );

    /* ─── INLINE-РЕДАГУВАННЯ ─────────────────────────────────────────────── */

    $tbody.on( 'click.fbind', '.fb-edit-btn', function () {
        const $tr = $( this ).closest( 'tr' );
        $tr.find( '.fb-view-mode' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-btn' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-mode' ).removeClass( 'hidden' );
        $tr.find( '.fb-save-btn' ).removeClass( 'hidden' );
        $tr.find( '.fb-ind-edit-consumed' ).trigger( 'focus' );
    } );

    $tbody.on( 'click.fbind', '.fb-save-btn', function () {
        const $btn = $( this ).prop( 'disabled', true );
        const $tr  = $btn.closest( 'tr' );

        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_edit',
            security: fbIndObj.nonce,
            id:       $tr.data( 'id' ),
            pa_id:    $tr.find( '.fb-ind-edit-pa' ).val(),
            month:    $tr.find( '.fb-ind-edit-month' ).val(),
            year:     $tr.find( '.fb-ind-edit-year' ).val(),
            val1:     $tr.find( '.fb-ind-edit-val1' ).val(),
            val2:     $tr.find( '.fb-ind-edit-val2' ).val(),
            consumed: $tr.find( '.fb-ind-edit-consumed' ).val(),
        }, function ( res ) {
            $btn.prop( 'disabled', false );
            if ( res.success ) loadIndicators( currentPage );
            else alert( res.data.message );
        } );
    } );

    /* ─── ВИДАЛЕННЯ ──────────────────────────────────────────────────────── */

    $tbody.on( 'click.fbind', '.fb-delete-btn', function ( e ) {
        e.stopImmediatePropagation();
        if ( ! window.confirm( fbIndObj.confirm_delete ) ) return;

        const $btn = $( this ).css( 'opacity', '0.4' );

        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_delete',
            security: fbIndObj.nonce,
            id:       $btn.closest( 'tr' ).data( 'id' ),
        }, function ( res ) {
            if ( res.success ) loadIndicators( currentPage );
            else { $btn.css( 'opacity', '1' ); alert( res.data.message ); }
        } );
    } );

    /* ─── МОДАЛЬНЕ ВІКНО: ПРИВ'ЯЗКА ─────────────────────────────────────── */

    const $modal        = $( '#fb-ind-modal' );
    const $searchInput  = $( '#fb-ind-search-input' );
    const $searchResult = $( '#fb-ind-search-results' );
    const $linkedList   = $( '#fb-ind-linked-list' );

    function openModal( indId ) {
        currentIndId = indId;
        $searchInput.val( '' );
        $searchResult.html( '<p class="fb-ind-hint">Введіть запит для пошуку платежів</p>' );
        $modal.fadeIn( 150 );
        loadLinked();
        $searchInput.trigger( 'focus' );
    }

    function closeModal() {
        $modal.fadeOut( 150 );
        currentIndId = 0;
        loadIndicators( currentPage );
    }

    $tbody.on( 'click.fbind', '.fb-link-btn', function ( e ) {
        e.stopImmediatePropagation();
        openModal( $( this ).data( 'id' ) );
    } );

    $( '#fb-ind-modal-close' ).on( 'click.fbind', closeModal );
    $modal.on( 'click.fbind', function ( e ) { if ( $( e.target ).is( $modal ) ) closeModal(); } );
    $( document ).on( 'keydown.fbind', function ( e ) {
        if ( e.key === 'Escape' && $modal.is( ':visible' ) ) closeModal();
    } );

    function loadLinked() {
        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_get_linked',
            security: fbIndObj.nonce,
            ind_id:   currentIndId,
        }, function ( res ) { if ( res.success ) renderLinked( res.data.linked ); } );
    }

    function renderLinked( items ) {
        if ( ! items || ! items.length ) {
            $linkedList.html( '<p class="fb-ind-hint">Прив\'язаних платежів немає</p>' );
            return;
        }
        let html = '<div class="fb-ind-linked-title">Прив\'язані платежі:</div>';
        items.forEach( function ( item ) {
            html += '<div class="fb-ind-linked-item">'
                  + '<span class="fb-ind-linked-val">' + escHtml( item.Amount_Value ) + '</span>'
                  + '<span class="fb-ind-linked-note">' + escHtml( item.Note || '' ) + '</span>'
                  + '<button class="fb-ind-unlink-btn" data-amount-id="' + parseInt( item.id ) + '">Від\'язати</button>'
                  + '</div>';
        } );
        $linkedList.html( html );
    }

    $searchInput.on( 'input.fbind', function () {
        clearTimeout( searchTimer );
        const q = $( this ).val().trim();
        if ( q.length < 1 ) {
            $searchResult.html( '<p class="fb-ind-hint">Введіть запит для пошуку</p>' );
            return;
        }
        searchTimer = setTimeout( function () {
            $searchResult.html( '<p class="fb-ind-hint">Пошук...</p>' );
            $.post( fbIndObj.ajax_url, {
                action: 'fb_ajax_ind_search_amounts', security: fbIndObj.nonce,
                ind_id: currentIndId, query: q,
            }, function ( res ) {
                if ( res.success ) renderSearchResults( res.data.amounts, res.data.linked_ids );
            } );
        }, 400 );
    } );

    function renderSearchResults( amounts, linkedIds ) {
        if ( ! amounts || ! amounts.length ) {
            $searchResult.html( '<p class="fb-ind-hint">Нічого не знайдено</p>' );
            return;
        }
        let html = '';
        amounts.forEach( function ( a ) {
            const isLinked = linkedIds.indexOf( parseInt( a.id ) ) !== -1;
            html += '<div class="fb-ind-result-item' + ( isLinked ? ' is-linked' : '' ) + '">'
                  + '<span class="fb-ind-res-val">' + escHtml( a.Amount_Value ) + '</span>'
                  + '<span class="fb-ind-res-note">' + escHtml( a.Note || '' ) + '</span>'
                  + ( isLinked
                      ? '<span class="fb-ind-already">✓ Прив\'язано</span>'
                      : '<button class="fb-ind-link-amount-btn" data-amount-id="' + parseInt( a.id ) + '">+ Прив\'язати</button>'
                    )
                  + '</div>';
        } );
        $searchResult.html( html );
    }

    $searchResult.on( 'click.fbind', '.fb-ind-link-amount-btn', function () {
        const $btn = $( this ).prop( 'disabled', true ).text( '...' );
        $.post( fbIndObj.ajax_url, {
            action: 'fb_ajax_ind_link', security: fbIndObj.nonce,
            ind_id: currentIndId, amount_id: $btn.data( 'amount-id' ),
        }, function ( res ) {
            if ( res.success ) { loadLinked(); $searchInput.trigger( 'input' ); }
            else { $btn.prop( 'disabled', false ).text( '+ Прив\'язати' ); alert( res.data.message ); }
        } );
    } );

    $linkedList.on( 'click.fbind', '.fb-ind-unlink-btn', function () {
        if ( ! window.confirm( fbIndObj.confirm_unlink ) ) return;
        const $btn = $( this ).prop( 'disabled', true );
        $.post( fbIndObj.ajax_url, {
            action: 'fb_ajax_ind_unlink', security: fbIndObj.nonce,
            ind_id: currentIndId, amount_id: $btn.data( 'amount-id' ),
        }, function ( res ) {
            if ( res.success ) { loadLinked(); $searchInput.trigger( 'input' ); }
            else { $btn.prop( 'disabled', false ); alert( res.data.message ); }
        } );
    } );

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

} );
