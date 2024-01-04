<?php
/**
 * Updates order status from the incoming webhook from Pinelab Edge Servers
 */
namespace Pinelabs\PinePGGateway\Controller\Standard;

use Magento\Sales\Model\Order;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface; 
// use Pinelabs\PinePGGateway\Controller\PinePGVerify;
use Magento\Framework\App\Request\InvalidRequestException;
use Unilever\CcavenueSubId\Model\ResourceModel\CcavenueSubId\Collection;
use Unilever\Pinelabs\Model\ResourceModel\Merchant\Collection as MerchantCollection;

class Webhook extends \Pinelabs\PinePGGateway\Controller\PinePGAbstract implements CsrfAwareActionInterface {

    /* 
    *  @var \Magento\Checkout\Model\Session
    */
    protected $config;
    protected $paymentMethod;
    protected $resultFactory;
    protected $encryptor;
    protected $urlEncoder;
    protected $customer;
    protected $customerSession;
    protected $checkoutSession;
    protected $orderInterface;
    protected $helper;
    protected $jsonRespond;
    	/**
     * @var Unilever\CcavenueSubId\Model\ResourceModel\CcavenueSubId\Collection
     */
    protected $collection;   
	 /**
     * @var Unilever\Pinelabs\Model\ResourceModel\Merchant\Collection
     */
    protected $merchantCollection;

    public function __construct(
        \Pinelabs\PinePGGateway\Model\PinePGPaymentMethod $paymentMethod,
        \Pinelabs\PinePGGateway\Helper\PinePG $checkoutHelper,
        \Pinelabs\PinePGGateway\Model\ConfigProvider $config,
        \Pinelabs\PinePGGateway\Helper\PinePG $helper,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Customer $customer,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Psr\Log\LoggerInterface $logger, 
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Framework\Encryption\EncryptorInterface $encryptorInterface,
        \Magento\Framework\Url\EncoderInterface $encoderInterface,
        \Magento\Sales\Api\Data\OrderInterface $orderInterface,        
        Collection $collection,        
        MerchantCollection $merchantCollection
    ) {
        parent::__construct($context, $customerSession, $checkoutSession, $quoteRepository, $orderFactory, $logger, $paymentMethod, $checkoutHelper, $cartManagement, $resultJsonFactory);
        $this->config = $config;
        $this->paymentMethod = $paymentMethod;
        $this->resultFactory = $resultFactory;
        $this->encryptor = $encryptorInterface;
        $this->urlEncoder = $encoderInterface;
        $this->customer = $customer;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderInterface = $orderInterface;
        $this->helper = $helper;
        $this->jsonRespond = $resultJsonFactory->create();
        $this->collection = $collection;        
        $this->merchantCollection = $merchantCollection;
    }

