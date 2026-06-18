# Changelog

All notable changes to the Flutterwave PrestaShop Payment Module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-08

### Added
- Initial release of Flutterwave Payment module for PrestaShop
- Payment initiation via Flutterwave Checkout API
- Transaction verification endpoint integration
- Webhook support for real-time payment status updates
- Configuration interface for API credentials
- Support for test (sandbox) and live (production) modes
- Automatic order creation upon successful payment
- Comprehensive error handling and logging
- Webhook signature verification for security
- Support for multiple currencies
- Payment status synchronization
- Module installation and uninstallation hooks
- Security index.php files in all directories
- Complete module documentation
- PrestaShop 1.7+ compatibility

### Features
- **Initiate Checkout**: Creates payment sessions and redirects customers to Flutterwave secure payment page
- **Verify Transaction**: Validates payment status after customer returns from payment page
- **Webhooks**: Processes real-time payment notifications from Flutterwave
- **Configuration**: Easy-to-use admin interface for API credential management
- **Error Handling**: Comprehensive error handling following Flutterwave API best practices
- **Logging**: Detailed logging of all payment operations for debugging
- **Security**: Bearer token authentication, webhook signature verification, HTTPS enforcement

### API Endpoints Integrated
- `POST /v3/payments` - Initiate checkout session
- `GET /v3/transactions/verify_by_reference` - Verify transaction status

### Documentation
- Complete README with installation instructions
- Module-specific documentation
- API integration documentation
- Webhook setup guide
- Troubleshooting guide

[1.0.0]: https://github.com/flutterwave/prestashop/releases/tag/v1.0.0