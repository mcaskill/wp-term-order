/**
 * @summary Binds to the document ready event.
 *
 * @since 0.1.0
 *
 * @param {jQuery} $ The jQuery object.
 */
jQuery( document ).ready( function ( $ ) {
    /**
     * @summary Adds event that updates the "order" input field when opening quick-edit.
     *
     * @listens click
     *
     * @param {Event} event The event object.
     * @returns {void}
     */
    $( '#the-list' ).on( 'click', 'a.editinline', function () {
        var tag_id = $( this ).parents( 'tr' ).attr( 'id' ),
            scope  = '#' + tag_id,
            order  = $( 'td.order', scope ).text();

        $( ':input[name="order"]', '.inline-edit-row' ).val( order );
    } );
} );