    public function execute() {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info(__LINE__.':'.__CLASS__.': Webhook execute() triggered for Update');
        
        try {
            $webhook = $this->paymentMethod->getConfigData("webhook");

            if(!$webhook) {
                $note = 'It doesn\'t allow to update order status';
                $response = ['success' => false, 'message' => $note ];
                $returnArray = json_encode($response);  
                $logger->info(__LINE__.':'.__CLASS__.': Update Status response:'. json_encode($returnArray));

                return $returnArray;
            }

            $data = json_decode(file_get_contents("php://input"), true);

            if(isset($data["body"])) {
                $extractedData = $data["body"];
            } else {
                $extractedData = $data;
            }
 
            $logger->info(__LINE__.':'.__CLASS__.": webhook request: " . json_encode($extractedData));

            // extracting request data
            if(isset($extractedData)){
                $linkOrderRef = $extractedData["merchant_response"]["unique_merchant_txn_id"];
                $order_id = explode("_", $linkOrderRef);
                $order_id = substr($order_id[1],-9); //get order_id part

                $enquiry_params['ppc_MerchantAccessCode'] = $extractedData["merchant_response"]['merchant_access_code'];
                $enquiry_params['ppc_MerchantID'] = $extractedData["merchant_response"]['merchant_id'];
                $enquiry_params['ppc_PinePGTransactionID'] = $extractedData["merchant_response"]['pine_pg_transaction_id'];
                $enquiry_params['ppc_TransactionType'] =  3;
                $enquiry_params['ppc_UniqueMerchantTxnID'] = $extractedData["merchant_response"]['unique_merchant_txn_id'];

                ksort($enquiry_params);
                $strString = "";

                foreach ($enquiry_params as $key => $val) {
                    $strString .= $key . "=" . $val . "&";
                }
                $merchantData = $this->getPinelabMerchantData($order);	
                $this->logger->info(__LINE__.':'.__CLASS__.': orderid_webhook'.$order->getId());
                $this->logger->info(__LINE__.':'.__CLASS__.': Merchant data :'. json_encode($merchantData));
                $merchantSecretKey	= '';
                if(isset($merchantData['secret_key']) && !empty($merchantData['secret_key'])){                    
                    $merchantSecretKey = $merchantData['secret_key'];                   
                }
                $secret_key = $merchantSecretKey;

                $strString = substr($strString, 0, -1);

                $logger->info(__LINE__.':'.__CLASS__.': Merchant data test:'. json_encode($merchantData));

                $logger->info('Enquiry Api Hash Generation :Request paramter is: ' . $strString);
                $this->logger->info('Merchant data secret_key webhook: ' . $secret_key);
                $code = strtoupper(hash_hmac('sha256', $strString, $this->Hex2String($secret_key)));
                $logger->info('Method[buildCheckoutRequest]:Hash of request is ' . $code);
                $params['ppc_MerchantAccessCode'] = $extractedData["merchant_response"]['merchant_access_code'];
                $params['ppc_MerchantID'] = $extractedData["merchant_response"]['merchant_id'];
                $params['ppc_PinePGTransactionID'] = $extractedData["merchant_response"]['pine_pg_transaction_id'];
                $params['ppc_TransactionType'] =  3;
                $params['ppc_UniqueMerchantTxnID'] = $extractedData["merchant_response"]['unique_merchant_txn_id'];
                $params['ppc_DIA_SECRET_TYPE'] = 'SHA256';
                $params['ppc_DIA_SECRET'] = $code;
                $params['ppc_Amount'] = $extractedData["merchant_response"]['amount_in_paisa'];

                $PayEnvironment = $this->paymentMethod->getConfigData("PayEnvironment");

                $verifyStatus = $this->verify($params,$PayEnvironment);
                $logger->info(__LINE__.'Verify Status:'. json_encode($verifyStatus));

                $status = $extractedData["merchant_response"]['txn_response_msg']; 

                // checking if order exists
                $order = $this->orderInterface->loadByIncrementId($order_id);
                $logger->info(__LINE__.'Verify Status:'. json_encode($order) );
                
                $MerchantAccessCode = $this->paymentMethod->getConfigData("MerchantAccessCode");
                $MerchantSecretKey = $this->paymentMethod->getConfigData("MerchantSecretKey");
                $orderStatusType = $this->paymentMethod->getConfigData("order_status");
                $statusCode = $extractedData["merchant_response"]["txn_response_code"]; 

                // validating signature
                $isSignatureValid = $verifyStatus;

                if($order->getId() && $isSignatureValid) {
                    $note = "";
                    $note .= "--- Webhook Update ---\n";
        
                    if (strtolower($status) == "success") {
                        $payment = $order->getPayment();

                        $this->paymentMethod->postProcessing($order, $payment, $verifyStatus);
        
                        $note .= "Transaction Status: Processing" . "\n";
                        $note .= "Order reference: " . $linkOrderRef;
            
                        $this->paymentMethod->addCommentToOrder($order_id, $note);
                        $logger->info(__LINE__.':'.__CLASS__.': webhook success');

                        $response = ['success' => true, 'message' => 'Successfully updated'];
        
                    } else if (strtolower($status) == "failure" || strtolower($status) == 'cancelled') {
                        $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
        
                        $note .= "Transaction Status: " . $status . "\n";
                        $note .= "Failure Code: " . $statusCode . "\n"; 
        
                        $this->paymentMethod->addCommentToOrder($order_id, $note);
                        $logger->info(__LINE__.':'.__CLASS__.': webhook failed');
                        $response = ['success' => false, 'message' => $note];

                    } else {
                        $note .= "Transaction Status: Pending";
                        $order->setState(Order::STATE_HOLDED)->setStatus(Order::STATE_HOLDED);
                        $this->paymentMethod->addCommentToOrder($order_id, $note);
                        $logger->info(__LINE__.':'.__CLASS__.': webhook pending');
                        $response = ['success' => true, 'message' => $note];
                    }
                } else {

                    if ($order_id) {
                        $logger->info(__LINE__.':'.__CLASS__.': Error in validating the signature');

                        $response = ['success' => false, 'message' => 'Error in validating the signature'];
                    } else {
                        $logger->info(__LINE__.':'.__CLASS__.': Error fetching order from order Id');

                        $response = ['success' => false, 'message' => 'Error fetching order from order Id'];
                    }

                }  
            }  

        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $note = $e->getMessage();
            $response = ['success' => false, 'message' => $note];
        } catch (\Exception $e) {
            $note = $e->getMessage();
            $response = ['success' => false, 'message' => $note ];
        }
        $logger->info(__LINE__.':'.__CLASS__.': Notes'. json_encode($note));

        return $this->jsonRespond->setData($response);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public static function verify($params,$PayEnvironment)
    {  
       // $writer = new \Zend_Log_Writer_Stream('/var/log/PinePG/' . date("Y-m-d") . '.log');
       $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        $curl = curl_init();

        $verifyApiUrl = 'https://pinepg.in/api/PG/V2';

        if ($PayEnvironment == 'TEST')
        {
            $verifyApiUrl = 'https://uat.pinepg.in/api/PG/V2';
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => $verifyApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => "ppc_DIA_SECRET=" . $params['ppc_DIA_SECRET'] . "&ppc_DIA_SECRET_TYPE=" . $params['ppc_DIA_SECRET_TYPE'] . "&ppc_MerchantAccessCode=" . $params['ppc_MerchantAccessCode'] . "&ppc_MerchantID=" . $params['ppc_MerchantID'] . "&ppc_PinePGTransactionID=" . $params['ppc_PinePGTransactionID'] . "&ppc_TransactionType=" . $params['ppc_TransactionType'] . "&ppc_UniqueMerchantTxnID=" . $params['ppc_UniqueMerchantTxnID'],
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded'
            ),
        ));

        $response = curl_exec($curl);
        $logger->info('Enquiry Api response:' . $response);

        $response = json_decode($response);

        if (isset($response->ppc_TxnResponseMessage) && $response->ppc_TxnResponseMessage == "SUCCESS" && isset($response->ppc_TxnResponseCode) && $response->ppc_TxnResponseCode == 1 && $params['ppc_Amount'] == $response->ppc_Amount) {
            $logger->info(__LINE__.'Enquiry Api');
            return $response;
        }
        $logger->info(__LINE__.'Enquiry Api');
        return false;
    }

    public static function Hex2String($hex){
        $string='';
        for ($i=0; $i < strlen($hex)-1; $i+=2){
            $string .= chr(hexdec($hex[$i].$hex[$i+1]));
        }
        return $string;
    }

    private function getPinelabMerchantData($order){
        
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info(__LINE__.':'.__CLASS__.': Webhook getPinelabMerchantData()');

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
         $logger->info(__LINE__.':'.__CLASS__.': orderidwebhook'.$order->getId());
        $logger->info(__LINE__.':'.__CLASS__.': Merchant data getpinewebhook:'. json_encode($merchantData));
        return $merchantData;
    }

}