<?php

namespace Salecto\Webshipper\Api\Carrier;

use Salecto\Shipping\Api\Carrier\CarrierInterface;

interface WebshipperInterface extends CarrierInterface
{
    const TYPE_NAME = 'webshipper';

    /**
     * @param string $country
     * @param string $method
     * @param string $postcode
     * @param string $shipping_address
     * @return \Salecto\Webshipper\Api\Data\ParcelShopInterface[]
     */
    public function getParcelShops($country, $method = '', $postcode = null, $shipping_address = null);
}
