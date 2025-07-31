<?php
namespace Pinelabs\PinePGGateway\Model\Total\Invoice;

use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;

class PinelabsDiscount extends AbstractTotal
{
    public function collect(\Magento\Sales\Model\Order\Invoice $invoice)
    {
        $totalPinelabsDiscount = 0;

        foreach ($invoice->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            $pinelabsDiscount = $orderItem->getData('pinepg_product_discount') + $orderItem->getData('pinepg_cashback_discount');
            $rowDiscount = $pinelabsDiscount * $item->getQty();

            $item->setDiscountAmount($item->getDiscountAmount() + $rowDiscount);
            $totalPinelabsDiscount += $rowDiscount;
        }

        $invoice->setDiscountAmount(-$totalPinelabsDiscount);
        $invoice->setGrandTotal($invoice->getGrandTotal() - $totalPinelabsDiscount);
        $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() - $totalPinelabsDiscount);

        return $this;
    }
}