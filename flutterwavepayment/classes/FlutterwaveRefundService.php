<?php
/**
 * Flutterwave Refund Service
 *
 * @author    Flutterwave Payment
 * @copyright 2024 Flutterwave Payment
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
require_once dirname(__FILE__) . '/FlutterwaveApiClient.php';

use FlutterwavePayment\classes\FlutterwaveApiClient;

class FlutterwaveRefundService
{
    private $module;

    public function __construct(FlutterwavePayment $module)
    {
        $this->module = $module;
    }

    public function refundOrder($orderId, $amount)
    {
        $transaction = $this->module->getTransactionByOrderId($orderId);

        if (!$transaction) {
            throw new Exception('Flutterwave transaction not found.');
        }

        $totalRefunded = (float) Db::getInstance()->getValue(
            'SELECT COALESCE(SUM(amount), 0)
             FROM ' . _DB_PREFIX_ . 'flutterwave_refund
             WHERE id_order = ' . (int) $orderId . '
             AND status = "successful"'
        );

        $remainingAmount =
            (float) $transaction['amount'] - $totalRefunded;

        if ($amount > $remainingAmount) {
            throw new Exception(
                'Refund amount exceeds remaining balance.'
            );
        }

        $apiClient = new FlutterwaveApiClient(
            $this->module->getApiUrl(),
            $this->module->getSecretKey(),
            $this->module->getPublicKey()
        );

        $response = $apiClient->refundTransaction(
            $transaction['flutterwave_transaction_id'],
            $amount
        );

        Db::getInstance()->insert(
            'flutterwave_refund',
            [
                'id_order' => (int) $orderId,
                'refund_reference' => isset($response['data']['id'])
                    ? pSQL((string) $response['data']['id'])
                    : '',
                'amount' => (float) $amount,
                'status' => isset($response['data']['status'])
                    ? pSQL((string) $response['data']['status'])
                    : 'successful',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $response;
    }
}