<?php
/**
 * Validation Controller - Verifies Flutterwave Payment
 *
 * @author    Flutterwave Developers
 * @copyright 2024 Flutterwave Developers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use FlutterwavePayment\classes\FlutterwaveApiClient;

require_once dirname(__FILE__) . '/../../classes/FlutterwaveApiClient.php';

class FlutterwavePaymentValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check if this is a return from payment page
        $action = Tools::getValue('status');
        $reference = Tools::getValue('reference');

        if ($action === 'cancelled') {
            $this->errors[] = $this->module->l('Payment was cancelled.');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }


        if (empty($reference)) {
            // Try to get reference from cookie
            $reference = $this->context->cookie->__get('flutterwave_reference_' . $cart->id);
        }

        if (empty($reference)) {
            $this->errors[] = $this->module->l('Payment reference not found.');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
            return;
        }

        try {
            // Initialize API client
            $apiClient = new FlutterwaveApiClient(
                $this->module->getApiUrl(),
                $this->module->getSecretKey(),
                $this->module->getPublicKey()
            );

            // Verify transaction
            $response = $apiClient->verifyTransaction($reference);

            // Extract transaction data
            $transactionData = isset($response['data']) ? $response['data'] : $response;
            $status = isset($transactionData['status']) ? $transactionData['status'] : null;
            $amount = isset($transactionData['amount']) ? $transactionData['amount'] : 0;

            PrestaShopLogger::addLog(
                'Flutterwave Response: ' . json_encode($response),
                3,
                null,
                'Validation',
                $cart->id,
                true
            );


            // Check transaction status
            if ($status === 'successful' || $status === 'success' || $status === 'completed') {
                // Verify amount matches cart total
                $cartTotal = $cart->getOrderTotal(true, Cart::BOTH);

                if (abs($amount - $cartTotal) > FlutterwavePayment::AMOUNT_TOLERANCE) {
                    throw new Exception('Payment amount mismatch');
                }

                // Check if the currency matches                
                $currency = new Currency($cart->id_currency);
                if (isset($transactionData['currency']) && $transactionData['currency'] !== $currency->iso_code) {
                    throw new Exception('Payment currency mismatch');
                }

                // Check if order already exists for this cart
                $orderId = Order::getIdByCartId($cart->id);

                if ($orderId) {
                    // Order already created, redirect to confirmation
                    Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
                    return;
                }

                // Create order
                $currency = new Currency($cart->id_currency);
                $this->module->validateOrder(
                    (int) $cart->id,
                    Configuration::get('PS_OS_PAYMENT'),
                    $amount,
                    $this->module->displayName,
                    null,
                    ['transaction_id' => $reference],
                    (int) $currency->id,
                    false,
                    $customer->secure_key
                );

                $orderId = $this->module->currentOrder;

                Db::getInstance()->insert(
                    'flutterwave_transaction',
                    [
                        'id_order' => (int)$orderId,
                        'flutterwave_transaction_id' => pSQL((string)$transactionData['id']),
                        'flutterwave_reference' => pSQL($reference),
                        'amount' => (float)$amount,
                        'currency' => pSQL($currency->iso_code),
                        'status' => pSQL($status),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]
                );

                // Clear cookie
                $this->context->cookie->__unset('flutterwave_reference_' . $cart->id);
                $this->context->cookie->write();

                // Redirect to order confirmation
                Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
            } elseif ($status === 'pending' || $status === 'processing') {
                // Payment is still pending
                $this->warnings[] = $this->module->l('Your payment is being processed. Please wait...');
                $this->redirectWithNotifications('index.php?controller=order&step=1');
            } else {
                // Payment failed or cancelled
                $errorMessage = isset($transactionData['message']) ? $transactionData['message'] : 'Payment failed';
                throw new Exception($errorMessage);
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Flutterwave Payment Validation Error: ' . $e->getMessage(),
                3,
                null,
                'Validation',
                1,
                true
            );

            if ($action === 'failed') {
                $this->errors[] = $this->module->l('Payment failed. Please try again or contact support.');
            } else {
                $this->errors[] = $this->module->l('Payment verification failed. Please try again or contact support.');
            }
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }
    }
}
