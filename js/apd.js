jQuery( document ).ready( function( $ ) {
    // Add the count.
    $( 'li.all a' ).removeClass( 'current' ).find( 'span.count' ).html( '(' + dj_apd.count + ')' );

    $( '.wp-list-table.plugins tr' ).each( function() {
        var is_active = $( this ).find( 'a.dj-apd-deactivate' );

        if ( is_active.length ) {
            $( this ).removeClass( 'inactive' ).addClass( 'active' );
            $( this ).find( 'div.plugin-version-author-uri' ).removeClass( 'inactive' ).addClass( 'active' );
        }
    });
});
