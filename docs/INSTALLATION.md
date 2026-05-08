# Installation Guide - Flutterwave PrestaShop Payment Module

This guide provides detailed step-by-step instructions for installing and configuring the Flutterwave Payment module for PrestaShop.

## Prerequisites

Before installing the module, ensure you have:

1. **PrestaShop Installation**
   - PrestaShop 1.7.0 or higher installed and running
   - Access to PrestaShop admin panel
   - Access to server file system (FTP/SFTP or direct access)

2. **Server Requirements**
   - PHP 7.1 or higher
   - cURL extension enabled
   - JSON extension enabled
   - SSL certificate installed (HTTPS required for production)

3. **Flutterwave Account**
   - Active Flutterwave merchant account
   - API credentials (Public Key and Secret Key)
   - Access to Flutterwave Dashboard

## Installation Methods

### Method 1: Manual Installation (Recommended)

1. **Download the Module**
   ```bash
   git clone https://github.com/flutterwave/prestashop.git
   # or download as ZIP from GitHub
   ```

2. **Upload to PrestaShop**
   - Connect to your server via FTP/SFTP or file manager
   - Navigate to your PrestaShop installation directory
   - Go to the `modules` folder
   - Upload the entire `flutterwavepayment` folder to the `modules` directory
   - Final path should be: `/path/to/prestashop/modules/flutterwavepayment/`

3. **Set Correct Permissions**
   ```bash
   cd /path/to/prestashop/modules/
   chmod -R 755 flutterwavepayment
   ```

4. **Install via Admin Panel**
   - Log in to your PrestaShop admin panel
   - Navigate to **Modules > Module Manager**
   - In the search box, type "Flutterwave Payment"
   - Click the **Install** button
   - Wait for installation to complete

### Method 2: Upload ZIP File

1. **Create ZIP Archive**
   - Create a ZIP file containing the `flutterwavepayment` folder
   - Ensure the folder structure is preserved
   - ZIP should contain: `flutterwavepayment/flutterwavepayment.php`, etc.

2. **Upload via Admin Panel**
   - Log in to PrestaShop admin panel
   - Navigate to **Modules > Module Manager**
   - Click **Upload a module** button (top right)
   - Select your ZIP file
   - Click **Upload this module**
   - Wait for automatic installation

3. **Verify Installation**
   - Search for "Flutterwave Payment" in Module Manager
   - Status should show as "Installed"

## Configuration

### Step 1: Get Your Flutterwave API Credentials

