<?php

namespace Pinelabs\PinePGGateway\Model;

use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Framework\Session\Config;
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
       
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Checkout\Model\Session $checkoutSession   ,
        \Magento\Checkout\Model\Cart $cart,
		\Magento\Directory\Model\Country $countryHelper		
    ) {
        $this->helper = $helper;
        $this->httpClientFactory = $httpClientFactory;
        $this->checkoutSession = $checkoutSession;
        $this->cart = $cart;
		// $this->_countryHelper = \Magento\Framework\App\ObjectManager::getInstance()->get('\Magento\Directory\Model\Country');

		$this->_countryHelper = $countryHelper;

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
    $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG/'.date("Y-m-d").'.log');
    $this->logger = new \Zend_Log();
    $this->logger->addWriter($writer);

    $order = $this->checkoutSession->getLastRealOrder();
    
    $this->logger->info("===== STARTING PINE PG REQUEST BUILD =====");
    $this->logger->info("Order ID: ".$order->getIncrementId());
    
    // Convert amounts to paisa (×100)
    // Get the base grand total
$baseGrandTotal = $order->getBaseGrandTotal();

// Log the raw value before rounding
$this->logger->info('PinePG Payment: Base grand total value before rounding', [
    'order_id' => $order->getIncrementId(),
    'base_grand_total' => $baseGrandTotal
]);

// Safely round and multiply
$grandTotal = round((float) ($baseGrandTotal ?? 0), 2) * 100;

// Log the final calculated grand total
$this->logger->info('PinePG Payment: Calculated grand total (after rounding * 100)', [
    'order_id' => $order->getIncrementId(),
    'grand_total' => $grandTotal
]);

    $discountAmount = abs($order->getBaseDiscountAmount()) * 100;
    $shippingAmount = $order->getBaseShippingAmount() * 100;
    $taxAmount = $order->getBaseTaxAmount() * 100;

    $this->logger->info("Base Amounts:");
    $this->logger->info(sprintf("Grand Total: %.2f (%d paisa)", $grandTotal/100, $grandTotal));
    $this->logger->info(sprintf("Discount: %.2f (%d paisa)", $discountAmount/100, $discountAmount));
    $this->logger->info(sprintf("Shipping: %.2f (%d paisa)", $shippingAmount/100, $shippingAmount));
    $this->logger->info(sprintf("Tax: %.2f (%d paisa)", $taxAmount/100, $taxAmount));

    // Initialize arrays
    $productInfo = [];
    $productDetails = [];
    $totalProductAmount = 0;
    $totalDiscountedAmount = 0;

    $this->logger->info("===== PRODUCT DETAILS =====");
    
    // First pass: Calculate total product amount and collect discount information
    $items = $order->getAllVisibleItems();
    $totalItems = count($items);
    $hasProductLevelDiscount = false;
    
    foreach ($items as $item) {
        $itemDiscount = abs($item->getDiscountAmount()) * 100;
        if ($itemDiscount > 0) {
            $hasProductLevelDiscount = true;
            break;
        }
    }

    foreach ($items as $item) {
        $price = round($item->getPrice(), 2) * 100;
        $qty = (int)$item->getQtyOrdered();
        $itemTotal = $price * $qty;
        $itemDiscount = abs($item->getDiscountAmount()) * 100;
        
        // For cart-level discount, distribute discount equally among items
        if (!$hasProductLevelDiscount && $discountAmount > 0) {
            $itemDiscount = round($discountAmount / $totalItems, 0);
        }
        
        $discountedItemTotal = $itemTotal - $itemDiscount;
        $discountedPricePerItem = round($discountedItemTotal / $qty, 0);
        
        $this->logger->info(sprintf(
            "Product: %s | SKU: %s | Price: %.2f | Qty: %d | Item Total: %.2f | Discount: %.2f | Discounted Total: %.2f",
            $item->getName(),
            $item->getSku(),
            $price/100,
            $qty,
            $itemTotal/100,
            $itemDiscount/100,
            $discountedItemTotal/100
        ));

        // Add product name (once per SKU)
        $productInfo[] = $item->getName();

		   $price = floatval($item->getPrice());

			// ✅ Skip if product price is zero
			if ($price <= 0) {
				continue;
			}
		
        
        // Add one line per quantity with discounted price
        for ($i = 0; $i < $qty; $i++) {
            $productDetails[] = [
                'product_code' => $item->getSku(),
                'product_amount' => $discountedPricePerItem
            ];
            $totalProductAmount += $discountedPricePerItem;
            $totalDiscountedAmount += ($price - $discountedPricePerItem);
        }
    }

    // Add shipping as separate line item if needed
    if ($shippingAmount > 0) {
        $this->logger->info(sprintf("Adding Shipping: %.2f", $shippingAmount/100));
        $productDetails[] = [
            'product_code' => 'SHIPPING',
            'product_amount' => $shippingAmount
        ];
    }

    $this->logger->info("===== AMOUNT CALCULATION =====");
    $this->logger->info(sprintf("Total Products: %.2f", $totalProductAmount/100));
    $this->logger->info(sprintf("+ Shipping: %.2f", $shippingAmount/100));
    $this->logger->info(sprintf("- Total Discount Applied: %.2f", $totalDiscountedAmount/100));
    $calculatedTotal = $totalProductAmount + $shippingAmount;
    $this->logger->info(sprintf("= Calculated Total: %.2f", $calculatedTotal/100));
    $this->logger->info(sprintf("Order Grand Total: %.2f", $grandTotal/100));

    if (abs($grandTotal - $calculatedTotal) > 1) {
        $this->logger->err(sprintf(
            "AMOUNT MISMATCH: Difference of %.2f detected!", 
            abs($grandTotal - $calculatedTotal)/100
        ));
    }

    // Build request parameters
    $params = [
        // Payment amounts
        "ppc_Amount" => $grandTotal,
        "ppc_CouponDiscountAmount" => $discountAmount,
        
        // Merchant info
        "ppc_MerchantID" => $this->getConfigData("MerchantId"),
        "ppc_MerchantAccessCode" => $this->getConfigData("MerchantAccessCode"),
        
        // Product info
        "ppc_MerchantProductInfo" => implode('|', array_unique($productInfo)),
        "ppc_MultiCartProductDetails" => base64_encode(json_encode($productDetails)),
        
        // Other required fields
        "ppc_UniqueMerchantTxnID" => uniqid().'_'.$order->getIncrementId(),
        "ppc_MerchantReturnURL" => $this->getReturnUrl(),
        "ppc_NavigationMode" => '2',
        "ppc_PayModeOnLandingPage" => $this->getConfigData("MerchantPaymentMode"),
        "ppc_TransactionType" => '1',
    ];

    // Add customer data
    $billing = $order->getBillingAddress();
    $params += [
        'ppc_CustomerFirstName' => $billing->getFirstname(),
        'ppc_CustomerLastName' => $billing->getLastname(),
        'ppc_CustomerMobile' => $billing->getTelephone(),
        'ppc_CustomerEmail' => $billing->getEmail()
    ];

    ksort($params);
    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = "$key=$value";
    }
    $stringToHash = implode('&', $pairs);

    $secretKey = $this->Hex2String($this->getConfigData("MerchantSecretKey"));
    $params['ppc_DIA_SECRET_TYPE'] = 'SHA256';
    $params['ppc_DIA_SECRET'] = strtoupper(hash_hmac('sha256', $stringToHash, $secretKey));

    $this->logger->info("===== FINAL REQUEST PARAMS =====");
    $this->logger->info(json_encode($params, JSON_PRETTY_PRINT));
    $this->logger->info("String to Hash: ".$stringToHash);
    $this->logger->info("Generated Hash: ".$params['ppc_DIA_SECRET']);
    $this->logger->info("===== REQUEST BUILD COMPLETE =====");

    return $params;
}
	 
	  //validate response
    public function validateResponse($returnParams) {

		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG/'.date("Y-m-d").'.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__);
		
		$order_id=0;
		if (isset($returnParams['ppc_UniqueMerchantTxnID'])) 
		{
		  $order_id = trim(($returnParams['ppc_UniqueMerchantTxnID']));
		  $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' validate response for order id:'.$order_id);
		} 
		else 
		{
		 $this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Received order id is null');
		  die('Illegal Access ORDER ID NOR PASSED');
		}
		
		$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order_info = $objectManager->create('Magento\Sales\Model\Order')->load($order_id);
		
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
			$secret_key   =   $this -> Hex2String($this->getConfigData("MerchantSecretKey"));
			ksort($returnParams);
			foreach ($returnParams as $key => $value)
			{
				$strString.=$key."=".$value."&";
			}			
			$strString = substr($strString, 0, -1);
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

		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG/'.date("Y-m-d").'.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__);
 
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
        $order->setStatus('processing');
		// Add a comment to the order
		$order->addStatusHistoryComment('<b>UniqueMerchantTxnID </b>'.$response['ppc_UniqueMerchantTxnID'].', <b>Txn Id:</b> '. $response['ppc_PinePGTransactionID'].', <b>Txn DateTime:</b> '. $response['ppc_TransactionCompletionDateTime']. ', <b>Txn Status:</b> '. $response['ppc_TxnResponseMessage']);
        $order->save();
		$this->logger->info(__LINE__ . ' | '.__FUNCTION__.' Save the order after successful response from Pine PG for order id:'.$response['ppc_UniqueMerchantTxnID'].'and Pine PG Txn ID:'.$response['ppc_PinePGTransactionID'] );
    }

	private function checkCartType($product_info_data, $params, $order)
{
    if ($params['ppc_Carttype'] == 'MultiCart') {
        if ($product_info_data) {
            $discountAmount = $order->getDiscountAmount() ? (int)(abs(floatval($order->getDiscountAmount())) * 100) : 0;
            $shippingAmount = $order->getShippingAmount() ? (int)(floatval($order->getShippingAmount()) * 100) : 0;
            
            if ($discountAmount > 0) {
                $productTotalAmt_beforeDiscount = array_sum(array_map(function($item) {
                    return $item->product_amount;
                }, $product_info_data)) + $discountAmount;
                
                $product_info_data = $this->calculation_on_items($product_info_data, $productTotalAmt_beforeDiscount, $discountAmount);
            }
            
            // Add shipping as separate item if needed
            if ($shippingAmount > 0) {
                $shippingProduct = new \stdClass();
                $shippingProduct->product_code = 'SHIPPING';
                $shippingProduct->product_amount = $shippingAmount;
                $product_info_data[] = $shippingProduct;
            }
            
            $params['ppc_MultiCartProductDetails'] = base64_encode(json_encode($product_info_data));
        } else {
            $params['ppc_MultiCartProductDetails'] = '';
            unset($params['ppc_MultiCartProductDetails']);
        }
    }
    unset($params['ppc_Carttype']);
    return $params;
}

	private function calculation_on_items($items,$total_amt,$discount){ 

		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG/'.date("Y-m-d").'.log');
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
}