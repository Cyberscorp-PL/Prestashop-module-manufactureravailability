# Prestashop-module-manufactureravailability
Prestashop 1.7.x - 9.2.x - Mass add info about availability for product in specific manufacturer. 



# Manufacturer Availability

## What does this module do?

The module lets you manage, from one place, the product setting **"When
out of stock"** and the label **"Label when out of stock (and back order
allowed)"** for all products of a manufacturer.

-   Each manufacturer has its own switch, out-of-stock behaviour and
    availability label (in every language).
-   After saving, the values are written to all products of that
    manufacturer.
-   Switching a manufacturer off restores the shop default behaviour and
    clears the label in its products.
-   Products marked as exceptions are never changed by the module.
-   When a manufacturer is switched on, products that already have their
    own out-of-stock setting or label are automatically added to the
    exceptions.
-   With **"Do not change current setting"** the module only fills in
    the availability label and leaves the out-of-stock behaviour of the
    products untouched.
-   New or edited products of an active manufacturer receive the
    settings automatically.
-   The scan button adds to the exceptions every product that already
    has its own settings, so they are never overwritten.
-   Manufacturers without any product are highlighted in red.

## Version-dependent code

The module detects the PHP and PrestaShop version at runtime and
automatically selects the matching translation system, hooks and product
page handling. The PHP version you can use is limited by your PrestaShop
version.

## Compatibility

  Item         Supported
  ------------ ------------------
  PHP          8.0.0 - 8.5
  PrestaShop   1.7.0.0 - 9.2.99

The module also displays the currently detected PHP and PrestaShop
versions in its **Information** tab, together with a compatibility
status.

### Module version

**1.0.10**

## Translation

Module is translated to, EN, DE, PL, FR. The module utilizes PrestaShop's native translation system. It is possible to add translations for any language available in the store's administration panel.
