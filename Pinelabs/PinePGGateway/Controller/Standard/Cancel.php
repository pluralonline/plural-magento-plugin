<?php

namespace Pinelabs\PinePGGateway\Controller\Standard;

class Cancel extends \Pinelabs\PinePGGateway\Controller\PinePGAbstract {

    public function execute() {
        $this->getOrder()->cancel()->save();
        
        $this->messageManager->addErrorMessage(__('Your order has been can cancelled'));
        $this->getResponse()->setRedirect(
                $this->getCheckoutHelper()->getUrl('checkout')
        );
    }
}
