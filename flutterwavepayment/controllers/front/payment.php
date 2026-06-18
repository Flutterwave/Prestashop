<?php
/**
 * Payment Controller - Initiates Flutterwave Checkout
 *
 * @author    Flutterwave Developers
 * @copyright 2024 Flutterwave Developers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/FlutterwaveApiClient.php';

class FlutterwavePaymentPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_column_left = false;

    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;

        if (!$this->module->checkCurrency($cart)) {
            Tools::redirect('index.php?controller=order');
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order');
        }

        // Get cart total
        $total = $cart->getOrderTotal(true, Cart::BOTH);
        $currency = new Currency($cart->id_currency);

        // Generate unique reference
        $reference = $this->generateReference($cart->id);

        // Prepare customer data
        $customerData = [
            'email' => $customer->email,
            'name' => $customer->firstname . ' ' . $customer->lastname,
            'phone' => !empty($customer->phone_mobile) ? $customer->phone_mobile : $customer->phone,
        ];

        // Prepare callback and return URLs
        $callbackUrl = $this->context->link->getModuleLink(
            $this->module->name,
            'validation',
            [],
            true
        );

        $returnUrl = $this->context->link->getModuleLink(
            $this->module->name,
            'validation',
            ['action' => 'return'],
            true
        );

        // Get delivery address for metadata
        $address = new Address($cart->id_address_delivery);

        // Prepare checkout data
        $checkoutData = [
            'amount' => $total,
            'currency' => $currency->iso_code,
            'reference' => $reference,
            'customer' => $customerData,
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'metadata' => [
                'cart_id' => (string) $cart->id,
                'customer_id' => (string) $customer->id,
                'shop_name' => Configuration::get('PS_SHOP_NAME'),
            ],
        ];

        try {
            // Initialize API client
            $apiClient = new FlutterwaveApiClient(
                $this->module->getApiUrl(),
                $this->module->getSecretKey(),
                $this->module->getPublicKey()
            );

            // Initiate checkout
            $response = $apiClient->initiateCheckout($checkoutData);

            // Store transaction reference in cart
            $this->context->cookie->__set('flutterwave_reference_' . $cart->id, $reference);
            $this->context->cookie->write();

            // Check if we got a checkout URL
            if (isset($response['data']['link']) && !empty($response['data']['link'])) {
                Tools::redirect($response['data']['link']);
            } elseif (isset($response['link']) && !empty($response['link'])) {
                Tools::redirect($response['link']);
            } else {
                throw new Exception('No checkout URL received from Flutterwave API');
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Flutterwave Payment Error: ' . $e->getMessage(),
                3,
                null,
                'Cart',
                $cart->id,
                true
            );

            $this->errors[] = $this->module->l('Payment initialization failed. Please try again or contact support. :'. $e->getMessage());
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }
    }

    /**
     * Generate unique reference for transaction
     * 
     * @param int $cartId Cart ID
     * @return string Unique reference
     */
    private function generateReference($cartId)
    {
        $random = '';
        if (function_exists('random_bytes')) {
            $random = bin2hex(random_bytes(4));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $random = bin2hex(openssl_random_pseudo_bytes(4));
        } else {
            // Fallback for older PHP versions
            $random = substr(md5(uniqid(mt_rand(), true)), 0, 8);
        }
        
        return FlutterwavePayment::REFERENCE_PREFIX . $cartId . '_' . time() . '_' . $random;
    }
}
