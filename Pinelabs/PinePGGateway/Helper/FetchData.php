<?php

namespace Pinelabs\PinePGGateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class FetchData extends AbstractHelper{
    protected $resourceConnection;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ){
        $this->resourceConnection = $resourceConnection;
    }

    public function fetchPinelabDetails($pincode) {

        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');
        $this->logger = new \Zend_Log();
        $this->logger->addWriter($writer);

		$this->logger->info(__LINE__ . ' | '.__FUNCTION__); 

		$connection = $this->resourceConnection->getConnection();
		$tableName = $this->resourceConnection->getTableName('postcode_ccavenue'); 
		$query = "SELECT pinelabs_sub_id FROM $tableName WHERE postcode='".$pincode."' and postcode_status='1' ";
		$result = $connection->fetchAll($query);

		if(count($result) > 0){
			$tableName = $this->resourceConnection->getTableName('pinelabs_merchant_data'); 
			$query = "SELECT * FROM $tableName WHERE pinelabs_sub_id='".$result[0]['pinelabs_sub_id']."' and status='1' ";
			$result = $connection->fetchAll($query);
		}

		$this->logger->info(__LINE__ . ' | '.__FUNCTION__. '-- '. json_encode($result));
		
		return $result;
	}

}