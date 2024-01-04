# PInelabs Edge 2.3.4v

## This is a Magneto2 Extension

> ![Magneto2](https://github.com/MCSAM-pvt/plural-magento/blob/master/Images/magento.svg)

# How to install

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

 - 2.3.4

# Features

 - Pinelabs Edge [read more](https://www.pinelabs.com/developer/apis) integration to magento 2.
 
# How To Configure

 1. Login Into Admin Panel.
 2. Goto Stores Configuration.
 3. Goto payment Methods Tab inside Sales Section.
 4. Scroll down and locate Pinelabs Edge.
 5. Fill your details Merchant Id, Merchant Secret, Merchant Access Code.

# Support / Help
