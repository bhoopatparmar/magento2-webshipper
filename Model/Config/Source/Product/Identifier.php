<?php
namespace Salecto\Webshipper\Model\Config\Source\Product;

class Identifier extends \Salecto\Webshipper\Model\Config\Source\ProductAttributes
{
    public function getDefaultOption()
    {
        return [
            'value' => null,
            'label' => __('-- Use Default (sku) --')
        ];
    }
}
