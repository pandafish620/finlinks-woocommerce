/**
 * Registers "Pay with USDC" in the Cart/Checkout blocks. No build step needed.
 */
( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	if ( ! registry || ! settingsApi || ! window.wp || ! window.wp.element ) {
		return;
	}
	var el = window.wp.element.createElement;
	var __ = window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : function ( s ) { return s; };
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( s ) { return s; };
	var data = settingsApi.getSetting( 'finlinks_data', {} );
	var title = decode( data.title || __( 'Pay with USDC', 'finlinks-stablecoin-payments' ) );
	var description = decode( data.description || '' );
	var currencies = data.currencies || [ 'USD' ];
	var minMinor = data.minMinor || 100;
	var maxMinor = data.maxMinor || 1000000;

	var Content = function () {
		return description ? el( 'p', null, description ) : null;
	};
	var Label = function ( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el( PaymentMethodLabel, { text: title } ) : el( 'span', null, title );
	};

	registry.registerPaymentMethod( {
		name: 'finlinks',
		label: el( Label ),
		ariaLabel: title,
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function ( args ) {
			if ( data.paused ) {
				return false;
			}
			var totals = args && args.cartTotals;
			if ( ! totals ) {
				return true;
			}
			if ( currencies.indexOf( String( totals.currency_code || '' ).toUpperCase() ) === -1 ) {
				return false;
			}
			var unit = typeof totals.currency_minor_unit === 'number' ? totals.currency_minor_unit : 2;
			var total = parseInt( totals.total_price, 10 );
			if ( isNaN( total ) ) {
				return true;
			}
			// Convert to cents.
			var cents = unit === 2 ? total : Math.round( total * Math.pow( 10, 2 - unit ) );
			return cents >= minMinor && cents <= maxMinor;
		},
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
