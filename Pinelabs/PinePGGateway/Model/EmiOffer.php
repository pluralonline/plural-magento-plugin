<?php

namespace Pinelabs\PinePGGateway\Model;

use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\Session\Config;
/**
 * Fetch EMI Offers
 */
class EmiOffer extends \Magento\Payment\Model\Method\AbstractMethod
{

    /**
     * Payment code
     *
     * @var string
     */

     /**
     * Payment code
     *
     * @var string
     */
	const PAYMENT_PINE_PG_CODE = 'pinepgpaymentmethod';
    protected $_code = self::PAYMENT_PINE_PG_CODE;
    protected $_isOffline = true;
	private $checkoutSession;
	protected  $logger;
	protected $orderRepository;
	protected $orderHistoryFactory;

    /**
     * 
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Pinelabs\PinePGGateway\Helper\PinePG $helper,
		\Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Checkout\Model\Cart $cart,
		\Magento\Directory\Model\Country $countryHelper,
		\Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory
    ) {
        $this->helper = $helper;
        $this->httpClientFactory = $httpClientFactory;
        $this->checkoutSession = $checkoutSession;
        $this->cart = $cart;
		// $this->_countryHelper = \Magento\Framework\App\ObjectManager::getInstance()->get('\Magento\Directory\Model\Country');
		$this->orderRepository = $orderRepository;
		$this->_countryHelper = $countryHelper;
		$this->orderHistoryFactory = $orderHistoryFactory;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );

        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);

    }


    public function emiOfferCalculator($request) {
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__. ' | '. json_encode($request));
        $res = $this->EmiOffer($request);
        return $res;
    }

    public function EmiOffer($params)
    {   
        $curl = curl_init();

        $env = $this->getConfigData('PayEnvironment'); 

        if ($env === 'LIVE') {
            $apiUrl = "https://pinepg.in/api/v2/emi/calculator";
        }
        $apiUrl = "https://uat.pinepg.in/api/v2/emi/calculator";

        $reqbody = $this->buildReq($params['productDetails']);

        curl_setopt_array($curl, array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($reqbody),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        $response = json_decode($response);

        return $response;
    }

    private function buildReq($product_details){
        $totalAmount = 0;
        $productDetails = array();
        foreach($product_details as $val){
            $totalAmount += (int)$val['productAmount'];
            array_push($productDetails,array("product_code"=> $val['productCode'], "product_amount"=> $val['productAmount']));
            $this->logger->info(__LINE__ . ' | '.__FUNCTION__. ' | Product Details:' . json_encode($product_details));
        }
        $reqBody = array(
                "merchant_data" => array(
                    "merchant_id" => $this->getConfigData("MerchantId"),
                    "merchant_access_code" => $this->getConfigData("MerchantAccessCode")
                ),
                "payment_data" => array(
                    "amount_in_paisa" => $totalAmount
                ),
                "product_details" => $productDetails
            );

        $this->logger->info(__LINE__ . ' | '.__FUNCTION__. ' | Product Details:' . json_encode($reqBody));

        return $reqBody;
    }
}