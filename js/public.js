(function($) {
    $(document).ready(function() {
        // Make My Account > Orders invoice download link open in new window
        document.querySelectorAll( "a.laskuhari-download-order-invoice" ).forEach( link => {
            link.setAttribute( "target", "_blank" );
            link.setAttribute( "rel", "noopener noreferrer" );
        } );
    });
})(jQuery);

if( laskuhariInfo.cron_needs_to_run === "yes" ) {
    fetch( laskuhariInfo.cron_url )
    .then( response => {
        if( ! response.ok) {
            console.error( "Laskuhari: Failed to trigger WP-Cron." );
        }
    } )
    .catch( error  => {
        console.error( "Laskuhari: Error triggering WP-Cron:", error );
    } );
}
