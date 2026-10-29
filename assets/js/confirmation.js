/* A return to the store is never proof of payment. Only refresh pending pages. */
( function () {
	'use strict';
	var notice = document.querySelector( '.finlinks-awaiting[data-finlinks-refresh="scheduled"]' );
	if ( ! notice ) {
		return;
	}
	var next = Number( notice.dataset.finlinksNext );
	var delay = Number( notice.dataset.finlinksDelay );
	if ( ! Number.isInteger( next ) || next < 1 || ! Number.isFinite( delay ) || delay <= 0 ) {
		return;
	}
	window.setTimeout( function () {
		try {
			var url = new URL( window.location.href );
			url.searchParams.set( 'finlinks_wait', String( next ) );
			window.location.replace( url.toString() );
		} catch ( error ) {}
	}, delay );
} )();
