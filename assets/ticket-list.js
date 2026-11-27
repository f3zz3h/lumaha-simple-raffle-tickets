/* WooCommerce → Raffle tickets list: the Print button. */
document.addEventListener( 'click', function ( e ) {
	if ( e.target.closest && e.target.closest( '.lsrt-print' ) ) {
		window.print();
	}
} );
