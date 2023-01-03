# magento-2-graphql

This module add graphql support to [Ivy Magento 2 module](https://packagist.org/packages/getivy/magento-2).

### Compatibility

Magento 2.3.x
Magento 2.4.x

### Installation

You can install this modile simply with composer. [Here](https://packagist.org/packages/getivy/magento-2-graphql) you can find the package.

```bash
composer config repositories.getivy/magento-2-graphql git git@github.com:getivy/magento-2-graphql.git
composer require getivy/magento-2-graphql
php bin/magento module:enable Esparksinc_IvyPaymentGraphql
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```
