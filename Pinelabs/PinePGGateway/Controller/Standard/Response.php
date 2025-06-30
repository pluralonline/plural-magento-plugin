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
                $this->processPinelabsDiscounts($order, $params['ppc_TxnAdditionalInfo']);
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
                    $this->processCapturedAmount($order, $params['ppc_CapturedAmount']);
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


    protected function processPinelabsDiscounts($order, $txnAdditionalInfo)
{
    $this->logger->info('Processing Pinelabs discounts');
    
    $txnJson = base64_decode($txnAdditionalInfo);
    $txnData = json_decode($txnJson, true);
    
    if (!isset($txnData['product_details']) || !is_array($txnData['product_details'])) {
        $this->logger->warning('No product details found in txnAdditionalInfo');
        return;
    }

    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    $orderItemRepository = $objectManager->get(\Magento\Sales\Api\OrderItemRepositoryInterface::class);
    
    $totalGatewayDiscount = 0;
    $items = $order->getAllItems();
    
    foreach ($items as $item) {
        foreach ($txnData['product_details'] as $productDetail) {
            if ($item->getSku() == $productDetail['product_code']) {
                // Calculate discounts
                $productDiscount = $productDetail['product_discount'] / 100;
                $cashbackDiscount = $productDetail['subvention_cashback_discount'] / 100;
                $totalItemDiscount = $productDiscount + $cashbackDiscount;
                
                // Native Magento discount fields
                $item->setDiscountAmount($totalItemDiscount)
                     ->setBaseDiscountAmount($totalItemDiscount)
                     ->setOriginalDiscountAmount($totalItemDiscount);
                
                // Custom fields (preserved as per requirement)
                $item->setData('pinepg_product_amount', $productDetail['product_amount'] / 100);
                $item->setData('pinepg_cashback_discount', $cashbackDiscount);
                $item->setData('pinepg_product_discount', $productDiscount);
                $item->setData('pinepg_cashback_discount_percentage', $productDetail['subvention_cashback_discount_percentage']);
                $item->setData('pinepg_oem_name', $productDetail['oem_name']);
                $item->setData('pinepg_oem_id', $productDetail['oem_id']);
                
                // Adjust row totals
                $rowTotal = ($item->getPrice() * $item->getQtyOrdered()) - $totalItemDiscount;
                $item->setRowTotal($rowTotal)
                     ->setBaseRowTotal($rowTotal);
                
                $totalGatewayDiscount += $totalItemDiscount;
                
                $orderItemRepository->save($item);
                
                $this->logger->info(sprintf(
                    'Applied discounts to SKU %s: Product ₹%s + Cashback ₹%s = Total ₹%s',
                    $item->getSku(),
                    number_format($productDiscount, 2),
                    number_format($cashbackDiscount, 2),
                    number_format($totalItemDiscount, 2)
                ));
            }
        }
    }
    
    // Update order totals
    if ($totalGatewayDiscount > 0) {
        $order->setDiscountAmount($totalGatewayDiscount)
              ->setBaseDiscountAmount($totalGatewayDiscount)
              ->setSubtotalWithDiscount($order->getSubtotal() - $totalGatewayDiscount)
              ->setBaseSubtotalWithDiscount($order->getBaseSubtotal() - $totalGatewayDiscount)
              ->setDiscountDescription('Pinelabs Gateway Discount');
        
        // Recalculate totals to ensure consistency
        $order->setGrandTotal($order->getSubtotal() + $order->getTaxAmount() + $order->getShippingAmount() - $totalGatewayDiscount)
              ->setBaseGrandTotal($order->getBaseSubtotal() + $order->getBaseTaxAmount() + $order->getBaseShippingAmount() - $totalGatewayDiscount);
        
        $this->logger->info(sprintf(
            'Order %s: Applied total gateway discount of ₹%s. New grand total: ₹%s',
            $order->getIncrementId(),
            number_format($totalGatewayDiscount, 2),
            number_format($order->getGrandTotal(), 2)
        ));
    }
}

/**
 * Process captured amount and verify totals
 */
protected function processCapturedAmount($order, $capturedAmount)
{
    $capturedAmount = $capturedAmount / 100;
    $grandTotal = $order->getGrandTotal();
    
    // Store original captured amount in custom field
    $order->setData('pinepg_captured_amount', $capturedAmount);
    
    // Verify amount matches (allow small rounding differences)
    if (abs($capturedAmount - $grandTotal) > 0.01) {
        $this->logger->warning(sprintf(
            'Amount mismatch! Pinelabs captured ₹%s but order total is ₹%s',
            number_format($capturedAmount, 2),
            number_format($grandTotal, 2)
        ));
    }
    
    // Mark as fully paid
    $order->setTotalPaid($grandTotal)
          ->setBaseTotalPaid($grandTotal)
          ->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
          ->setStatus($order->getConfig()->getStateDefaultStatus(\Magento\Sales\Model\Order::STATE_PROCESSING));
    
    // Add payment transaction
    $payment = $order->getPayment();
    $payment->setAmountPaid($grandTotal)
            ->setBaseAmountPaid($grandTotal)
            ->setIsTransactionClosed(true);
    
    $payment->addTransaction(
        \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE,
        null,
        true
    );
    
    // Add order comment
    $order->addCommentToStatusHistory(
        sprintf('Pinelabs payment captured: ₹%s. Discount applied: ₹%s',
            number_format($capturedAmount, 2),
            number_format($order->getDiscountAmount(), 2)
        ),
        false
    )->setIsCustomerNotified(true);
}

}
