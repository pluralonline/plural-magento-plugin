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
				  $resultRedirect->setPath('/'); 
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

                if (isset($params['ppc_TxnAdditionalInfo'])) {
                $this->processPinelabsDiscounts($order, $params['ppc_TxnAdditionalInfo']);
            }
               
                
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

    try {
        $txnJson = base64_decode($txnAdditionalInfo);
        $txnData = json_decode($txnJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in txnAdditionalInfo');
        }

        $this->logger->info("Decoded txnData: " . json_encode($txnData));

        if (!isset($txnData['product_details']) || !is_array($txnData['product_details'])) {
            $this->logger->warning('No product_details found in txnAdditionalInfo');
            return;
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $orderItemRepository = $objectManager->get(\Magento\Sales\Api\OrderItemRepositoryInterface::class);

        $totalMagentoDiscount = abs($order->getDiscountAmount());
        $totalPinelabsDiscount = 0.0;
        $magentoDiscountApplied = false;

        // First pass - calculate total discounts
        foreach ($order->getAllVisibleItems() as $item) {
            foreach ($txnData['product_details'] as $productDetail) {
                if ($item->getSku() == $productDetail['product_code']) {
                    $pinelabsProductDiscount = $productDetail['product_discount'] / 100;
                    $pinelabsCashbackDiscount = $productDetail['subvention_cashback_discount'] / 100;
                    $totalPinelabsDiscount += $pinelabsProductDiscount + $pinelabsCashbackDiscount;
                }
            }
        }

        $newOrderDiscount = $totalMagentoDiscount + $totalPinelabsDiscount;
        $newGrandTotal = $order->getSubtotal() + $order->getShippingAmount() - $newOrderDiscount;

        // Second pass - apply discounts to items
        foreach ($order->getAllVisibleItems() as $item) {
            foreach ($txnData['product_details'] as $productDetail) {
                if ($item->getSku() == $productDetail['product_code']) {
                    $pinelabsProductDiscount = $productDetail['product_discount'] / 100;
                    $pinelabsCashbackDiscount = $productDetail['subvention_cashback_discount'] / 100;
                    $pinelabsTotal = $pinelabsProductDiscount + $pinelabsCashbackDiscount;

                    $magentoItemDiscount = 0.0;
                    if (!$magentoDiscountApplied && $totalMagentoDiscount > 0) {
                        $magentoItemDiscount = $totalMagentoDiscount;
                        $magentoDiscountApplied = true;
                    }

                    $combinedItemDiscount = $magentoItemDiscount + $pinelabsTotal;
                    $qty = $item->getQtyOrdered();
                    $itemPrice = $item->getPrice();

                    $finalItemPrice = max(0, $itemPrice - ($combinedItemDiscount / $qty));
                    $finalRowTotal = max(0, ($itemPrice * $qty) - $combinedItemDiscount);

                    $item->setDiscountAmount($combinedItemDiscount)
                        ->setBaseDiscountAmount($combinedItemDiscount)
                        ->setRowTotal($finalRowTotal)
                        ->setBaseRowTotal($finalRowTotal)
                        ->setPrice($finalItemPrice)
                        ->setBasePrice($finalItemPrice);

                    // Set additional PinePG data
                    $item->setData('pinepg_product_amount', $productDetail['product_amount'] / 100)
                        ->setData('pinepg_cashback_discount', $pinelabsCashbackDiscount)
                        ->setData('pinepg_product_discount', $pinelabsProductDiscount)
                        ->setData('pinepg_cashback_discount_percentage', $productDetail['subvention_cashback_discount_percentage'])
                        ->setData('pinepg_oem_name', $productDetail['oem_name'] ?? '')
                        ->setData('pinepg_oem_id', $productDetail['oem_id'] ?? 0);

                    $orderItemRepository->save($item);
                }
            }
        }

        // Update order totals
        $order->setDiscountAmount(-$newOrderDiscount)
              ->setBaseDiscountAmount(-$newOrderDiscount)
              ->setGrandTotal($newGrandTotal)
              ->setBaseGrandTotal($newGrandTotal)
              ->setTotalPaid($newGrandTotal)  // This is critical
              ->setBaseTotalPaid($newGrandTotal)  // This is critical
              ->setTotalDue(0)
              ->setBaseTotalDue(0)
              ->setDiscountDescription('Magento Coupon + Pinelabs Gateway Discount');

        // Force update payment information
        $payment = $order->getPayment();
        if ($payment) {
            $payment->setAmountPaid($newGrandTotal)
                    ->setBaseAmountPaid($newGrandTotal)
                    ->setAmountAuthorized($newGrandTotal)
                    ->setBaseAmountAuthorized($newGrandTotal)
                    ->setAmountOrdered($newGrandTotal)  // Important addition
                    ->setBaseAmountOrdered($newGrandTotal)  // Important addition
                    ->setShippingAmount(0)
                    ->setBaseShippingAmount(0)
                    ->setIsTransactionClosed(true);

            // Add transaction with correct amount
            $payment->addTransaction(
                \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE,
                null,
                true,
                ['amount' => $newGrandTotal]
            );
            
            $payment->save();
        }

        // Add status history
        $order->addCommentToStatusHistory(
            __('Applied discounts - Magento: ₹%1, Pinelabs: ₹%2. Final amount: ₹%3',
               number_format($totalMagentoDiscount, 2),
               number_format($totalPinelabsDiscount, 2),
               number_format($newGrandTotal, 2))
        );

        $this->logger->info(sprintf(
            'Order %s: Final totals - Subtotal: ₹%.2f, Discount: ₹%.2f, Grand Total: ₹%.2f, Paid: ₹%.2f',
            $order->getIncrementId(),
            $order->getSubtotal(),
            $newOrderDiscount,
            $newGrandTotal,
            $newGrandTotal
        ));

        // Final save with all changes
        $order->save();

    } catch (\Exception $e) {
        $this->logger->error('Failed to process Pinelabs discounts: ' . $e->getMessage());
        throw $e;
    }
}






     


}
