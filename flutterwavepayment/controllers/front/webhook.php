<?php
/**
 * Webhook Controller - Handles Flutterwave Webhook Events
 *
 * @author    Flutterwave Developers
 * @copyright 2024 Flutterwave Developers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use FlutterwavePayment\classes\FlutterwaveApiClient;
use FlutterwavePayment\classes\FlutterwaveTransactionVerifier;

require_once dirname(__FILE__) . '/../../classes/FlutterwaveApiClient.php';
require_once dirname(__FILE__) . '/../../classes/FlutterwaveTransactionVerifier.php';


class FlutterwavePaymentWebhookModuleFrontController extends ModuleFrontController
{
    

    public function postProcess()
    {
        // Get raw POST data
        $payload = file_get_contents('php://input');
        
        if (empty($payload)) {
            http_response_code(400);
            die(json_encode(['status' => 'error', 'message' => 'Empty payload']));
        }

        if ( ! isset($_SERVER['HTTP_VERIF_HASH']) || empty($_SERVER['HTTP_VERIF_HASH'])) {
             PrestaShopLogger::addLog(
                'Flutterwave Webhook: Missing signature',
                3,
                null,
                'FlutterwavePayment',
                null,
                true
            );
            
            http_response_code(401);
            die(json_encode(['status' => 'error', 'message' => 'Invalid signature']));
        }

        $signature = $_SERVER['HTTP_VERIF_HASH'];
        $secret = $this->module->getWebhookSecret();

        // Only authenticates the sender; payment details are requeried below
        if (!FlutterwaveApiClient::verifyWebhookHash($signature, $secret)) {
            PrestaShopLogger::addLog(
                'Flutterwave Webhook: Invalid signature',
                3,
                null,
                'FlutterwavePayment',
                null,
                true
            );
            
            http_response_code(401);
            die(json_encode(['status' => 'error', 'message' => 'Invalid signature']));
        }

        // Parse webhook data
        $webhookData = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            die(json_encode(['status' => 'error', 'message' => 'Invalid JSON']));
        }

        // Log webhook received, without customer or card details
        PrestaShopLogger::addLog(
            'Flutterwave Webhook received: event=' . (isset($webhookData['event']) ? $webhookData['event'] : '')
            . ', tx_ref=' . (isset($webhookData['data']['tx_ref']) ? $webhookData['data']['tx_ref'] : ''),
            1,
            null,
            'FlutterwavePayment',
            null,
            true
        );

        try {
            // Extract the reference only; everything else in the body is untrusted
            $data = isset($webhookData['data']) ? $webhookData['data'] : $webhookData;
            $reference = isset($data['tx_ref']) ? (string) $data['tx_ref'] : null;

            if (empty($reference)) {
                throw new Exception('Reference not found in webhook data');
            }

            // Requery the transaction at the Flutterwave API
            $apiClient = new FlutterwaveApiClient(
                $this->module->getApiUrl(),
                $this->module->getSecretKey(),
                $this->module->getPublicKey()
            );

            $response = $apiClient->verifyTransaction($reference);
            $transactionData = isset($response['data']) ? $response['data'] : [];

            FlutterwaveTransactionVerifier::assertReference($transactionData, $reference);

            $status = isset($transactionData['status']) ? $transactionData['status'] : null;

            // Extract cart ID from the verified reference
            $cartId = FlutterwaveTransactionVerifier::cartIdFromReference($reference);

            if (empty($cartId)) {
                throw new Exception('Cart ID not found in webhook reference');
            }

            // Check if order already exists
            $orderId = Order::getOrderByCartId($cartId);
            
            if ($orderId) {
                $order = new Order($orderId);
                
                // Update order status based on the verified transaction
                if (FlutterwaveTransactionVerifier::isSuccessful($status)) {
                    $orderCurrency = new Currency($order->id_currency);
                    $transactionId = FlutterwaveTransactionVerifier::assertPaymentMatches(
                        $transactionData,
                        $order->total_paid,
                        $orderCurrency->iso_code
                    );

                    // The charge must not already be recorded against a different order
                    $linkedOrderId = (int) Db::getInstance()->getValue(
                        'SELECT id_order FROM `' . _DB_PREFIX_ . 'flutterwave_transaction`
                        WHERE flutterwave_transaction_id = "' . pSQL($transactionId) . '"
                        OR flutterwave_reference = "' . pSQL($reference) . '"',
                        false
                    );

                    if ($linkedOrderId && $linkedOrderId !== (int) $orderId) {
                        throw new Exception('Transaction ' . $transactionId . ' is not linked to order #' . $orderId);
                    }

                    // Payment successful - ensure order is marked as paid
                    if ($order->getCurrentState() != Configuration::get('PS_OS_PAYMENT')) {
                        $order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
                        
                        PrestaShopLogger::addLog(
                            'Flutterwave Webhook: Order #' . $orderId . ' marked as paid',
                            1,
                            null,
                            'Order',
                            $orderId,
                            true
                        );
                    }
                } elseif (FlutterwaveTransactionVerifier::isFailed($status)) {
                    // Payment failed
                    if ($order->getCurrentState() != Configuration::get('PS_OS_ERROR')) {
                        $order->setCurrentState(Configuration::get('PS_OS_ERROR'));
                        
                        PrestaShopLogger::addLog(
                            'Flutterwave Webhook: Order #' . $orderId . ' marked as failed',
                            2,
                            null,
                            'Order',
                            $orderId,
                            true
                        );
                    }
                }
            } else {
                // Transction does not belong to this store or is not create from this module, ignore
                PrestaShopLogger::addLog(
                    'Flutterwave Webhook: No order found for Cart ID ' . $cartId,
                    2,
                    null,
                    'FlutterwavePayment',
                    null,
                    true
                );

                http_response_code(200);
                die(json_encode(['status' => 'success', 'message' => 'No order found for this webhook, ignoring']));
            }

            // Return success response.
            http_response_code(200);
            die(json_encode(['status' => 'success', 'message' => 'Webhook processed']));
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Flutterwave Webhook Error: ' . $e->getMessage(),
                3,
                null,
                'FlutterwavePayment',
                null,
                true
            );
            
            http_response_code(500);
            die(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
        }
    }
}
