<?php

namespace Pinelabs\PinePGGateway\Controller\Standard;

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
        \Magento\Framework\Url\EncoderInterface $encoderInterface,
		\Magento\Framework\Filesystem $filesystem
    ) {
        parent::__construct($context, $customerSession, $checkoutSession, $quoteRepository, $orderFactory, $logger, $paymentMethod, $checkoutHelper, $cartManagement, $resultJsonFactory, $filesystem);
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
		$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG/'.date("Y-m-d").'.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
			if(!array_key_exists('ppc_UniqueMerchantTxnID', $this->getRequest()->getParams())) {
				$resultRedirect->setPath('');
				return $resultRedirect;
			}

            $paymentMethod = $this->getPaymentMethod();
            $params = $this->getRequest()->getParams();

            $this->logger->info('Full PinePG Response Params: ' . json_encode($params));

            $merchantTxnID = $params['ppc_UniqueMerchantTxnID'];
            $order_id = explode('_', $merchantTxnID);
            $order_id = $order_id[1]; //get order_id part

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

            //$order = $objectManager->create('Magento\Sales\Api\Data\OrderInterface')->loadByIncrementId($order_id);
            $order = $objectManager->create(\Magento\Sales\Model\Order::class)->loadByIncrementId($order_id);
            $orderItemRepository = $objectManager->get(\Magento\Sales\Api\OrderItemRepositoryInterface::class);



            if (isset($params['ppc_TxnAdditionalInfo'])) {
                $this->logger->info('txnAdditionalInfo received: ' . $params['ppc_TxnAdditionalInfo']);
            
                $txnJson = base64_decode($params['ppc_TxnAdditionalInfo']);
                $this->logger->info('Base64-decoded ppc_TxnAdditionalInfo: ' . $txnJson);
            
                $txnData = json_decode($txnJson, true);
            
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('JSON decode error: ' . json_last_error_msg());
                } else {
                    $this->logger->info('Decoded txnAdditionalInfo JSON: ' . json_encode($txnData));
                }
            
                if (isset($txnData['product_details']) && is_array($txnData['product_details'])) {
                    $this->logger->info('product_details found with ' . count($txnData['product_details']) . ' item(s).');
                    
                    $items = $order->getAllItems();
                    foreach ($items as $item) {
                        foreach ($txnData['product_details'] as $productDetail) {
                            if ($item->getSku() == $productDetail['product_code']) {
                                $item->setData('pinepg_product_amount', $productDetail['product_amount'] / 100);
                                $item->setData('pinepg_cashback_discount', $productDetail['subvention_cashback_discount'] / 100);
                                $item->setData('pinepg_product_discount', $productDetail['product_discount'] / 100);
                                $item->setData('pinepg_cashback_discount_percentage', $productDetail['subvention_cashback_discount_percentage']); // Leave as-is, it's already a %
                                $item->setData('pinepg_oem_name', $productDetail['oem_name']);
                                $item->setData('pinepg_oem_id', $productDetail['oem_id']);
            
                                $orderItemRepository = \Magento\Framework\App\ObjectManager::getInstance()
                                    ->get(\Magento\Sales\Api\OrderItemRepositoryInterface::class);
                                $orderItemRepository->save($item);
            
                                $this->logger->info('Saved item SKU: ' . $item->getSku() . ', Data: ' . json_encode([
                                    'pinepg_product_amount' => $productDetail['product_amount'] / 100,
                                    'pinepg_cashback_discount' => $productDetail['subvention_cashback_discount'] / 100,
                                    'pinepg_product_discount' => $productDetail['product_discount'] / 100,
                                    'pinepg_cashback_discount_percentage' => $productDetail['subvention_cashback_discount_percentage'],
                                    'pinepg_oem_name' => $productDetail['oem_name'],
                                    'pinepg_oem_id' => $productDetail['oem_id']
                                ]));
                            }
                        }
                    }
                } else {
                    $this->logger->warning('product_details not found or not an array in txnAdditionalInfo.');
                }
            } else {
                $this->logger->info('txnAdditionalInfo not present in params.');
            }
            

            if(!$order->getCustomerIsGuest()) {
                $customer = $this->customer->load($order->getCustomerId());
                $this->customerSession->setCustomerAsLoggedIn($customer);                    
            }

            $this->checkoutSession->setLastQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastOrderStatus($order->getStatus());

            if ($paymentMethod->validateResponse($params)) {

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

                if (isset($params['ppc_CapturedAmount'])) {
                    $order->setData('pinepg_captured_amount', $params['ppc_CapturedAmount'] / 100); // Divide by 100 if it's in paisa
                    $order->save();
                }

                if (!PinePGVerify::verify($params,$PayEnvironment)) {
                    $this->_cancelPayment('Payment fails');
                    $resultRedirect->setPath('checkout/onepage/failure');
                    return $resultRedirect;
                }

                $order->setState('processing')->setStatus('processing');

                $order->save();

                

                $payment = $order->getPayment();

                $paymentMethod->postProcessing($order, $payment, $params);


                $encryptedOrderId = $this->encryptor->encrypt($order->getId());

                $urlEncodedOrderId = $this->urlEncoder->encode($encryptedOrderId);
               
                $resultRedirect->setPath('checkout/onepage/success');

                try {
                    $orderSender = $objectManager->create('Magento\Sales\Model\Order\Email\Sender\OrderSender');
                    $orderSender->send($order);
                } catch (\Exception $e) {
                    $this->logger->critical($e);
                } catch (\Laminas\Mail\Transport\Exception\RuntimeException $e) {
                    $this->logger->critical($e);
                }
            } else {
                $resultRedirect->setPath('checkout/onepage/failure');
                $this->_cancelPayment('Payment fails');
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order.'));
        }

        return $resultRedirect;
    }
}
