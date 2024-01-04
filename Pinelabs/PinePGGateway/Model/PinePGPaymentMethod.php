<?php

namespace Pinelabs\PinePGGateway\Model;

use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\Session\Config;
use Unilever\CcavenueSubId\Model\ResourceModel\CcavenueSubId\Collection;
use Unilever\Pinelabs\Model\ResourceModel\Merchant\Collection as MerchantCollection;
/**
 * Pay In Store payment method model
 */
class PinePGPaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{

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
     * @var Unilever\CcavenueSubId\Model\ResourceModel\CcavenueSubId\Collection
     */
    protected $collection;   
	 /**
     * @var Unilever\Pinelabs\Model\ResourceModel\Merchant\Collection
     */
    protected $merchantCollection;

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
		\Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory,		
        Collection $collection,        
        MerchantCollection $merchantCollection
    ) {
        $this->helper = $helper;
        $this->httpClientFactory = $httpClientFactory;
        $this->checkoutSession = $checkoutSession;
        $this->cart = $cart;
		// $this->_countryHelper = \Magento\Framework\App\ObjectManager::getInstance()->get('\Magento\Directory\Model\Country');
		$this->orderRepository = $orderRepository;
		$this->_countryHelper = $countryHelper;
		$this->orderHistoryFactory = $orderHistoryFactory;
		$this->collection = $collection;        
        $this->merchantCollection = $merchantCollection;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );

    }
	
	public function getRedirectUrl() {
        return $this->helper->getUrl($this->getConfigData('redirect_url'));
    }

    public function getReturnUrl() {
        return $this->helper->getUrl($this->getConfigData('return_url'));
    }

    public function getCancelUrl() {
        return $this->helper->getUrl($this->getConfigData('cancel_url'));
    }

    /**
     * Return url according to environment
     * @return string
     */
    public function getCgiUrl() {
        $env = $this->getConfigData('PayEnvironment');
        if ($env === 'LIVE') {
            return $this->getConfigData('production_url');
        }
        return $this->getConfigData('sandbox_url');
    }
	  public function Hex2String($hex){
            $string='';
            for ($i=0; $i < strlen($hex)-1; $i+=2){
                $string .= chr(hexdec($hex[$i].$hex[$i+1]));
            }
            return $string;
        }
		
    public function buildCheckoutRequest() {

		//$writer = new \Zend_Log_Writer_Stream('/var/log/PinePG/'.date("Y-m-d").'.log');
		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);

		$TXN_TYPE_PURCHASE='1';
		$NAVIGATION_REDIRECT_MODE='2';
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__);
		
        $order = $this->checkoutSession->getLastRealOrder();
        $billing_address = $order->getBillingAddress();
		$shipping_address = $order->getShippingAddress();
		$merchantData = $this->getPinelabMerchantData($order);
		$merchantAccessCode = '';
		$merchantSecretKey	= '';
		if(isset($merchantData['access_code']) && !empty($merchantData['access_code'])
		&& isset($merchantData['secret_key']) && !empty($merchantData['secret_key'])){
			$merchantAccessCode = $merchantData['access_code'];
			$merchantSecretKey = $merchantData['secret_key'];
			$merchantId = $merchantData['merchant_id'];
		}else{
			die('Access code not found for postcode.');
			return false;
		}		
		$params = array();
		//set billing address
		$params['ppc_CustomerFirstName'] 	= $billing_address->getData('firstname');
		$params['ppc_CustomerLastName'] 	= $billing_address->getData('lastname');
		$params['ppc_CustomerCountry'] 		= $billing_address->getData('country_id');
		$countryObj 						= $this->_countryHelper->loadByCode($params['ppc_CustomerCountry']);
		$params['ppc_CustomerCountry'] 		= $countryObj->getName();
		$params['ppc_CustomerState'] 		= $billing_address->getData('region');
		$params['ppc_CustomerCity'] 		= $billing_address->getData('city');
		
		$params['ppc_CustomerAddressPIN'] 	= $billing_address->getData('postcode');
		$params['ppc_CustomerEmail'] 		= $billing_address->getData('email');
		$params['ppc_CustomerMobile'] 		= $billing_address->getData('telephone');

		//set shipping address
		$params['ppc_ShippingFirstName'] 	 = $shipping_address->getData('firstname');
		$params['ppc_ShippingLastName'] 	 = $shipping_address->getData('lastname');
		
		$params['ppc_ShippingCity'] 		 = $shipping_address->getData('city');
		$params['ppc_ShippingState'] 		 = $shipping_address->getData('region');
		
		$params['ppc_ShippingCountry'] 	 	 = $shipping_address->getData('country_id');
		$countryObj 						 = $this->_countryHelper->loadByCode($params['ppc_ShippingCountry']);
		$params['ppc_ShippingCountry'] 		 = $countryObj->getName();
		
		$params['ppc_ShippingZipCode'] 	 	 = $shipping_address->getData('postcode');
		$params['ppc_ShippingPhoneNumer']  	 = $shipping_address->getData('telephone');

		$params['ppc_UdfField1'] 			 = 'Magento_2.4.5-p1';
        $params["ppc_MerchantAccessCode"] 	 = $merchantAccessCode;
        $secret_key 						 = $this->Hex2String($merchantSecretKey);
        $params["ppc_PayModeOnLandingPage"]  = $this->getConfigData("MerchantPaymentMode");
        $params["ppc_Carttype"] 			 = $this->getConfigData("cart");
        $params["ppc_LPC_SEQ"] 				 = '1';
		$params["ppc_Amount"] 				 = round($order->getBaseGrandTotal(), 2)*100;
        $params["ppc_NavigationMode"] 		 = $NAVIGATION_REDIRECT_MODE;
		$params["ppc_MerchantReturnURL"] 	 = $this->getReturnUrl();
        $params["ppc_TransactionType"] 		 = $TXN_TYPE_PURCHASE;
	    $params["ppc_UniqueMerchantTxnID"] 	 = uniqid().'_'.$this->checkoutSession->getLastRealOrderId(); 
	    $params["ppc_MerchantID"] 			 = $merchantId;

	    $product_id ='';
	    $totalOrders = 0;
	    $IsProductQuantityInCartMoreThanOne=false;
		$quan=-1;
		 
		$params['ppc_MerchantProductInfo'] = '';
		$params['ppc_Product_Code']  ='';
		$product_info_data = [];
		$i = 0;
		foreach ($order->getAllVisibleItems()  as $product) {
			$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Get Product code of item and check whether there is more than one item present in cart or not');
			$totalOrders =$totalOrders+1;
			$product_id = $product->getSku();
			$quan=$product->getQty();
					   
			if($totalOrders == 1){
			    $params['ppc_MerchantProductInfo'] = $product->getName();
			}else{
				$params['ppc_MerchantProductInfo'] = $params['ppc_MerchantProductInfo'].'|'.$product->getName();
			}
						   
			if($product->getQtyOrdered()>1)
			{
				$IsProductQuantityInCartMoreThanOne=true; 
			} 
			
			$product_details = new \stdClass();

			$quantity = explode('.',$product->getQtyOrdered())[0];
			$product_details->product_code = $product->getSku();
			$product_details->product_amount = (floatval($product->getRowTotalInclTax()) * 100)*$quantity;
			$product_info_data[$i] = $product_details;
			$this->logger->info('quantity : '.$product->getDiscountAmount().' , discounts: '.$quantity );	
			$this->logger->info('Price with Tax: '. abs($product->getRowTotalInclTax()));
			$i++;
        }
		
		$this->logger->info('price:'.$product->getPrice() );	
		$params = $this->checkCartType($product_info_data,$params,$order);

        if ($totalOrders == 1 && $IsProductQuantityInCartMoreThanOne==false )
        {
		    $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Item count is one and Product code is:'.$product_id );
            $params['ppc_Product_Code']  = $product_id;
		}
        else
        {
			$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Item count is more than one ' );
			$params['ppc_Product_Code']  ='';
        }
					
	  	ksort($params);
		$strString="";
	 
		 // convert dictionary key and value to a single string variable
		foreach ($params as $key => $val) {
			$strString.=$key."=".$val."&";
		}
	    $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Request paramter is: '.$strString );
		 // trim last character from string
		$strString = substr($strString, 0, -1);
		$this->logger->info('Merchant data secret_key buildCheckoutRequest: ' . $secret_key);
		$code = strtoupper(hash_hmac('sha256', $strString, $secret_key));
        $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Hash of request is '.$code );
        $params['ppc_DIA_SECRET_TYPE'] = 'SHA256';
	    $params['ppc_DIA_SECRET'] = $code;	
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Parameters: '. json_encode($params));
        return $params;
    }

	public function buildCheckoutRequestByOrderId($meg_rder) {

		//$writer = new \Zend_Log_Writer_Stream('/var/log/PinePG/'.date("Y-m-d").'.log');
		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);

		$TXN_TYPE_PURCHASE='1';
		$NAVIGATION_REDIRECT_MODE='2';
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__);
         

		$order = $meg_rder;
        $billing_address = $order->getBillingAddress();
		$shipping_address = $order->getShippingAddress();
		$this->logger->info(__LINE__ . ' | '.json_encode($order->getIncrementId()));
		$merchantData = $this->getPinelabMerchantData($order);	
		$merchantAccessCode = '';
		$merchantSecretKey	= '';
		if(isset($merchantData['access_code']) && !empty($merchantData['access_code'])
		&& isset($merchantData['secret_key']) && !empty($merchantData['secret_key'])){
			$merchantAccessCode = $merchantData['access_code'];
			$merchantSecretKey = $merchantData['secret_key'];
			$merchantId = $merchantData['merchant_id'];
		}else{
			die('Access code not found for postcode.');
			return false;
		}	
		$params = array();
		//set billing address
		$params['ppc_CustomerFirstName'] 	= $billing_address->getData('firstname');
		$params['ppc_CustomerLastName'] 	= $billing_address->getData('lastname');
		$params['ppc_CustomerCountry'] 		= $billing_address->getData('country_id');
		$countryObj 						= $this->_countryHelper->loadByCode($params['ppc_CustomerCountry']);
		$params['ppc_CustomerCountry'] 		= $countryObj->getName();
		$params['ppc_CustomerState'] 		= $billing_address->getData('region');
		$params['ppc_CustomerCity'] 		= $billing_address->getData('city');
		
		$params['ppc_CustomerAddressPIN'] 	= $billing_address->getData('postcode');
		$params['ppc_CustomerEmail'] 		= $billing_address->getData('email');
		$params['ppc_CustomerMobile'] 		= $billing_address->getData('telephone');

		//set shipping address
		$params['ppc_ShippingFirstName'] 	 = $shipping_address->getData('firstname');
		$params['ppc_ShippingLastName'] 	 = $shipping_address->getData('lastname');
		
		$params['ppc_ShippingCity'] 		 = $shipping_address->getData('city');
		$params['ppc_ShippingState'] 		 = $shipping_address->getData('region');
		
		$params['ppc_ShippingCountry'] 	 	 = $shipping_address->getData('country_id');
		$countryObj 						 = $this->_countryHelper->loadByCode($params['ppc_ShippingCountry']);
		$params['ppc_ShippingCountry'] 		 = $countryObj->getName();
		
		$params['ppc_ShippingZipCode'] 	 	 = $shipping_address->getData('postcode');
		$params['ppc_ShippingPhoneNumer']  	 = $shipping_address->getData('telephone');

		$params['ppc_UdfField1'] 			 = 'Magento_2.4.5-p1';
		$params['ppc_UdfField2']			 = $order->getIncrementId();
        $params["ppc_MerchantAccessCode"] 	 = $merchantAccessCode;
        $secret_key 						 = $this->Hex2String($merchantSecretKey);
        $params["ppc_PayModeOnLandingPage"]  = $this->getConfigData("MerchantPaymentMode");
        $params["ppc_Carttype"] 			 = $this->getConfigData("cart");
        $params["ppc_LPC_SEQ"] 				 = '1';
		$params["ppc_Amount"] 				 = round($order->getBaseGrandTotal(), 2)*100;
        $params["ppc_NavigationMode"] 		 = $NAVIGATION_REDIRECT_MODE;
		$params["ppc_MerchantReturnURL"] 	 = $this->getReturnUrl();
        $params["ppc_TransactionType"] 		 = $TXN_TYPE_PURCHASE;
	    $params["ppc_UniqueMerchantTxnID"] 	 = uniqid().'_'.$order->getIncrementId(); 
	    $params["ppc_MerchantID"] 			 = $merchantId;
	    $product_id ='';
	    $totalOrders = 0;
	    $IsProductQuantityInCartMoreThanOne=false;
		$quan=-1;
		 
		$params['ppc_MerchantProductInfo'] = '';
		$params['ppc_Product_Code']  ='';
		$product_info_data = [];
		$i = 0;
		foreach ($order->getAllVisibleItems()  as $product) {
			$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Get Product code of item and check whether there is more than one item present in cart or not');
			$totalOrders =$totalOrders+1;
			$product_id = $product->getSku();
			$quan=$product->getQty();
					   
			if($totalOrders == 1){
			    $params['ppc_MerchantProductInfo'] = $product->getName();
			}else{
				$params['ppc_MerchantProductInfo'] = $params['ppc_MerchantProductInfo'].'|'.$product->getName();
			}
						   
			if($product->getQtyOrdered()>1)
			{
				$IsProductQuantityInCartMoreThanOne=true; 
			} 
			
			$product_details = new \stdClass();

			$quantity = explode('.',$product->getQtyOrdered())[0];
			$product_details->product_code = $product->getSku();
			$product_details->product_amount = (floatval($product->getRowTotalInclTax()) * 100)*$quantity;
			$product_info_data[$i] = $product_details;
			$this->logger->info('quantity : '.$product->getDiscountAmount().' , discounts: '.$quantity );	
			$this->logger->info('Price with Tax: '. abs($product->getRowTotalInclTax()));
			$i++;
        }
		
		$this->logger->info('price without tax: '.$product->getPrice() );	
		$params = $this->checkCartType($product_info_data,$params,$order);

        if ($totalOrders == 1 && $IsProductQuantityInCartMoreThanOne==false )
        {
		    $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Item count is one and Product code is:'.$product_id );
            $params['ppc_Product_Code']  = $product_id;
		}
        else
        {
			$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Item count is more than one ' );
			$params['ppc_Product_Code']  ='';
        }
					
	  	ksort($params);
		$strString="";
	 
		 // convert dictionary key and value to a single string variable
		foreach ($params as $key => $val) {
			$strString.=$key."=".$val."&";
		}
	    $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Request paramter is: '.$strString );
		 // trim last character from string
		$strString = substr($strString, 0, -1);
		$this->logger->info('Merchant data secret_key buildCheckoutRequestByOrderId: ' . $secret_key);
		$code = strtoupper(hash_hmac('sha256', $strString, $secret_key));
        $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Hash of request is '.$code );
        $params['ppc_DIA_SECRET_TYPE'] = 'SHA256';
	    $params['ppc_DIA_SECRET'] = $code;	
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Parameters: '. json_encode($params));
        return $params;
    }
	 
	  //validate response
    public function validateResponse($returnParams) {

		//$writer = new \Zend_Log_Writer_Stream('/var/log/PinePG/'.date("Y-m-d").'.log');
		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__);
		
		$order_id=0;
		if (isset($returnParams['ppc_UniqueMerchantTxnID'])) 
		{
		  	$merchantTxnID = trim(($returnParams['ppc_UniqueMerchantTxnID']));		  
        	$order_id = explode('_', $merchantTxnID);
            $order_id = substr($order_id[1],-9); //get order_id part
		  	$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' validate response for order id:'.$order_id);
		} 
		else 
		{
		 $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Received order id is null');
		  die('Illegal Access ORDER ID NOR PASSED');
		}
		
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
		$order_info = $objectManager->create('Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($order_id);        
		
	    if ($order_info) 
		{
			if ( !empty($returnParams) ) 
			{
				
			$DiaSecretType='';
			$DiaSecret='';
			if (isset($returnParams['ppc_DIA_SECRET_TYPE'])) {
				$DiaSecretType = $returnParams['ppc_DIA_SECRET_TYPE'];
			} 
			if (isset($returnParams['ppc_DIA_SECRET'])) {
				$DiaSecret = $returnParams['ppc_DIA_SECRET'];
			} 
			
			$strString="";
			ksort($returnParams);
			foreach ($returnParams as $key => $value)
			{
				$strString.=$key."=".$value."&";
			}

			$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' [Order ID]:' . $order_id.' Received parameters : '.$strString);
			unset($returnParams['ppc_DIA_SECRET_TYPE']);
			unset($returnParams['ppc_DIA_SECRET']);
			$strString="";
			$merchantSecretKey = "";
			$merchantData = $this->getPinelabMerchantData($order_info);			
			if(isset($merchantData['secret_key']) && !empty($merchantData['secret_key'])){			
				$merchantSecretKey = $merchantData['secret_key'];				
			}
			$this->logger->info('Merchant data secret_key validateResponse: ' . $merchantSecretKey);
			$secret_key   =   $this->Hex2String($merchantSecretKey);
			ksort($returnParams);
			foreach ($returnParams as $key => $value)
			{
				$strString.=$key."=".$value."&";
			}			
			$strString = substr($strString, 0, -1);
			//$this->logger->info('Merchant data secret_key validateResponse: ' . $secret_key);
			$SecretHashCode = strtoupper(hash_hmac('sha256', $strString, $secret_key));
		
			if("" == trim($DiaSecret))
			{	
				$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' [Order ID]:' . $order_id.' Transaction failed.Pine PG Secure hash is empty');
				return false;
			}   
			else
			{
				if(trim($DiaSecret)==trim($SecretHashCode))
				{	
					if ($returnParams['ppc_PinePGTxnStatus'] == '4' && $returnParams['ppc_TxnResponseCode'] == '1') 
					{		
						$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' [Order ID]:' . $order_id.' Payment Transation is successful');
						return true;
					}
					else if($returnParams['ppc_PinePGTxnStatus'] == '-10')
					{
						$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' [Order ID]:' . $order_id.' Transaction cancelled by user ');
						return false;
					}
					else if($returnParams['ppc_PinePGTxnStatus'] == '-6')
					{ 
						$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' [Order ID]:' . $order_id.' Transaction rejected by system ');
						return false;
					}
					else
					{
						$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' [Order ID]:' . $order_id.'  Transaction failed ');
						return false;
					}
				}
				else
				{
					$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' [Order ID]:' . $order_id.'  Transaction failed.Secure_Hash not matched with Pine PG Secure Hash');
					return false;
				}
			}
		}
		else
		{ 	    
				$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Post parameters received is empty');	
				die('Illegal Access POST REQUEST IS EMPTY');
				return false;
		}
		}
		else 
			{	
			 $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Received order id is null:');
			  die('Illegal Access ORDER ID NOR PASSED');
			}	
     return false;
    }


    public function postProcessing(\Magento\Sales\Model\Order $order, \Magento\Framework\DataObject $payment, $response) { 

		//$writer = new \Zend_Log_Writer_Stream('/var/log/PinePG/'.date("Y-m-d").'.log');
		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__ . ' Response: '.json_encode($response)); 

		$response =  json_decode(json_encode($response), true);
 
		$payment->setTransactionId($response['ppc_PinePGTransactionID']);
        $payment->setAdditionalInformation(['ppc_Amount_in_paise: '. $response['ppc_Amount']]);
		if (isset($response['ppc_Is_BrandEMITransaction']) ) 
		{
			if($response['ppc_Is_BrandEMITransaction']=="1")
			{
				 $payment->setAdditionalInformation(['ppc_Is_BrandEMITransaction: '. $response['ppc_Is_BrandEMITransaction']]);			
			}
		}
		if (isset($response['ppc_Is_BankEMITransaction']) ||isset($response['ppc_Is_BankEMITransaction'])) 
		{	
			if($response['ppc_Is_BankEMITransaction']=="1")
			{
				 $payment->setAdditionalInformation(['ppc_Is_BankEMITransaction: '. $response['ppc_Is_BankEMITransaction']]);			
			}
		}
		 
		if (isset($response['ppc_IssuerName']) ) 
		{
			$payment->setAdditionalInformation(['ppc_IssuerName: '. $response['ppc_IssuerName']]);
		}
		
		if (isset($response['ppc_EMIInterestRatePercent']) ) 
		{
			$payment->setAdditionalInformation(['ppc_EMIInterestRatePercent: '. $response['ppc_EMIInterestRatePercent']]);
		}
		if (isset($response['ppc_EMIAmountPayableEachMonth']) ) 
		{
			$payment->setAdditionalInformation(['ppc_EMIAmountPayableEachMonth: '. $response['ppc_EMIAmountPayableEachMonth']]);
		}
		
		if (isset($response['ppc_EMITotalDiscCashBackPercent']) ) 
		{
			$payment->setAdditionalInformation(['ppc_EMITotalDiscCashBackPercent: '. $response['ppc_EMITotalDiscCashBackPercent']]);
		}
	    if (isset($response['ppc_EMITotalDiscCashBackAmt']) ) 
		{
			 $payment->setAdditionalInformation(['ppc_EMITotalDiscCashBackAmt: '. $response['ppc_EMITotalDiscCashBackAmt']]);
		}
	   
		if (isset($response['ppc_EMITenureMonth']) ) 
		{
		  $payment->setAdditionalInformation(['ppc_EMITenureMonth: '. $response['ppc_EMITenureMonth']]);
		}
	    if (isset($response['ppc_EMICashBackType']) ) 
		{
			 $payment->setAdditionalInformation(['ppc_EMICashBackType: '. $response['ppc_EMICashBackType']]);
		}
	    if (isset($response['ppc_EMIAdditionalCashBack']) ) 
		{
			 $payment->setAdditionalInformation(['ppc_EMIAdditionalCashBack: '.$response['ppc_EMIAdditionalCashBack']]);
		}
				
        $payment->addTransaction("order");
        $payment->setIsTransactionClosed(0);
		$payment->setAmountPaid($response['ppc_Amount']); 
        $payment->place();
		$order->setTotalPaid($response['ppc_Amount']/100); 
        $order->setStatus('payment_received');
		// Add a comment to the order
		//$order->setStatus('processing');
		$order->addStatusHistoryComment('<b>UniqueMerchantTxnID </b>'.$response['ppc_UniqueMerchantTxnID'].', <b>Txn Id:</b> '. $response['ppc_PinePGTransactionID'].', <b>Txn DateTime:</b> '. $response['ppc_TransactionCompletionDateTime']. ', <b>Txn Status:</b> '. $response['ppc_TxnResponseMessage']);
        $order->save();
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Save the order after successful response from Pine PG for order id:'.$response['ppc_UniqueMerchantTxnID'].'and Pine PG Txn ID:'.$response['ppc_PinePGTransactionID'] );
    }

	private function checkCartType($product_info_data,$params,$order)
	{

		if($params['ppc_Carttype'] == 'MultiCart'){

			if($product_info_data){ 

				if($order->getDiscountAmount()){
					$discount_val = abs($order->getDiscountAmount());
					$this->logger->info(__LINE__ . ' | '.__FUNCTION__.'==='.$discount_val);

					$productTotalAmt_beforeDiscount = $params['ppc_Amount'] + ($discount_val*100);

					$product_info_data = $this->calculation_on_items($product_info_data,$productTotalAmt_beforeDiscount,($discount_val*100));	
				}

				$ppc_MultiCartProductDetails = base64_encode(json_encode($product_info_data));
			
				$params['ppc_MultiCartProductDetails'] = base64_encode(json_encode($product_info_data));
			}
			else 
			{
				$params['ppc_MultiCartProductDetails'] = '';
				unset($params['ppc_MultiCartProductDetails']);
			}

		}
		unset($params['ppc_Carttype']);
		return $params;		
	}

	private function calculation_on_items($items,$total_amt,$discount){ 

		//$writer = new \Zend_Log_Writer_Stream('/var/log/PinePG/'.date("Y-m-d").'.log');
		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);

		$this->logger->info('PineItems - '.json_encode($items).' Ordertotal-amount-before-discount - '.$total_amt. ' Discount - '. $discount);

		foreach($items as $key => $value){
				$single_item_percentage = ($items[$key]->product_amount/$total_amt) * $discount;
				$get_amt = $items[$key]->product_amount - $single_item_percentage;
				$items[$key]->product_amount = $get_amt;
			}
		return $items;
	}

	public function addCommentToOrder($orderId, $comment, $status="")
	{ 
		try {
			$order = $this->orderRepository->get($orderId);
			if ($order->canComment()) {
				$history = $this->orderHistoryFactory->create()
					->setStatus(!empty($status) ? $status : $order->getStatus()) // Update status when passing $comment parameter
					->setComment(
						__('%1', $comment)
					); // Set your comment

				$history->setIsVisibleOnFront(true);// Enable order comment visible on sales order details

				$order->addStatusHistory($history); // Add your comment to order
				$this->orderRepository->save($order);
			}
		} catch (NoSuchEntityException $exception) {
			$this->logger->error($exception->getMessage());
		}
		return true;
	}

	
    private function getPinelabMerchantData($order){

		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info(__LINE__.':'.__CLASS__.': PinePGPaymentMethod getPinelabMerchantData() ');


        $merchantData = ['merchant_id' => "", "access_code" => ""];
       // $order = $this->loadOrder($orderId);
        if($order && !empty($order->getData())){
            $data = $order->getData();
            $postcode = "";
            if ($order->getIsVirtual() || (is_null($order->getShippingAddress()))){
                $addressData = $order->getBillingAddress()->getData();
                $postcode = $addressData['postcode'];
            }else{
                $addressData = $order->getShippingAddress()->getData();
                $postcode = $addressData['postcode'];
            }            
            $billingData = $order->getBillingAddress()->getData();
            $collection = $this->collection;
            $collection->addFieldToFilter('postcode',$postcode);            
            $collection = $collection->getData();
            $pinelabSubId ='';
            foreach ($collection as $item) {
                $pinelabSubId = $item["pinelabs_sub_id"];
            }
            if(!empty($pinelabSubId)){
                $merchantData['merchant_id'] = $pinelabSubId;
                $mcollection = $this->merchantCollection;
                $mcollection->addFieldToFilter('pinelabs_sub_id',$pinelabSubId)
                    ->addFieldToFilter('status',1);
                $mcollection = $mcollection->getData(); 
                foreach ($mcollection as $item) {
                    $merchantAccessCode = $item["merchant_access_code"];                    
                    $merchantData['access_code'] = $item["merchant_access_code"];                       
					$merchantData['secret_key'] = $item["merchant_secret_key"];                       
                }   
            }                    
        }

		$logger->info(__LINE__.':'.__CLASS__.': orderid55555req'.$order->getId());
        $logger->info(__LINE__.':'.__CLASS__.': Merchant data getpinewebhookmethodreq:'. json_encode($merchantData));
        return $merchantData;
    }
}