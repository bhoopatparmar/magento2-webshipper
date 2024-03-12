<?php

namespace Salecto\Webshipper\Model\Carrier;

use Exception;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Area;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Asset\Repository;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Quote\Item;
use Salecto\Shipping\Api\Data\RateInterface;
use Salecto\Shipping\Api\Data\RateInterfaceFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Salecto\Shipping\Api\Carrier\MethodTypeHandlerInterface;
use Salecto\Shipping\Model\Carrier\AbstractCarrier;
use Salecto\Shipping\Model\Rate;
use Salecto\Shipping\Model\RateManagement;
use Salecto\Webshipper\Api\Carrier\WebshipperInterface;
use Salecto\Webshipper\Api\Data\ParcelShopInterface;
use Salecto\Webshipper\Model\Api;
use Salecto\Webshipper\Model\Config;

class Webshipper extends AbstractCarrier implements WebshipperInterface
{
    public $_code = self::TYPE_NAME;

    protected static $logos = [];

    /**
     * @var Api
     */
    private $webshipperApi;
    /**
     * @var RateFactory
     */
    private $rateFactory;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var Json
     */
    private $json;
    /**
     * @var Session
     */
    private $customerSession;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @param \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        RateManagement $rateManagement,
        MethodFactory $methodFactory,
        ResultFactory $resultFactory,
        Repository $assetRepository,
        StoreManagerInterface $storeManager,
        RateInterfaceFactory $rateFactory,
        Config $config,
        Json $json,
        Session $customerSession,
        CacheInterface $cache,
        \Magento\Checkout\Model\Session $checkoutSession,
        MethodTypeHandlerInterface $defaultMethodTypeHandler = null,
        array $methodTypeHandlers = [],
        array $data = []
    ) {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $rateManagement,
            $methodFactory,
            $resultFactory,
            $assetRepository,
            $storeManager,
            $defaultMethodTypeHandler,
            $methodTypeHandlers,
            $data
        );
        $this->cache = $cache;
        $this->checkoutSession = $checkoutSession;
        $this->rateFactory = $rateFactory;
        $this->config = $config;
        $this->json = $json;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return true;
    }

    /**
     * Type name that links to the Rate model
     *
     * @return string
     */
    public function getTypeName(): string
    {
        return static::TYPE_NAME;
    }

    /**
     * @inheirtDoc
     */
    public function getParcelShops($country, $method = '', $postcode = null, $shipping_address = null)
    {
        if (empty($postcode)) {
            return [];
        }

        if (!empty($shipping_address)) {
            try {
                $shipping_address = $this->json->unserialize($shipping_address);
            } catch (\InvalidArgumentException $e) {
                $shipping_address = null;
            }
        }

        try {
            $parcelShops = $this->getWebshipperApi()->getParcelShops($country, $method, $postcode, $shipping_address);
        } catch (Exception $e) {
            return [];
        }
        if (empty($parcelShops) || !$parcelShops) {
            return [];
        }

        return $parcelShops;
    }

    /**
     * @return Api
     */
    public function getWebshipperApi()
    {
        if ($this->webshipperApi === null) {
            $this->webshipperApi = ObjectManager::getInstance()->create(
                Api::class
            );
        }
        return $this->webshipperApi;
    }

    public function getImageUrl(ShippingMethodInterface $shippingMethod, $rate, $typeHandler)
    {
        if (isset(static::$logos[$shippingMethod->getMethodCode()])) {
            if ($this->config->showCarrierLogo()) {
                return static::$logos[$shippingMethod->getMethodCode()];
            } else {
                return '';
            }
        }
        return $this->assetRepository->createAsset('Salecto_Webshipper::images/webshipper.png', [
            'area' => Area::AREA_FRONTEND
        ])->getUrl();
    }

    public function collectRates(RateRequest $request)
    {
        $this->_logger->debug('Webshipper collectRates');
        $result = parent::collectRates($request);

        $cacheKeyData = $request->getData();
        unset($cacheKeyData['all_items']);
        unset($cacheKeyData['base_currency']);
        unset($cacheKeyData['package_currency']);
        unset($cacheKeyData['limit_carrier']);
        unset($cacheKeyData['condition_name']);
        unset($cacheKeyData['dest_region_code']);
        $cacheKeyData['webshipper_order_channel_id'] = $this->config->getOrderChannelId();
        foreach ($request->getAllItems() as $item) {
            $cacheKeyData['items'][] = $item->getSku() . '-' . $item->getQty();
        }
        $cacheKey = hash('sha256', json_encode($cacheKeyData));

        $shippingRates = $this->cache->load($cacheKey);
        if (empty($shippingRates)) {
            $this->_logger->debug('Webshipper collectRates cache:miss');
            try {
                $shippingRates = $this->fetchWebshipperRates($request);
            } catch (\Exception $e) {
                $this->_logger->error('Webshipper rateQuotes Exception', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                return $result;
            }
            if (!isset($shippingRates['data']['attributes']['quotes'])) {
                return $result;
            }
            $this->cache->save(json_encode($shippingRates), $cacheKey, ['cms_block']);
        } else {
            $this->_logger->debug('Webshipper collectRates cache:hit');
            $shippingRates = json_decode($shippingRates, true);
        }

        foreach ($shippingRates['data']['attributes']['quotes'] as $shippingRate) {
            $rate = $this->createRateFromWebshipperRate($shippingRate);
            /** @var Method $method */
            $method = $this->methodFactory->create();
            $method->setData('carrier', $this->_code);
            $method->setData('carrier_title', 'Webshipper');
            $method->setData('method', $this->makeMethodCode($rate));
            $method->setData('method_title', $rate->getTitle());
            $method->setPrice(
                $request->getFreeShipping() && $rate->getAllowFree() ? 0 : $rate->getPrice()
            );
            $result->append($method);
        }
        return $result;
    }

    /**
     * @param RateInterface $rate
     * @return string
     */
    public function makeMethodCode(RateInterface $rate)
    {
        $dropPointIndicator = $rate->getMethodType() === 'parcelshop' ? '1' : '0';
        return "{$rate->getId()}_{$dropPointIndicator}";
    }

    /**
     * @param string $method
     * @return mixed
     */
    public function getMethodTypeByMethod(string $method)
    {
        $methodType = explode('_', $method)[1];
        return $methodType === '1' ? 'parcelshop' : 'address';
    }

    public function fetchWebshipperRates($request)
    {
        return $this->getWebshipperApi()->request(function ($client) use ($request) {
            $allItems = $request->getAllItems();
            $items = [];
            foreach ($allItems as $item) {
                $orderLine = [
                    'quantity' => $item->getQty(),
                    'description' => $item->getName(),
                    'sku' => $item->getSku(),
                    'additional_attributes' => $this->mapAdditionalAttributes($item)
                ];
                // $this->config->updateOrderLinesFromConfig($orderLine);
                $items[] = $orderLine;
            }
            $attributes = [
                "order_channel_id" => $this->config->getOrderChannelId(),
                "price" => $request->getPackageValue(),
                "weight" => $request->getPackageWeight(),
                "weight_unit" => $this->config->getWeightUnit() ?? 'g',
                "sender_address" => [
                    "zip" => $this->config->getStoreZip(),
                    "country_code" => $this->config->getStoreCountry()
                ],
                "delivery_address" => [
                    "zip" => $request->getDestPostcode(),
                    "city" => $request->getDestCity(),
                    "address_1" => $request->getDestStreet(),
                    "street" => $request->getDestStreet(),
                    "country_code" => $request->getDestCountryId(),
                ],
                "items" => $items,
                "additional_attributes" => [
                    'customer_group_id' => $this->customerSession->getCustomerGroupId()
                ]
            ];
            $attributes = $this->transformAttributesFromConfigMapping($attributes);
            $data = [
                "data" => [
                    "type" => "rate_quotes",
                    "attributes" => $attributes
                ]
            ];
            $this->_logger->debug(
                'Webshipper rate_quotes Preflight',
                [
                    'body' => $data
                ]
            );
            return $client->post(
                "/v2/rate_quotes",
                [
                    'json' => $data,
                    'headers' => [
                        'Accept' => 'application/vnd.api+json',
                        'Content-Type' => 'application/vnd.api+json'
                    ]
                ]
            );
        }, function ($response, $content) {
            $this->_logger->debug(
                'Webshipper rate_quotes response',
                [
                    'content' => $content
                ]
            );
            return $content;
        });
    }


    public function transformAttributesFromConfigMapping(&$attributes)
    {
        $this->config->updateAddressFromConfig($attributes, 'sender_address');
        $this->config->updateAddressFromConfig($attributes, 'delivery_address');
        $this->config->updateOrderFromConfig($attributes);
        return $attributes;
    }

    public function mapAdditionalAttributes(Item $item)
    {
        $product = $item->getProduct();
        $attributes = $this->config->getProductAttributes() ?? "";
        if (!is_array($attributes)) {
            $attributes = explode(',', (string) $attributes);
        }
        $attributes = array_filter($attributes);
        $data = [];
        foreach ($attributes as $attributeCode) {
            $data[$attributeCode] = $product->getData($attributeCode);
        }
        return $data;
    }

    public function createRateFromWebshipperRate($data)
    {
        $shippingRate = $data['shipping_rate'] ?? [];
        /** @var Rate $rate */
        $rate = $this->rateFactory->create();
        $methodType = $shippingRate['require_drop_point'] ? 'parcelshop' : 'address';
        $rate->setId($shippingRate['id']);
        $rate->setCarrierType($this->_code);
        $rate->setMethodType($methodType);
        $rate->setIsActive(true);
        $rate->setSortOrder(1);
        $rate->setTitle((string) $shippingRate['name']);
        $rate->setPrice((float) $data['price']);
        $rate->setAllowFree(true);
        static::$logos[$this->makeMethodCode($rate)] = $data['carrier_logo'];
        return $rate;
    }
}
