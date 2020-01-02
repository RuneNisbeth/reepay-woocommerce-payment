/* global wc_checkout_params */

// Preload ModalCheckout
window.rp = new Reepay.ModalCheckout();

jQuery( function( $ ) {
    'use strict';

    // wc_checkout_params is required to continue, ensure the object exists
    if ( typeof wc_checkout_params === 'undefined' ) {
        return false;
    }

    $( document ).ajaxComplete( function ( event, xhr, settings ) {
        if ( settings.url === wc_checkout_params.checkout_url ) {
            const data = xhr.responseText;

            // Parse
            try {
                const result = $.parseJSON( data );

                // Check is response from payment gateway
                if ( ! result.hasOwnProperty( 'is_reepay_checkout' ) ) {
                    return false;
                }

                wc_reepay.buildModalCheckout( result.reepay.id, result.accept_url, result.cancel_url );
            } catch ( e ) {
                return false;
            }
        }
    } );

    $( document ).ready(function () {
        if ( window.location.hash.indexOf( '#!reepay-pay' ) > -1 ) {
            const url = document.location.hash.replace( '#!reepay-pay','' ),
                params = new URLSearchParams( url );

            let rid = params.get( 'rid' ),
                accept_url = params.get( 'accept_url' ),
                cancel_url = params.get( 'cancel_url' );

            window.setTimeout( function () {
                wc_reepay.buildModalCheckout( rid, accept_url, cancel_url );
                history.pushState( '', document.title, window.location.pathname );
            }, 1000 );
        }
    });
});

wc_reepay = {
    /**
     * Build Modal Checkout
     *
     * @param reepay_id
     * @param accept_url
     * @param cancel_url
     */
    buildModalCheckout: function ( reepay_id, accept_url, cancel_url ) {
        if ( WC_Gateway_Reepay_Checkout.payment_type === 'OVERLAY' ) {
            // Show modal
            window.rp.show( reepay_id );
            //rp = new Reepay.ModalCheckout( reepay_id );
        } else {
            window.rp = new Reepay.WindowCheckout( reepay_id );
        }

        window.rp.addEventHandler( Reepay.Event.Accept, function( data ) {
            console.log( 'Accept', data );

            let redirect_url = accept_url;
            for ( let prop in data ) {
                redirect_url = wc_reepay.setUrlParameter( redirect_url, prop, data[prop] );
            }

            window.location.href = redirect_url;
        } );

        window.rp.addEventHandler( Reepay.Event.Cancel, function( data ) {
            console.log( 'Cancel', data );
            window.location.href = cancel_url;
        } );

        window.rp.addEventHandler( Reepay.Event.Close, function( data ) {
            console.log( 'Close', data );
        } );

        window.rp.addEventHandler( Reepay.Event.Error, function( data ) {
            console.log( 'Error', data );
            window.location.href = cancel_url;
        } );
    },

    /**
     * Add parameter for Url
     *
     * @param url
     * @param key
     * @param value
     * @return {string}
     */
    setUrlParameter: function ( url, key, value ) {
        var baseUrl = url.split('?')[0],
            urlQueryString = '?' + url.split('?')[1],
            newParam = key + '=' + value,
            params = '?' + newParam;

        // If the "search" string exists, then build params from it
        if (urlQueryString) {
            var updateRegex = new RegExp('([\?&])' + key + '[^&]*');
            var removeRegex = new RegExp('([\?&])' + key + '=[^&;]+[&;]?');

            if (typeof value === 'undefined' || value === null || value === '') { // Remove param if value is empty
                params = urlQueryString.replace(removeRegex, "$1");
                params = params.replace(/[&;]$/, "");

            } else if (urlQueryString.match(updateRegex) !== null) { // If param exists already, update it
                params = urlQueryString.replace(updateRegex, "$1" + newParam);

            } else { // Otherwise, add it to end of query string
                params = urlQueryString + '&' + newParam;
            }
        }

        // no parameter was set so we don't need the question mark
        params = params === '?' ? '' : params;

        return baseUrl + params;
    }
};

