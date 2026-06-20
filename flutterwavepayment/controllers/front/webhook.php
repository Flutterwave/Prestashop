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

require_once dirname(__FILE__) . '/../../classes/FlutterwaveApiClient.php';


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

        // Verify signature
        if ($signature !== $secret) {
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

        // Log webhook received
        PrestaShopLogger::addLog(
            'Flutterwave Webhook received: ' . json_encode($webhookData),
            1,
            null,
            'FlutterwavePayment',
            null,
            true
        );

        try {
            // Extract event data
            $event = isset($webhookData['event']) ? $webhookData['event'] : null;
            $data = isset($webhookData['data']) ? $webhookData['data'] : $webhookData;
            
            $reference = isset($data['tx_ref']) ? $data['tx_ref'] : null;
            $status = isset($data['status']) ? $data['status'] : null;
            $amount = isset($data['amount']) ? $data['amount'] : 0;

            if (empty($reference)) {
                throw new Exception('Reference not found in webhook data');
            }

            // Extract cart ID from reference or metadata
            $cartId = null;
            
            if (isset($data['metadata']['cart_id'])) {
                $cartId = (int) $data['metadata']['cart_id'];
            } else {
                // Try to extract from reference (format: {PREFIX}CARTID_TIMESTAMP_RANDOM)
                $pattern = '/^' . preg_quote(FlutterwavePayment::REFERENCE_PREFIX, '/') . '(\d+)_/';
                if (preg_match($pattern, $reference, $matches)) {
                    $cartId = (int) $matches[1];
                }
            }

            if (empty($cartId)) {
                throw new Exception('Cart ID not found in webhook data');
            }

            // Check if order already exists
            $orderId = Order::getOrderByCartId($cartId);
            
            if ($orderId) {
                $order = new Order($orderId);
                
                // Update order status based on webhook event
                if ($status === 'successful' || $status === 'success' || $status === 'completed') {
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
                } elseif ($status === 'failed' || $status === 'declined') {
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
