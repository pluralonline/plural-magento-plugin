<?php

namespace Pinelabs\PinePGGateway\Controller;

class PinePGVerify
{

    public static function verify($params,$PayEnvironment)
    { 
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG/' . date("Y-m-d") . '.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        $curl = curl_init();

        $verifyApiUrl = 'https://pinepg.in/api/PG/V2';

        if ($PayEnvironment == 'TEST')
        {
            $verifyApiUrl = 'https://uat.pinepg.in/api/PG/V2';
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => $verifyApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => "ppc_DIA_SECRET=" . $params['ppc_DIA_SECRET'] . "&ppc_DIA_SECRET_TYPE=" . $params['ppc_DIA_SECRET_TYPE'] . "&ppc_MerchantAccessCode=" . $params['ppc_MerchantAccessCode'] . "&ppc_MerchantID=" . $params['ppc_MerchantID'] . "&ppc_PinePGTransactionID=" . $params['ppc_PinePGTransactionID'] . "&ppc_TransactionType=" . $params['ppc_TransactionType'] . "&ppc_UniqueMerchantTxnID=" . $params['ppc_UniqueMerchantTxnID'],
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded'
            ),
        ));

        $response = curl_exec($curl);
        $logger->info('Enquiry Api response:' . $response);

        $response = json_decode($response);

        if (isset($response->ppc_TxnResponseMessage) && $response->ppc_TxnResponseMessage == "SUCCESS" && isset($response->ppc_TxnResponseCode) && $response->ppc_TxnResponseCode == 1 && $params['ppc_Amount'] == $response->ppc_Amount) {
            return true;
        }

        return false;
    }

    public static function Hex2String($hex){
        $string='';
        for ($i=0; $i < strlen($hex)-1; $i+=2){
            $string .= chr(hexdec($hex[$i].$hex[$i+1]));
        }
        return $string;
    }
}
