jQuery( document ).ready( function ( $ ) {
    const $tbody = $( '#fb-houses-tbody' );

    function load_houses() {
        $tbody.css( 'opacity', '0.5' );
        $.post(fbHousesObj.ajax_url, {
            action: 'fb_ajax_load_houses',
            security: fbHousesObj.nonce,
            family_id: $( '#fb-filter-family' ).val(),
            type_id: $( '#fb-filter-type' ).val(),
        }, function ( res ) {
            $tbody.css( 'opacity', '1' );
            if ( res.success ) $tbody.html( res.data.html );
        });
    }

    load_houses();

    $( document ).on( 'change', '#fb-filter-family, #fb-filter-type', load_houses );

    $( '#fb-add-house-form' ).on( 'submit', function ( e ) {
        e.preventDefault();
        const $btn = $( this ).find( '.fb-btn-primary' );
        $btn.prop( 'disabled', true );
        $.post(fbHousesObj.ajax_url, $( this ).serialize() + '&action=fb_ajax_add_house&security=' + fbHousesObj.nonce, function ( res ) {
            $btn.prop( 'disabled', false );
            if ( res.success ) {
                $( '#fb-add-house-form' )[ 0 ].reset();
                load_houses();
            } else alert( res.data.message );
        });
    });

    // Delegated events for dynamic content
    $tbody.on( 'click', '.fb-edit-btn', function () {
        const $tr = $( this ).closest( 'tr' );
        $tr.find( '.fb-view-mode, .fb-edit-btn' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-mode, .fb-save-btn' ).removeClass( 'hidden' );
    });

    $tbody.on( 'click', '.fb-save-btn', function () {
        const $tr = $( this ).closest( 'tr' );
        $.post(fbHousesObj.ajax_url, {
            action: 'fb_ajax_edit_house',
            security: fbHousesObj.nonce,
            id: $tr.data( 'id' ),
            type_id: $tr.find( '.fb-edit-type' ).val(),
            city: $tr.find( '.fb-edit-city' ).val(),
            street: $tr.find( '.fb-edit-street' ).val(),
            number: $tr.find( '.fb-edit-num' ).val(),
            apt: $tr.find( '.fb-edit-apt' ).val(),
        }, function ( res ) {
            if ( res.success ) load_houses();
        });
    });

    $tbody.on( 'click', '.fb-delete-btn', function () {
        if ( window.confirm( fbHousesObj.confirm ) ) {
            $.post(fbHousesObj.ajax_url, {
                action: 'fb_ajax_delete_house',
                security: fbHousesObj.nonce,
                id: $( this ).closest( 'tr' ).data( 'id' ),
            }, load_houses );
        }
    });
});