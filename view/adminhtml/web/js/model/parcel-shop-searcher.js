define([
    'ko',
    'mage/storage',
    'jquery'
], function(ko, storage, $) {

    var currentRequest = null;

    return function(wexoShippingData, shippingCountryId) {
        if (currentRequest && currentRequest.abort) {
            currentRequest.abort();
        }

        $('body').trigger('processStart');
        let currentShippingMethod = $("#shipping-method-additional-load").data('current-shipping-method');
        let shippingAddress = this.source.shippingAddress.data;
        shippingAddress.countryId = shippingCountryId;
        return storage.get('/rest/V1/wexo-webshipper/get-parcel-shops?' + $.param({
            country: shippingCountryId,
            postcode: wexoShippingData.postcode,
            method: currentShippingMethod ? currentShippingMethod.shipping_method.method_code : '',
            shipping_address: JSON.stringify(shippingAddress),
            cache: true
        })).always(function() {
            currentRequest = null;
            $('body').trigger('processStop');
        });
    };
});
