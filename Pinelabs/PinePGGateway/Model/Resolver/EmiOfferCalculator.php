<?php
declare (strict_types = 1);

namespace Pinelabs\PinePGGateway\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Magento\Quote\Api\CartManagementInterface;
use Pinelabs\PinePGGateway\Model\EmiOffer;

class EmiOfferCalculator implements ResolverInterface
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
     * @param EmiOffer $EmiOffer
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        GetCartForUser $getCartForUser,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        EmiOffer $EmiOffer,
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
        $this->EmiOffer       = $EmiOffer;
        
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $this->logger->info('graphQL: Get Product Details'. json_encode($args));

        if (empty($args['productDetails']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "productDetails" is missing');

            throw new GraphQlInputException(__('Required parameter "productDetails" is missing'));
        }

        try{ 
        
            $params = $this->EmiOffer->emiOfferCalculator($args);
            
            $this->logger->info('Fetch Product EMI Offers Details');
      
            return json_decode(json_encode($params),true);

        } catch (\Exception $e){
            $this->logger->critical('graphQL: Exception: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
