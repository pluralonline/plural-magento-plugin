<?php
declare (strict_types = 1);

namespace Pinelabs\PinePGGateway\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Quote\Api\CartManagementInterface;
use Pinelabs\PinePGGateway\Model\PinePGPaymentMethod;

class PlacePineOrder implements ResolverInterface
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Quote\Api\CartManagementInterface
     */
    protected $cartManagement;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    protected $_objectManager;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param GetCartForUser $getCartForUser
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param PinePGPaymentMethod $PinePGPaymentMethod
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        GetCartForUser $getCartForUser,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        PinePGPaymentMethod $PinePGPaymentMethod,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->scopeConfig    = $scopeConfig;
        $this->getCartForUser = $getCartForUser;
        $this->cartManagement = $cartManagement; 
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->order          = $order;
        $this->logger         = $logger;
        $this->PinePGPaymentMethod         = $PinePGPaymentMethod;
        
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $this->logger->info('graphQL: Creating PinePG Order');

        if (empty($args['order_id']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "order_id" is missing');

            throw new GraphQlInputException(__('Required parameter "order_id" is missing'));
        }

        try
        {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $order_id   = $args['order_id'];

            $this->logger->info('graphQL: Order ID: ' . $order_id);

            $order = $this->order->load($order_id, $this->order::INCREMENT_ID);
            $order_grand_total          = $order->getGrandTotal();
            $this->logger->info('graphql: order total amount'.json_encode($order_grand_total ));
            $this->logger->info('graphQL: Order details: ' . json_encode($order));
            $params = $this->PinePGPaymentMethod->buildCheckoutRequestByOrderId($order);
            
            $this->logger->info('-- All Param---'. json_encode($params));

            $responseContent = [
                'success'        => true,
                'message'        => 'Order Params created successfully'
            ];
            $order->setState('new')->setStatus('pending');
			$order->save();
            $responseContent = array_merge($params, $responseContent);
            $this->logger->info('Original ppc amount : '. $responseContent['ppc_Amount']);
            $this->logger->info('Int ppc amount : '. (int)$responseContent['ppc_Amount']);
            $this->logger->info('FloatVal ppc amount : '. floatval($responseContent['ppc_Amount']));
            $responseContent['ppc_Amount'] = floatval($responseContent['ppc_Amount']);
            //$responseContent['ppc_Amount'] = (int)$responseContent['ppc_Amount'];
            $responseContent['action'] = $this->PinePGPaymentMethod->getCgiUrl();
            return $responseContent;

        } catch (\Exception $e)
        {
            $this->logger->critical('graphQL: Exception: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
