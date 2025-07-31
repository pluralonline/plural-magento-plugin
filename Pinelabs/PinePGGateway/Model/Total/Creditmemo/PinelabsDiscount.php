<?php
namespace Pinelabs\PinePGGateway\Model\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

class PinelabsDiscount extends AbstractTotal
{
    public function collect(\Magento\Sales\Model\Order\Creditmemo $creditmemo)
    {
        $totalPinelabsDiscount = 0;

        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            $pinelabsDiscount = $orderItem->getData('pinepg_product_discount') + $orderItem->getData('pinepg_cashback_discount');
            $rowDiscount = $pinelabsDiscount * $item->getQty();

            $item->setDiscountAmount($item->getDiscountAmount() + $rowDiscount);
            $totalPinelabsDiscount += $rowDiscount;
        }

        $creditmemo->setDiscountAmount(-$totalPinelabsDiscount);
        $creditmemo->setGrandTotal($creditmemo->getGrandTotal() - $totalPinelabsDiscount);
        $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() - $totalPinelabsDiscount);

        return $this;
    }
}