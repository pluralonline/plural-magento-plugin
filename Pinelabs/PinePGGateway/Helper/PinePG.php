<?php

namespace Pinelabs\PinePGGateway\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Helper\AbstractHelper;

class PinePG extends AbstractHelper
{
    protected $session;
    protected $quote;
    protected $quoteManagement;
 

    public function __construct(
        Context $context,
        \Magento\Checkout\Model\Session $session,
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Model\QuoteManagement $quoteManagement
    ) {
        $this->session = $session;
        $this->quote = $quote;
        $this->quoteManagement = $quoteManagement;
        parent::__construct($context);
    }

    public function cancelCurrentOrder($comment)
    {
        $order = $this->session->getLastRealOrder();
        if ($order->getId() && $order->getState() != Order::STATE_CANCELED) {
            $order->registerCancellation($comment)->save();
            return true;
        }
        return false;
    }

    public function restoreQuote()
    {
        return $this->session->restoreQuote();
    }

    public function getUrl($route, $params = [])
    {
        return $this->_getUrl($route, $params);
    }

    public function getLogger() {

        // M2.3.4
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG/'.date("Y-m-d").'.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        return $logger;
    }
}
