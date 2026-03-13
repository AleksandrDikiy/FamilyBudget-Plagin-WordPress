/**
 * Family Budget — Модуль Показників лічильників
 *
 * Обробляє: завантаження/фільтрацію таблиці, додавання,
 * inline-редагування, видалення та прив'язку платежів.
 */
jQuery( document ).ready( function ( $ ) {

    const $tbody = $( '#fb-ind-tbody' );

    // Guard: запобігає подвійній ініціалізації
    if ( $tbody.data( 'fb-ind-init' ) ) return;
    $tbody.data( 'fb-ind-init', true );

    // Скидаємо всі попередні обробники (namespace .fbind)
    $tbody.off( '.fbind' );
    $( document ).off( '.fbind' );
    $( '#fb-ind-add-form' ).off( '.fbind' );

    // Поточний ID показника для модального вікна
    let currentIndId = 0;
    let searchTimer  = null;

    /* ───────────────────────────────────────────────────────────────────────
     * ЗАВАНТАЖЕННЯ ТАБЛИЦІ
     * ─────────────────────────────────────────────────────────────────────── */

    /**
     * Збирає фільтри та завантажує рядки таблиці через AJAX.
     */
    function loadIndicators() {
        $tbody.css( 'opacity', '0.5' );

        $.post( fbIndObj.ajax_url, {
            action:    'fb_ajax_ind_load',
            security:  fbIndObj.nonce,
            family_id: $( '#fb-ind-f-family' ).val(),
            house_id:  $( '#fb-ind-f-house' ).val(),
            account_id:$( '#fb-ind-f-account' ).val(),
            year:      $( '#fb-ind-f-year' ).val(),
            month:     $( '#fb-ind-f-month' ).val(),
        }, function ( res ) {
            $tbody.css( 'opacity', '1' );
            if ( res.success ) $tbody.html( res.data.html );
        } );
    }

    loadIndicators();

    // Реакція на зміну будь-якого фільтра
    $( document ).on( 'change.fbind',
        '#fb-ind-f-family, #fb-ind-f-house, #fb-ind-f-account, #fb-ind-f-year, #fb-ind-f-month',
        loadIndicators
    );

    /* ───────────────────────────────────────────────────────────────────────
     * ФОРМА ДОДАВАННЯ
     * ─────────────────────────────────────────────────────────────────────── */

    $( '#fb-ind-add-form' ).on( 'submit.fbind', function ( e ) {
        e.preventDefault();

        const $btn = $( this ).find( '.fb-btn-primary' ).prop( 'disabled', true );

        $.post( fbIndObj.ajax_url,
            $( this ).serialize() + '&action=fb_ajax_ind_add&security=' + fbIndObj.nonce,
            function ( res ) {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    $( '#fb-ind-add-form' )[ 0 ].reset();
                    loadIndicators();
                } else {
                    alert( res.data.message );
                }
            }
        );
    } );

    /* ───────────────────────────────────────────────────────────────────────
     * INLINE-РЕДАГУВАННЯ
     * ─────────────────────────────────────────────────────────────────────── */

    // Перехід у режим редагування
    $tbody.on( 'click.fbind', '.fb-edit-btn', function () {
        const $tr = $( this ).closest( 'tr' );
        $tr.find( '.fb-view-mode' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-btn' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-mode' ).removeClass( 'hidden' );
        $tr.find( '.fb-save-btn' ).removeClass( 'hidden' );
        $tr.find( '.fb-ind-edit-consumed' ).trigger( 'focus' );
    } );

    // Збереження
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
            if ( res.success ) {
                loadIndicators();
            } else {
                alert( res.data.message );
            }
        } );
    } );

    /* ───────────────────────────────────────────────────────────────────────
     * ВИДАЛЕННЯ
     * ─────────────────────────────────────────────────────────────────────── */

    $tbody.on( 'click.fbind', '.fb-delete-btn', function ( e ) {
        e.stopImmediatePropagation();

        if ( ! window.confirm( fbIndObj.confirm_delete ) ) return;

        const $btn = $( this ).css( 'opacity', '0.4' );

        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_delete',
            security: fbIndObj.nonce,
            id:       $btn.closest( 'tr' ).data( 'id' ),
        }, function ( res ) {
            if ( res.success ) {
                loadIndicators();
            } else {
                $btn.css( 'opacity', '1' );
                alert( res.data.message );
            }
        } );
    } );

    /* ───────────────────────────────────────────────────────────────────────
     * МОДАЛЬНЕ ВІКНО: ПРИВ'ЯЗКА ПЛАТЕЖУ
     * ─────────────────────────────────────────────────────────────────────── */

    const $modal        = $( '#fb-ind-modal' );
    const $searchInput  = $( '#fb-ind-search-input' );
    const $searchResult = $( '#fb-ind-search-results' );
    const $linkedList   = $( '#fb-ind-linked-list' );

    /** Відкриває модальне вікно та завантажує прив'язані платежі */
    function openModal( indId ) {
        currentIndId = indId;
        $searchInput.val( '' );
        $searchResult.html( '<p class="fb-ind-hint">Введіть запит для пошуку платежів</p>' );
        $modal.fadeIn( 150 );
        loadLinked();
        $searchInput.trigger( 'focus' );
    }

    /** Закриває модальне вікно */
    function closeModal() {
        $modal.fadeOut( 150 );
        currentIndId = 0;
        loadIndicators(); // Оновлюємо таблицю щоб відобразити нові суми
    }

    // Відкриття модального вікна при кліку на ⚙
    $tbody.on( 'click.fbind', '.fb-link-btn', function ( e ) {
        e.stopImmediatePropagation();
        openModal( $( this ).data( 'id' ) );
    } );

    // Закриття: кнопка × або клік на overlay
    $( '#fb-ind-modal-close' ).on( 'click.fbind', closeModal );
    $modal.on( 'click.fbind', function ( e ) {
        if ( $( e.target ).is( $modal ) ) closeModal();
    } );

    // Закриття через Escape
    $( document ).on( 'keydown.fbind', function ( e ) {
        if ( e.key === 'Escape' && $modal.is( ':visible' ) ) closeModal();
    } );

    /** Завантажує список прив'язаних платежів */
    function loadLinked() {
        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_get_linked',
            security: fbIndObj.nonce,
            ind_id:   currentIndId,
        }, function ( res ) {
            if ( ! res.success ) return;
            renderLinked( res.data.linked );
        } );
    }

    /** Рендерить список прив'язаних платежів */
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
                  + '<button class="fb-ind-unlink-btn" data-amount-id="' + parseInt( item.id ) + '">'
                  + 'Від\'язати</button>'
                  + '</div>';
        } );
        $linkedList.html( html );
    }

    /** Пошук платежів із debounce 400ms */
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
                action:   'fb_ajax_ind_search_amounts',
                security: fbIndObj.nonce,
                ind_id:   currentIndId,
                query:    q,
            }, function ( res ) {
                if ( ! res.success ) return;
                renderSearchResults( res.data.amounts, res.data.linked_ids );
            } );
        }, 400 );
    } );

    /** Рендерить результати пошуку */
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

    // Прив'язати платіж
    $searchResult.on( 'click.fbind', '.fb-ind-link-amount-btn', function () {
        const $btn = $( this ).prop( 'disabled', true ).text( '...' );

        $.post( fbIndObj.ajax_url, {
            action:    'fb_ajax_ind_link',
            security:  fbIndObj.nonce,
            ind_id:    currentIndId,
            amount_id: $btn.data( 'amount-id' ),
        }, function ( res ) {
            if ( res.success ) {
                loadLinked();
                $searchInput.trigger( 'input' ); // Оновлюємо результати пошуку
            } else {
                $btn.prop( 'disabled', false ).text( '+ Прив\'язати' );
                alert( res.data.message );
            }
        } );
    } );

    // Від'язати платіж
    $linkedList.on( 'click.fbind', '.fb-ind-unlink-btn', function () {
        if ( ! window.confirm( fbIndObj.confirm_unlink ) ) return;

        const $btn = $( this ).prop( 'disabled', true );

        $.post( fbIndObj.ajax_url, {
            action:    'fb_ajax_ind_unlink',
            security:  fbIndObj.nonce,
            ind_id:    currentIndId,
            amount_id: $btn.data( 'amount-id' ),
        }, function ( res ) {
            if ( res.success ) {
                loadLinked();
                $searchInput.trigger( 'input' );
            } else {
                $btn.prop( 'disabled', false );
                alert( res.data.message );
            }
        } );
    } );

    /* ───────────────────────────────────────────────────────────────────────
     * УТИЛІТИ
     * ─────────────────────────────────────────────────────────────────────── */

    /** Екранує HTML-символи для безпечного вставлення в DOM */
    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

} );
