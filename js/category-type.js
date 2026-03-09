/**
 * Family Budget — Модуль "Типи Категорій"
 * Оновлено: виправлено подвійне спрацьовування подій
 */

/* global fbCategoryTypeObj, jQuery */
( function( $ ) {
    'use strict';

    const CFG = window.fbCategoryTypeObj || {};
    const $tbody = $( '#fb-category-type-tbody' );

    function loadTable() {
        const family_id = $( '#fb-filter-ct-family' ).val();
        $tbody.html( '<tr><td colspan="4" class="text-center">Завантаження...</td></tr>' );

        $.post( CFG.ajax_url, {
            action:   'fb_load_category_types',
            security: CFG.nonce,
            family_id: family_id
        } )
            .done( function( res ) {
                if ( res.success && res.data && res.data.html ) {
                    $tbody.html( res.data.html );
                } else {
                    $tbody.html( '<tr><td colspan="4" class="text-center">Помилка завантаження даних</td></tr>' );
                }
            } )
            .fail( function() {
                $tbody.html( '<tr><td colspan="4" class="text-center">' + CFG.err_req + '</td></tr>' );
            } );
    }

    function initSortable() {
        $tbody.sortable({
            handle: '.fb-drag-handle',
            update: function() {
                const order = [];
                $tbody.find( 'tr' ).each( function() {
                    order.push( $( this ).data( 'id' ) );
                });

                $.post( CFG.ajax_url, {
                    action:   'fb_reorder_category_types',
                    security: CFG.nonce,
                    order:    order
                } );
            }
        });
    }

    // ── ОБРОБНИКИ ПОДІЙ (з використанням .off() для запобігання дублюванню) ──

    $( '#fb-filter-ct-family' ).off( 'change.fbCatType' ).on( 'change.fbCatType', loadTable );

    $( '#fb-add-ct-form' ).off( 'submit.fbCatType' ).on( 'submit.fbCatType', function( e ) {
        e.preventDefault();
        const $form = $( this );
        const $btn  = $form.find( 'button[type="submit"]' );

        $btn.prop( 'disabled', true );

        $.post( CFG.ajax_url, $form.serialize() + '&action=fb_add_category_type&security=' + CFG.nonce )
            .done( function( res ) {
                if ( res.success ) {
                    $form[0].reset();
                    loadTable();
                } else {
                    alert( res.data.message || CFG.err_req );
                }
            } )
            .fail( function() { alert( CFG.err_req ); } )
            .always( function() { $btn.prop( 'disabled', false ); } );
    });

    // Inline-редагування: Вхід
    $tbody.off( 'click.fbEdit' ).on( 'click.fbEdit', '.fb-edit-btn[data-action="edit"]', function( e ) {
        e.preventDefault();
        const $row = $( this ).closest( 'tr' );
        $row.find( '.fb-ct-name-text' ).addClass( 'hidden' );
        $row.find( '.fb-ct-name-input' ).removeClass( 'hidden' ).trigger( 'focus' );

        $row.find( '.fb-edit-btn, .fb-delete-btn' ).addClass( 'hidden' );
        $row.find( '.fb-save-btn, .fb-cancel-btn' ).removeClass( 'hidden' );
    });

    // Inline-редагування: Скасування
    $tbody.off( 'click.fbCancel' ).on( 'click.fbCancel', '.fb-cancel-btn[data-action="cancel"]', function( e ) {
        e.preventDefault();
        const $row = $( this ).closest( 'tr' );
        const origVal = $row.find( '.fb-ct-name-text' ).text();

        $row.find( '.fb-ct-name-input' ).val( origVal ).addClass( 'hidden' );
        $row.find( '.fb-ct-name-text' ).removeClass( 'hidden' );

        $row.find( '.fb-save-btn, .fb-cancel-btn' ).addClass( 'hidden' );
        $row.find( '.fb-edit-btn, .fb-delete-btn' ).removeClass( 'hidden' );
    });

    // Inline-редагування: Збереження
    $tbody.off( 'click.fbSave' ).on( 'click.fbSave', '.fb-save-btn[data-action="save"]', function( e ) {
        e.preventDefault();
        const $row = $( this ).closest( 'tr' );
        const id   = $row.data( 'id' );
        const name = $.trim( $row.find( '.fb-ct-name-input' ).val() );

        if ( ! name ) return;

        $( this ).prop( 'disabled', true );

        $.post( CFG.ajax_url, {
            action:   'fb_edit_category_type',
            security: CFG.nonce,
            id:       id,
            name:     name
        } )
            .done( function( res ) {
                if ( res.success ) {
                    $row.find( '.fb-ct-name-text' ).text( name );
                    $row.find( '.fb-cancel-btn' ).trigger( 'click' );
                } else {
                    alert( res.data.message || CFG.err_req );
                    $row.find( '.fb-cancel-btn' ).trigger( 'click' );
                }
            } )
            .fail( function() { alert( CFG.err_req ); } )
            .always( function() { $row.find( '.fb-save-btn' ).prop( 'disabled', false ); } );
    });

    // Видалення (Виправлено подвійний клік)
    $tbody.off( 'click.fbDelete' ).on( 'click.fbDelete', '.fb-delete-btn[data-action="delete"]', function( e ) {
        e.preventDefault();
        e.stopImmediatePropagation(); // Блокуємо спливання події, щоб уникнути дублів

        if ( ! confirm( CFG.confirm_del ) ) return;

        const $row = $( this ).closest( 'tr' );
        const id   = $row.data( 'id' );

        $.post( CFG.ajax_url, {
            action:   'fb_delete_category_type',
            security: CFG.nonce,
            id:       id
        } )
            .done( function( res ) {
                if ( res.success ) {
                    $row.fadeOut( 300, function() { $( this ).remove(); } );
                } else {
                    alert( res.data.message || CFG.err_req );
                }
            } )
            .fail( function() { alert( CFG.err_req ); } );
    });

    // ── ІНІЦІАЛІЗАЦІЯ ──
    $( document ).ready( function() {
        loadTable();
        initSortable();
    });

})( jQuery );