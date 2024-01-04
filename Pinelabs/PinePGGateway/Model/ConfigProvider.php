<?php

namespace Pinelabs\PinePGGateway\Model;

class ConfigProvider extends \Magento\Payment\Model\Method\AbstractMethod implements \Magento\Checkout\Model\ConfigProviderInterface
{
    protected $methodCode = \Pinelabs\PinePGGateway\Model\PinePGPaymentMethod::PAYMENT_PINE_PG_CODE;
    
    protected $method;

    public function __construct(\Magento\Payment\Helper\Data $paymenthelper){
        $this->method = $paymenthelper->getMethodInstance($this->methodCode);
    }

    public function getConfig(){

        return $this->method->isAvailable() ? [
            'payment'=>[
                'pinepg'=>[
                    'redirectUrl'=>$this->method->getRedirectUrl()  
                ]
        ]
        ]:[];
    }
}
