# PInelabs Edge

## This is a Magneto2 Extension

> ![Magneto2](https://github.com/MCSAM-pvt/plural-magento/blob/master/Images/magento.svg)

# How to install

# Create folder inside the 
/var/log/PinePG and giv write permission to the folder

$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/PinePG.log');

 ### Manual Install

```sh
    # goto root folder of your magento installation
    cd app/code
    wget {{ extension url }}
    tar -xf {{ Extension file }}

    # cd back into root folder
    php bin/magento module:enable Pinelabs_PinePGGateway
    php bin/magento setup:upgrade
    php bin/magento setup:di:compile
    php bin/magento setup:static-content:deploy -f
```

# Supported Magento Version

 - 2.4.5-p1

# Features

 - Pinelabs Edge [read more](https://www.pinelabs.com/developer/apis) integration to magento 2.
 
# How To Configure

 1. Login Into Admin Panel.
 2. Goto Stores Configuration.
 3. Goto payment Methods Tab inside Sales Section.
 4. Scroll down and locate Pinelabs Edge.
 5. Fill your details Merchant Id, Merchant Secret, Merchant Access Code.

# Support / Help


# graphql implementation

- Endpoint 
-- https://domain.com/graphql

```
mutation createPineOrder {
  placePineOrder(order_id:"000000005"){
    success,
    action,
    ppc_Amount,
    ppc_CustomerAddressPIN,
    ppc_CustomerCity,
    ppc_CustomerCountry,
    ppc_CustomerEmail,
    ppc_CustomerFirstName,
    ppc_CustomerLastName,
    ppc_CustomerMobile,
    ppc_CustomerState,
    ppc_LPC_SEQ,
    ppc_MerchantAccessCode,
    ppc_MerchantID,
    ppc_MerchantProductInfo,
    ppc_MerchantReturnURL,
    ppc_MultiCartProductDetails,
    ppc_NavigationMode,
    ppc_PayModeOnLandingPage,
    ppc_Product_Code,
    ppc_ShippingCity,
    ppc_ShippingCountry,
    ppc_ShippingFirstName,
    ppc_ShippingLastName,
    ppc_ShippingPhoneNumer,
    ppc_ShippingState,
    ppc_ShippingZipCode,
    ppc_TransactionType,
    ppc_UdfField1,
    ppc_UdfField2,
    ppc_UniqueMerchantTxnID,
    ppc_DIA_SECRET_TYPE,
    ppc_DIA_SECRET
  }
}
```

# response

```
{
  "data": {
    "placePineOrder": {
      "success": true,
      "action": "https://uat.pinepg.in/PinePGRedirect/",
      "ppc_Amount": 8300,
      "ppc_CustomerAddressPIN": "122101",
      "ppc_CustomerCity": "test",
      "ppc_CustomerCountry": "India",
      "ppc_CustomerEmail": "rahul@yopmail.com",
      "ppc_CustomerFirstName": "rahul",
      "ppc_CustomerLastName": "singh",
      "ppc_CustomerMobile": "8987898789",
      "ppc_CustomerState": "Kerala",
      "ppc_LPC_SEQ": "1",
      "ppc_MerchantAccessCode": "4a39a6d4-46b7-474d-929d-21bf0e9ed607",
      "ppc_MerchantID": "106598",
      "ppc_MerchantProductInfo": "Bellona Skirt",
      "ppc_MerchantReturnURL": "http://localhost/pinepg/standard/response/",
      "ppc_MultiCartProductDetails": "W3sicHJvZHVjdF9jb2RlIjoiVlNLMDEtTEEtTSIsInByb2R1Y3RfYW1vdW50Ijo3ODAwfV0=",
      "ppc_NavigationMode": "2",
      "ppc_PayModeOnLandingPage": "1,4",
      "ppc_Product_Code": "VSK01-LA-M",
      "ppc_ShippingCity": "test",
      "ppc_ShippingCountry": "India",
      "ppc_ShippingFirstName": "rahul",
      "ppc_ShippingLastName": "singh",
      "ppc_ShippingPhoneNumer": "8987898789",
      "ppc_ShippingState": "Kerala",
      "ppc_ShippingZipCode": "122101",
      "ppc_TransactionType": "1",
      "ppc_UdfField1": "Magento_2.4.5-p1",
      "ppc_UdfField2": "",
      "ppc_UniqueMerchantTxnID": "63ca9076b534c_000000005",
      "ppc_DIA_SECRET_TYPE": "SHA256",
      "ppc_DIA_SECRET": "AA2DC54BF7041A32C24E05FE132C853461B1CAF983174C5EBC59F18263105BE2"
    }
  }
}
```


## EMI Offers Graphql 

#### Query 
```
query {
  emiOfferCalculator( 
    productDetails: [
      {
        productCode: "testproduct01"
        productAmount: 1000000
      }
      {
        productCode: "testproduct02"
        productAmount: 1000000
      }
    ]
  ) {
      issuer {
          list_emi_tenure {
        offer_scheme {
          product_details {
            schemes
            product_code
            product_amount
            subvention_cashback_discount
            product_discount
            subvention_cashback_discount_percentage
            product_discount_percentage
            subvention_type
            bank_interest_rate_percentage
            bank_interest_rate
          }
          emi_scheme {
            scheme_id
            program_type
            is_scheme_valid
          }
        }
        tenure_id
        tenure_in_month
        monthly_installment
        bank_interest_rate
        interest_pay_to_bank
        total_offerred_discount_cashback_amount
        loan_amount
        auth_amount
      }
      issuer_name
      is_debit_emi_issuer 
      }
      response_message
  }
}
```

###  EMI offers Graphql response
```
{
    "data": {
        "emiOfferCalculator": {
            "issuer": [
                {
                    "list_emi_tenure": [
                        {
                            "offer_scheme": {
                                "product_details": [
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct01",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 150000,
                                        "bank_interest_rate": 25103
                                    },
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct02",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 150000,
                                        "bank_interest_rate": 25103
                                    }
                                ],
                                "emi_scheme": {
                                    "scheme_id": 47693,
                                    "program_type": 105,
                                    "is_scheme_valid": true
                                }
                            },
                            "tenure_id": "3",
                            "tenure_in_month": "3",
                            "monthly_installment": 683402,
                            "bank_interest_rate": 150000,
                            "interest_pay_to_bank": 50206,
                            "total_offerred_discount_cashback_amount": 0,
                            "loan_amount": 2000000,
                            "auth_amount": 2000000
                        },
                        {
                            "offer_scheme": {
                                "product_details": [
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct01",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 150000,
                                        "bank_interest_rate": 44198
                                    },
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct02",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 150000,
                                        "bank_interest_rate": 44198
                                    }
                                ],
                                "emi_scheme": {
                                    "scheme_id": 47693,
                                    "program_type": 105,
                                    "is_scheme_valid": true
                                }
                            },
                            "tenure_id": "6",
                            "tenure_in_month": "6",
                            "monthly_installment": 348067,
                            "bank_interest_rate": 150000,
                            "interest_pay_to_bank": 88402,
                            "total_offerred_discount_cashback_amount": 0,
                            "loan_amount": 2000000,
                            "auth_amount": 2000000
                        },
                        {
                            "offer_scheme": {
                                "product_details": [
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct01",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": null,
                                        "bank_interest_rate_percentage": 0,
                                        "bank_interest_rate": 0
                                    },
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct02",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": null,
                                        "bank_interest_rate_percentage": 0,
                                        "bank_interest_rate": 0
                                    }
                                ],
                                "emi_scheme": {
                                    "scheme_id": 47693,
                                    "program_type": 105,
                                    "is_scheme_valid": true
                                }
                            },
                            "tenure_id": "96",
                            "tenure_in_month": "1",
                            "monthly_installment": 0,
                            "bank_interest_rate": 0,
                            "interest_pay_to_bank": 0,
                            "total_offerred_discount_cashback_amount": 0,
                            "loan_amount": 2000000,
                            "auth_amount": 2000000
                        }
                    ],
                    "issuer_name": "HDFC",
                    "is_debit_emi_issuer": false
                },
                {
                    "list_emi_tenure": [
                        {
                            "offer_scheme": {
                                "product_details": [
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct01",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 149900,
                                        "bank_interest_rate": 25085
                                    },
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct02",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 149900,
                                        "bank_interest_rate": 25085
                                    }
                                ],
                                "emi_scheme": {
                                    "scheme_id": 47823,
                                    "program_type": 105,
                                    "is_scheme_valid": true
                                }
                            },
                            "tenure_id": "3",
                            "tenure_in_month": "3",
                            "monthly_installment": 683390,
                            "bank_interest_rate": 149900,
                            "interest_pay_to_bank": 50170,
                            "total_offerred_discount_cashback_amount": 0,
                            "loan_amount": 2000000,
                            "auth_amount": 2000000
                        },
                        {
                            "offer_scheme": {
                                "product_details": [
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct01",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 149900,
                                        "bank_interest_rate": 44168
                                    },
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct02",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": 3,
                                        "bank_interest_rate_percentage": 149900,
                                        "bank_interest_rate": 44168
                                    }
                                ],
                                "emi_scheme": {
                                    "scheme_id": 47823,
                                    "program_type": 105,
                                    "is_scheme_valid": true
                                }
                            },
                            "tenure_id": "6",
                            "tenure_in_month": "6",
                            "monthly_installment": 348056,
                            "bank_interest_rate": 149900,
                            "interest_pay_to_bank": 88336,
                            "total_offerred_discount_cashback_amount": 0,
                            "loan_amount": 2000000,
                            "auth_amount": 2000000
                        },
                        {
                            "offer_scheme": {
                                "product_details": [
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct01",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": null,
                                        "bank_interest_rate_percentage": 0,
                                        "bank_interest_rate": 0
                                    },
                                    {
                                        "schemes": [],
                                        "product_code": "testproduct02",
                                        "product_amount": 1000000,
                                        "subvention_cashback_discount": 0,
                                        "product_discount": 0,
                                        "subvention_cashback_discount_percentage": 0,
                                        "product_discount_percentage": 0,
                                        "subvention_type": null,
                                        "bank_interest_rate_percentage": 0,
                                        "bank_interest_rate": 0
                                    }
                                ],
                                "emi_scheme": {
                                    "scheme_id": 47823,
                                    "program_type": 105,
                                    "is_scheme_valid": true
                                }
                            },
                            "tenure_id": "96",
                            "tenure_in_month": "1",
                            "monthly_installment": 0,
                            "bank_interest_rate": 0,
                            "interest_pay_to_bank": 0,
                            "total_offerred_discount_cashback_amount": 0,
                            "loan_amount": 2000000,
                            "auth_amount": 2000000
                        }
                    ],
                    "issuer_name": "ICICI",
                    "is_debit_emi_issuer": false
                }
            ],
            "response_message": "SUCCESS"
        }
    }
}
```