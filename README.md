# magento-2-graphql

This module adds graphql support to [Ivy Magento 2 module](https://packagist.org/packages/getivy/magento-2).

### Compatibility

Magento 2.3.x
Magento 2.4.x

### Installation

You can install this module simply with composer.

```bash
composer require getivy/magento-2-graphql
php bin/magento module:enable Esparksinc_IvyPaymentGraphql
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```