1. Log in to [Flutterwave Dashboard](https://app.flutterwave.com)
2. Navigate to **Settings > API Keys** or **Developers > API Keys**
3. You'll see two sets of keys:
   - **Test/Sandbox Keys**: For testing
   - **Live/Production Keys**: For real transactions

4. Copy the following:
   - Public Key (starts with `FLWPUBK_TEST-` or `FLWPUBK-`)
   - Secret Key (starts with `FLWSECK_TEST-` or `FLWSECK-`)

### Step 2: Configure Webhook (Recommended)

1. In Flutterwave Dashboard, go to **Settings > Webhooks**
2. Click **Add Webhook** or **Create Webhook**
3. Enter the webhook URL:
   ```
   https://yourdomain.com/module/flutterwavepayment/webhook
   ```
   Replace `yourdomain.com` with your actual domain

4. Select events to listen to:
   - `payment.success`
   - `payment.failed`
   - (Select all payment-related events)

5. Save the webhook
6. Copy the **Webhook Secret** (used for signature verification)

### Step 3: Configure the Module

1. In PrestaShop admin, navigate to **Modules > Module Manager**
2. Search for "Flutterwave Payment"
3. Click **Configure** button
4. Fill in the configuration form:

   **For Testing (Sandbox Mode):**
   - **Live Mode**: Set to **Disabled** (Off)
   - **Public Key**: Enter your test public key (`FLWPUBK_TEST-...`)
   - **Secret Key**: Enter your test secret key (`FLWSECK_TEST-...`)
   - **Webhook Secret**: Enter your webhook secret (optional)

   **For Production (Live Mode):**
   - **Live Mode**: Set to **Enabled** (On)
   - **Public Key**: Enter your live public key (`FLWPUBK-...`)
   - **Secret Key**: Enter your live secret key (`FLWSECK-...`)
   - **Webhook Secret**: Enter your webhook secret (recommended)

5. Click **Save**
6. Verify success message appears

## Verification

### Test the Installation

1. **Check Module Status**
   - Module should appear in Module Manager
   - Status: Installed and Enabled
   - Green indicator next to module name

2. **Check Payment Method**
   - Go to your store frontend
   - Add a product to cart
   - Proceed to checkout
   - "Pay with Flutterwave" should appear as payment option

3. **Test Transaction (Sandbox)**
   - Complete a test purchase using sandbox mode
   - Should redirect to Flutterwave payment page
   - Complete payment with test card details
   - Should return to store with order confirmation
   - Check admin panel for new order

### Common Installation Issues

#### Module Not Appearing
- Clear PrestaShop cache: **Advanced Parameters > Performance > Clear cache**
- Check file permissions (should be 755 for directories, 644 for files)
- Verify folder structure is correct

#### Installation Fails
- Check PHP error logs
- Verify PHP version (7.1+)
- Ensure cURL extension is enabled
- Check file ownership (should match web server user)

#### Payment Method Not Showing
- Verify module is enabled
- Check currency is supported
- Clear cache
- Check shop currency configuration

## Post-Installation

### Test Mode Testing

1. **Configure for Test Mode**
   - Set Live Mode to Disabled
   - Use sandbox API keys
   - Save configuration

2. **Test Payment Flow**
   - Make test purchase
   - Use Flutterwave test cards
   - Verify order creation
   - Check webhook logs

3. **Test Webhook**
   - Trigger test webhook from Flutterwave Dashboard
   - Check PrestaShop logs for webhook receipt
   - Verify order status updates

### Go Live

1. **Complete Testing**
   - Test all payment scenarios
   - Verify order creation
   - Test refunds (if applicable)
   - Verify email notifications

2. **Switch to Live Mode**
   - In module configuration:
     - Set Live Mode to Enabled
     - Replace with live API keys
     - Update webhook secret if different
   - Save configuration

3. **Verify Live Setup**
   - Make a small real transaction
   - Verify payment processing
   - Check order creation
   - Confirm webhook delivery

4. **Monitor**
   - Check PrestaShop logs regularly
   - Monitor Flutterwave Dashboard for transactions
   - Verify webhook deliveries

## Webhook URL

Your webhook URL will be:
```
https://yourdomain.com/module/flutterwavepayment/webhook
```

**Important Notes:**
- Must be HTTPS in production
- Must be publicly accessible
- Should not require authentication
- Should return HTTP 200 on success

## File Locations

After installation, files will be located at:

```
/path/to/prestashop/
└── modules/
    └── flutterwavepayment/
        ├── flutterwavepayment.php          # Main module file
        ├── config.xml                 # Module configuration
        ├── logo.png                   # Module logo
        ├── classes/
        │   └── FlutterwaveApiClient.php    # API client
        ├── controllers/
        │   └── front/
        │       ├── payment.php        # Payment controller
        │       ├── validation.php     # Validation controller
        │       └── webhook.php        # Webhook controller
        └── views/
            └── templates/
                └── hook/
                    ├── payment_infos.tpl
                    └── payment_return.tpl
```

## Logs

PrestaShop logs can be found at:
- PrestaShop 1.7: `var/logs/`
- PrestaShop 1.6: `log/`

Errors and events are logged with prefix "Flutterwave Payment"

## Support

If you encounter issues during installation:

1. **Check Documentation**
   - Review this installation guide
   - Read the main README.md
   - Check Flutterwave API documentation

2. **Check Logs**
   - PrestaShop error logs
   - Server error logs
   - Browser console for frontend errors

3. **Contact Support**
   - Flutterwave Support: hi@flutterwavego.com
   - Flutterwave Documentation: https://developer.flutterwave.com
   - GitHub Issues: https://github.com/flutterwave/prestashop/issues

## Next Steps

After successful installation:

1. Review [Best Practices](https://developer.flutterwave.com/v3.0.0/docs/best-practices)
2. Set up [Webhook Monitoring](https://developer.flutterwave.com/v3.0.0/docs/webhooks)
3. Test thoroughly before going live
4. Monitor transactions in Flutterwave Dashboard
5. Keep module updated

## Uninstallation

To uninstall the module:

1. Go to **Modules > Module Manager**
2. Search for "Flutterwave Payment"
3. Click **Uninstall** button
4. Confirm uninstallation
5. Optionally delete files from `modules/flutterwavepayment/` folder

**Note**: Uninstalling will remove configuration but not affect existing orders.