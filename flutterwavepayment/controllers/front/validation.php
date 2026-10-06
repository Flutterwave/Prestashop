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
use FlutterwavePayment\classes\FlutterwaveSignozLogger;
use FlutterwavePayment\classes\FlutterwaveTransactionVerifier;

require_once dirname(__FILE__) . '/../../classes/FlutterwaveApiClient.php';
require_once dirname(__FILE__) . '/../../classes/FlutterwaveSignozLogger.php';
require_once dirname(__FILE__) . '/../../classes/FlutterwaveTransactionVerifier.php';

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

        if ($action === 'cancelled') {
            $this->errors[] = $this->module->l('Payment was cancelled.');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        // Only trust the reference minted for this cart by the payment controller.
        // Never accept a reference from the query string: it lets a paid reference
        // from another cart be replayed onto this one.
        $reference = (string) $this->context->cookie->__get('flutterwave_reference_' . $cart->id);

        if (!FlutterwaveTransactionVerifier::referenceBelongsToCart($reference, $cart->id)) {
            FlutterwaveSignozLogger::instance()->trackError(
                'CALLBACK_REJECTED',
                'Payment return could not be bound to a reference issued for this cart.'
            );
            $this->errors[] = $this->module->l('Payment reference not found.');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
            return;
        }

        $status = null;

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

            // Log only the fields needed to trace the payment, not customer or card details
            PrestaShopLogger::addLog(
                'Flutterwave verification for ' . $reference . ': status=' . $status
                . ', id=' . (isset($transactionData['id']) ? $transactionData['id'] : '')
                . ', amount=' . (isset($transactionData['amount']) ? $transactionData['amount'] : '')
                . ' ' . (isset($transactionData['currency']) ? $transactionData['currency'] : ''),
                1,
                null,
                'Cart',
                $cart->id,
                true
            );

            // The verified transaction must be the one minted for this cart
            FlutterwaveTransactionVerifier::assertReference($transactionData, $reference);

            // Check transaction status
            if (FlutterwaveTransactionVerifier::isSuccessful($status)) {
                // Verify amount and currency match the cart
                $currency = new Currency($cart->id_currency);
                $transactionId = FlutterwaveTransactionVerifier::assertPaymentMatches(
                    $transactionData,
                    $cart->getOrderTotal(true, Cart::BOTH),
                    $currency->iso_code
                );
                $amount = (float) $transactionData['amount'];

                // Check if order already exists for this cart
                $orderId = Order::getIdByCartId($cart->id);

                if ($orderId) {
                    // Order already created, redirect to confirmation
                    Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
                    return;
                }

                // Serialize order creation per transaction so concurrent requests
                // cannot both pass the duplicate check below.
                $db = Db::getInstance();
                $lockName = pSQL('flutterwave_tx_' . $transactionId);
                if (!(int) $db->getValue("SELECT GET_LOCK('" . $lockName . "', 10)", false)) {
                    throw new Exception('Could not acquire transaction lock');
                }

                try {
                    // Reject a transaction or reference that already produced an order
                    $existingOrderId = (int) $db->getValue(
                        'SELECT id_order FROM `' . _DB_PREFIX_ . 'flutterwave_transaction`
                        WHERE flutterwave_transaction_id = "' . pSQL($transactionId) . '"
                        OR flutterwave_reference = "' . pSQL($reference) . '"',
                        false
                    );

                    if ($existingOrderId) {
                        $existingOrder = new Order($existingOrderId);
                        if ((int) $existingOrder->id_cart === (int) $cart->id) {
                            // Same cart submitted twice, redirect to confirmation
                            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $existingOrderId . '&key=' . $customer->secure_key);
                            return;
                        }

                        throw new Exception('Transaction ' . $transactionId . ' already used for order #' . $existingOrderId);
                    }

                    // Create order
                    $this->module->validateOrder(
                        (int) $cart->id,
                        Configuration::get('PS_OS_PAYMENT'),
                        $amount,
                        $this->module->displayName,
                        null,
                        ['transaction_id' => $transactionId],
                        (int) $currency->id,
                        false,
                        $customer->secure_key
                    );

                    $orderId = $this->module->currentOrder;

                    $db->insert(
                        'flutterwave_transaction',
                        [
                            'id_order' => (int) $orderId,
                            'flutterwave_transaction_id' => pSQL($transactionId),
                            'flutterwave_reference' => pSQL($reference),
                            'amount' => (float) $amount,
                            'currency' => pSQL($currency->iso_code),
                            'status' => pSQL($status),
                            'created_at' => date('Y-m-d H:i:s'),
                        ]
                    );
                } finally {
                    $db->getValue("SELECT RELEASE_LOCK('" . $lockName . "')", false);
                }

                $signoz = FlutterwaveSignozLogger::instance();
                if ($signoz->getCurrentEnvironment() === 'production') {
                    $signoz->trackTransaction(
                        $reference,
                        $currency->iso_code,
                        $amount,
                        isset($transactionData['payment_type']) ? (string) $transactionData['payment_type'] : 'card',
                        isset($transactionData['app_fee']) ? (float) $transactionData['app_fee'] : 0.0
                    );
                }

                // Clear cookie
                $this->context->cookie->__unset('flutterwave_reference_' . $cart->id);
                $this->context->cookie->write();

                // Redirect to order confirmation
                Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
            } elseif (FlutterwaveTransactionVerifier::isPending($status)) {
                // Payment is still pending
                $this->warnings[] = $this->module->l('Your payment is being processed. Please wait...');
                $this->redirectWithNotifications('index.php?controller=order&step=1');
            } else {
                // Payment failed or cancelled
                $errorMessage = isset($transactionData['message']) ? $transactionData['message'] : 'Payment failed';
                throw new Exception($errorMessage);
            }
        } catch (Exception $e) {
            FlutterwaveSignozLogger::instance()->trackError(
                FlutterwaveTransactionVerifier::isFailed($status) ? 'PAYMENT_FAILED' : 'PAYMENT_VERIFICATION_FAILED',
                $e->getMessage(),
                $reference
            );

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
