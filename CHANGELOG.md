# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Coupons starting with an underscore are not added as a separate discount row
- The filter `laskuhari_invoice_row_payload` now takes in more arguments (`?WC_Order_Item $item, int $order_id, string $type`) so invoice row information can be changed based on order details and row type
- The filter `laskuhari_invoice_surcharge` now takes in more arguments (`float $order_subtotal, string $send_method, ?WC_Cart $cart, ?WC_Order $order, bool $includes_tax`) so that changes to the invoicing surcharge can be made based on order/cart details

### Added

- Nonce checking for invoice creation and sending actions to prevent accidental double invoicing

### Removed

- Removed the filter `laskuhari_disable_invoice_surcharge`. The same behavior can be achieved now through `laskuhari_invoice_surcharge` by setting the surcharge to zero.

## [1.13.0] 2025-02-05

### Changed

- When WP Cron is disabled with `DISABLE_WP_CRON`, the plugin will now call `/wp-cron.php` asynchronously when there are Laskuhari jobs in queue so that invoices are processed in a timely manner.

### Added

- An option for disabling using WP Cron for delaying invoice generation at checkout (before always on)
- A warning when trying to send an invoice without address info
- Checks for einvoice address validity at checkout

## [1.12.3] 2024-08-16

### Fixed

- Fixed compatibility with PHP 7.2

### Added

- Added GitHub workflow to check for PHP compatibility automatically

## [1.12.2] 2024-08-15

### Fixed

- Improved error handling for Laskuhari API requests

### Changed

- Updated Finvoice operators list

## [1.12.1] 2024-05-20

### Fixed

- Unit price and discount was wrongly calculated from total row price, leading to incorrect values on invoice reows

## [1.12.0] 2024-05-10

### Added

- Support for the new 25.5 % VAT (and future VAT rates with decimals)
- End-to-end tests for orders with different VAT percentages
- End-to-end tests for checking that invoice amounts match order amounts

### Changed

- Coupon discounts are now added per VAT rate when order includes products with various VAT rates
- The product price of an order line item is now read directly from the item meta, not from the product database
