<?php

namespace Pinelabs\PinePGGateway\Controller\Standard;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Quote\Api\CartManagementInterface;
use Pinelabs\PinePGGateway\Controller\PinePGVerify;
use Pinelabs\PinePGGateway\Model\ConfigProvider;
use Pinelabs\PinePGGateway\Model\PinePGPaymentMethod;
use \Magento\Framework\Controller\ResultFactory;

class Response extends \Pinelabs\PinePGGateway\Controller\PinePGAbstract {
  
    protected $config;
    protected $pinePGPaymentMethod;
    protected $resultFactory;
    protected $encryptor;
    protected $urlEncoder;
    protected $customer;
    protected $customerSession;
    protected $checkoutSession;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Customer $customer,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Psr\Log\LoggerInterface $logger,
        \Pinelabs\PinePGGateway\Model\PinePGPaymentMethod $paymentMethod,
        \Pinelabs\PinePGGateway\Helper\PinePG $checkoutHelper,
        \Pinelabs\PinePGGateway\Model\ConfigProvider $config,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Framework\Encryption\EncryptorInterface $encryptorInterface,
        \Magento\Framework\Url\EncoderInterface $encoderInterface
    ) {
        parent::__construct($context, $customerSession, $checkoutSession, $quoteRepository, $orderFactory, $logger, $paymentMethod, $checkoutHelper, $cartManagement, $resultJsonFactory);
        $this->config = $config;
        $this->pinePGPaymentMethod = $paymentMethod;
        $this->resultFactory = $resultFactory;
        $this->encryptor = $encryptorInterface;
        $this->urlEncoder = $encoderInterface;
        $this->customer = $customer;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
    }
    
	public function execute()
    { 
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);
        $resultRedirect = $this->resultRedirectFactory->create();
 
        $this->logger->info(__LINE__.'parameters '. json_encode( $this->getRequest()->getParams()));

        try {
			if(!array_key_exists('ppc_UniqueMerchantTxnID', $this->getRequest()->getParams())) {
				$resultRedirect->setPath('');
				return $resultRedirect;
			} 

            $paymentMethod = $this->getPaymentMethod();
            $params = $this->getRequest()->getParams();

            $merchantTxnID = $params['ppc_UniqueMerchantTxnID'];
            $order_id = explode('_', $merchantTxnID);
            $order_id = substr($order_id[1],-9); //get order_id part
            $this->logger->info(print_r($params,true)); 

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

            $order = $objectManager->create('Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($order_id);
            $this->logger->info(__LINE__.'order response - '. json_encode($order));
            $orderId = base64_encode($order_id);
             if(!$order->getCustomerIsGuest()) {
                 $customer = $this->customer->load($order->getCustomerId());
                $this->logger->info(__LINE__.'response - '.json_encode($customer));
                 $this->customerSession->setCustomerAsLoggedIn($customer);                    
            }
            $this->logger->info(__LINE__.'response - ');

            $this->checkoutSession->setLastQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastOrderStatus($order->getStatus());
            $this->logger->info(__LINE__.'response - ');

            if ($paymentMethod->validateResponse($params)) {
                $this->logger->info(__LINE__.'response - ');

                $enquiry_params['ppc_MerchantAccessCode'] = $params['ppc_MerchantAccessCode'];
                $enquiry_params['ppc_MerchantID'] = $params['ppc_MerchantID'];
                $enquiry_params['ppc_PinePGTransactionID'] = $params['ppc_PinePGTransactionID'];
                $enquiry_params['ppc_TransactionType'] = $params['ppc_TransactionType'] = 3;
                $enquiry_params['ppc_UniqueMerchantTxnID'] = $params['ppc_UniqueMerchantTxnID'];

                ksort($enquiry_params);
                $strString = "";

                foreach ($enquiry_params as $key => $val) {
                    $strString .= $key . "=" . $val . "&";
                }

                $secret_key = $this->pinePGPaymentMethod->getConfigData("MerchantSecretKey");

                $strString = substr($strString, 0, -1);

                $this->logger->info('Enquiry Api Hash Generation :Request paramter is: ' . $strString);

                $code = strtoupper(hash_hmac('sha256', $strString, PinePGVerify::Hex2String($secret_key)));
                $this->logger->info('Method[buildCheckoutRequest]:Hash of request is ' . $code);
                $params['ppc_DIA_SECRET_TYPE'] = 'SHA256';
                $params['ppc_DIA_SECRET'] = $code;

                $PayEnvironment = $this->pinePGPaymentMethod->getConfigData("PayEnvironment");

                $verifyStatus = PinePGVerify::verify($params,$PayEnvironment);
                $this->logger->info(__LINE__.'Verify Status:'. $verifyStatus );

               // if (PinePGVerify::verify($params,$PayEnvironment)) {
                if($verifyStatus != 1){
                    $this->_cancelPayment('Payment fails');
                    $this->logger->info('Enquiry Api response: Payment fails');
                    //$resultRedirect->setPath('checkout/onepage/failure');
                    //return $resultRedirect;
                    return $this->resultRedirectFactory->create()->setUrl('/failure/orderId/' . $order_id . '/1');
                }
                $this->logger->info(__LINE__.'Enquiry Api response: Payment success');
                $order->setState('processing')->setStatus('payment_received');

                $order->save();
                $this->logger->info(__LINE__.'Enquiry Api response:'. json_encode($order) );

                $payment = $order->getPayment();
                $this->logger->info(__LINE__.'response payment- '. json_encode($payment));


                $paymentMethod->postProcessing($order, $payment, $params);

                $encryptedOrderId = $this->encryptor->encrypt($order->getId());

                $urlEncodedOrderId = $this->urlEncoder->encode($encryptedOrderId);
               
                //$resultRedirect->setPath('checkout/onepage/success');

                try {
                    $orderSender = $objectManager->create('Magento\Sales\Model\Order\Email\Sender\OrderSender');
                    $orderSender->send($order);
                } catch (\Exception $e) {
                    $this->logger->critical($e);
                } catch (\Laminas\Mail\Transport\Exception\RuntimeException $e) {
                    $this->logger->critical($e);
                }
                return $this->resultRedirectFactory->create()->setUrl('/success/orderId/' . $orderId);
            } else {
                //$resultRedirect->setPath('checkout/onepage/failure');
                $this->_cancelPayment('Payment fails');
                return $this->resultRedirectFactory->create()->setUrl('/failure/orderId/' . $order_id . '/1');
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order.'));
        }

        //return $resultRedirect;
        return $this->resultRedirectFactory->create()->setUrl('/failure/orderId/' . $order_id . '/1');
    }
}
