jQuery( document ).ready( function ( $ ) {
    $( '#the-list' ).on( 'click', 'a.editinline', function () {
        var tag_id = $( this ).parents( 'tr' ).attr( 'id' ),
            scope  = '#' + tag_id,
            order  = $( 'td.order', scope ).text();

        $( ':input[name="order"]', '.inline-edit-row' ).val( order );
    } );
} );
