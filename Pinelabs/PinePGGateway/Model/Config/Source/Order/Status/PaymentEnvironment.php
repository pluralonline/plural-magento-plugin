<?php
namespace Pinelabs\PinePGGateway\Model\Config\Source\Order\Status;

use Magento\Framework\Option\ArrayInterface;
class PaymentEnvironment implements ArrayInterface
{

/*
 * Option getter
 * @return array
 */
public function toOptionArray()
{
    $arr = $this->toArray();
    $ret = [];
    foreach ($arr as $key => $value) {
        $ret[] = [
            'value' => $key,
            'label' => $value
        ];
    }
    return $ret;
}

/*
 * Get options in "key-value" format
 * @return array
 */
public function toArray()
{
    $choose = [
        'TEST' => 'Test',
        'LIVE' => 'Live',
       

    ];
    return $choose;
}

}
?>