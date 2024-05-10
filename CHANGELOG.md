# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.12.0] 2024-05-10

### Added

- Support for the new 25.5 % VAT (and future VAT rates with decimals)
- End-to-end tests for orders with different VAT percentages
- End-to-end tests for checking that invoice amounts match order amounts

### Changed

- Coupon discounts are now added per VAT rate when order includes products with various VAT rates
- The product price of an order line item is now read directly from the item meta, not from the product database
