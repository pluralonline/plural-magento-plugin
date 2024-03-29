<?php
/**
 *
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Pinelabs\PinePGGateway\Observer;
//namespace Magento\Checkout\Controller\Onepage;

class PinePGFailure extends \Magento\Checkout\Controller\Onepage
{
    /**
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $lastQuoteId = $this->getOnepage()->getCheckout()->getLastQuoteId();
        $lastOrderId = $this->getOnepage()->getCheckout()->getLastOrderId();
        if (!$lastQuoteId || !$lastOrderId) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        return $this->resultPageFactory->create();
    }
}