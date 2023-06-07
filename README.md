# Shopware 6: Stock Updater fix

Issue: https://issues.shopware.com/issues/NEXT-12479

By default, Shopware ties the "available stock" field on product level to the status of the order. If a client uses an external system for managing stock (e.g. ERP, inventory management system, ..), it can happen that the available stock will be reduced twice.

This plugin replaces the default "StockUpdater" and removes all ties to the order status.

**Important:** We've tested it only on Shopware 6.4 and for the use cases of our clients. Please test thoroughly before deploying it to production.

## Installation

The recommended way is using [composer](http://getcomposer.org):

```bash
$ composer require semaio/shopware-plugin-stock-updater-fix
```

## Support

If you encounter any problems or bugs, please create an issue on [GitHub](https://github.com/semaio/shopware-plugin-stock-updater-fix/issues).

## Contribution

Any contribution to the development of `shopware-plugin-stock-updater-fix` is highly welcome. The best possibility to provide any code is to open a [pull request on GitHub](https://help.github.com/articles/using-pull-requests).

## License

[MIT License](https://opensource.org/licenses/mit). See the [LICENSE.txt](LICENSE.txt) file for more details.
