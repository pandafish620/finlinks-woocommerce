/* FinLinks connection controls. Loaded only on the gateway settings page. */
( function () {
	'use strict';
	document.querySelectorAll( '.finlinks-action, .finlinks-connect' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var connect = button.classList.contains( 'finlinks-connect' );
			var nonce = document.getElementById( connect ? '_finlinks_connect_nonce' : '_finlinks_nonce' );
			if ( ! nonce || ! button.dataset.postUrl ) {
				return;
			}
			var form = document.createElement( 'form' );
			form.method = 'post';
			form.action = button.dataset.postUrl;
			var fields = {
				action: connect ? 'finlinks_wc_connect' : 'finlinks_wc_connection',
				_wpnonce: nonce.value,
				finlinks_do: button.dataset.do
			};
			Object.keys( fields ).forEach( function ( key ) {
				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = key;
				input.value = fields[ key ];
				form.appendChild( input );
			} );
			document.body.appendChild( form );
			form.submit();
		} );
	} );
} )();
