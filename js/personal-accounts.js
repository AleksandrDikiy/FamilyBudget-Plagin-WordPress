/**
 * Family Budget — Модуль Особових рахунків
 *
 * Забезпечує AJAX-взаємодію: завантаження, фільтрація,
 * додавання, inline-редагування та видалення рахунків.
 */
jQuery( document ).ready( function ( $ ) {

    const $tbody = $( '#fb-pa-tbody' );

    // Guard: запобігає подвійній ініціалізації якщо скрипт завантажено двічі
    if ( $tbody.data( 'fb-pa-init' ) ) return;
    $tbody.data( 'fb-pa-init', true );

    // Знімаємо всі попередні обробники перед реєстрацією (namespace .fbpa)
    $tbody.off( '.fbpa' );
    $( document ).off( 'change.fbpa' );
    $( '#fb-pa-add-form' ).off( 'submit.fbpa' );

    /* ─── Завантаження / перезавантаження таблиці ──────────────────────── */

    function loadAccounts() {
        $tbody.css( 'opacity', '0.5' );

        $.post(
            fbPaObj.ajax_url,
            {
                action:   'fb_ajax_pa_load',
                security: fbPaObj.nonce,
                house_id: $( '#fb-pa-filter-house' ).val(),
                type_id:  $( '#fb-pa-filter-type' ).val(),
            },
            function ( res ) {
                $tbody.css( 'opacity', '1' );
                if ( res.success ) {
                    $tbody.html( res.data.html );
                }
            }
        );
    }

    loadAccounts();

    $( document ).on( 'change.fbpa', '#fb-pa-filter-house, #fb-pa-filter-type', loadAccounts );

    /* ─── Форма додавання ───────────────────────────────────────────────── */

    $( '#fb-pa-add-form' ).on( 'submit.fbpa', function ( e ) {
        e.preventDefault();

        const $btn = $( this ).find( '.fb-btn-primary' ).prop( 'disabled', true );

        $.post(
            fbPaObj.ajax_url,
            $( this ).serialize() + '&action=fb_ajax_pa_add&security=' + fbPaObj.nonce,
            function ( res ) {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    $( '#fb-pa-add-form' )[ 0 ].reset();
                    loadAccounts();
                } else {
                    alert( res.data.message );
                }
            }
        );
    } );

    /* ─── Inline-редагування: перехід у режим edit ──────────────────────── */

    $tbody.on( 'click.fbpa', '.fb-edit-btn', function () {
        const $tr = $( this ).closest( 'tr' );

        $tr.find( '.fb-view-mode' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-btn' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-mode' ).removeClass( 'hidden' );
        $tr.find( '.fb-save-btn' ).removeClass( 'hidden' );
        $tr.find( '.fb-pa-edit-number' ).trigger( 'focus' );
    } );

    /* ─── Inline-редагування: збереження ───────────────────────────────── */

    $tbody.on( 'click.fbpa', '.fb-save-btn', function () {
        const $btn = $( this ).prop( 'disabled', true );
        const $tr  = $btn.closest( 'tr' );

        $.post(
            fbPaObj.ajax_url,
            {
                action:   'fb_ajax_pa_edit',
                security: fbPaObj.nonce,
                id:       $tr.data( 'id' ),
                type_id:  $tr.find( '.fb-pa-edit-type' ).val(),
                number:   $tr.find( '.fb-pa-edit-number' ).val(),
            },
            function ( res ) {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    loadAccounts();
                } else {
                    alert( res.data.message );
                }
            }
        );
    } );

    /* ─── Видалення ─────────────────────────────────────────────────────── */

    $tbody.on( 'click.fbpa', '.fb-delete-btn', function ( e ) {
        // Зупиняємо спливання щоб інші обробники не перехопили подію
        e.stopImmediatePropagation();

        if ( ! window.confirm( fbPaObj.confirm ) ) {
            return;
        }

        const $btn = $( this ).css( 'opacity', '0.4' );

        $.post(
            fbPaObj.ajax_url,
            {
                action:   'fb_ajax_pa_delete',
                security: fbPaObj.nonce,
                id:       $btn.closest( 'tr' ).data( 'id' ),
            },
            function ( res ) {
                if ( res.success ) {
                    loadAccounts();
                } else {
                    $btn.css( 'opacity', '1' );
                    alert( res.data.message );
                }
            }
        );
    } );

} );
