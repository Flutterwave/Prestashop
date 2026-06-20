<?php

class AdminFlutterwaveRefundController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign([
            'form_action' => self::$currentIndex .
                '&token=' . $this->token,
        ]);

        $this->setTemplate('refund.tpl');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitFlutterwaveRefund')) {

            $orderId = (int)Tools::getValue('id_order');
            $amount = (float)Tools::getValue('amount');

            require_once dirname(__FILE__) .
                '/../../classes/FlutterwaveRefundService.php';

            try {

                $service = new FlutterwaveRefundService(
                    Module::getInstanceByName('flutterwavepayment')
                );

                $response = $service->refundOrder(
                    $orderId,
                    $amount
                );

                $order = new Order($orderId);

                // STEP 2: GET REFUND ID
                $refundId = isset($response['data']['id'])
                    ? $response['data']['id']
                    : 'N/A';

                // STEP 3: SAVE ORDER MESSAGE (ADMIN VIEW)
                $currency = new Currency($order->id_currency);

                $message = sprintf(
                    'Flutterwave refund processed. Amount: %s %s. Refund ID: %s',
                    number_format($amount, 2),
                    $currency->iso_code,
                    $refundId
                );

                $orderMessage = new Message();
                $orderMessage->id_order = (int)$orderId;
                $orderMessage->message = pSQL($message, true);
                $orderMessage->private = 1;
                $orderMessage->add();

                // STEP 4: CALCULATE TOTAL REFUNDED
                $module = Module::getInstanceByName('flutterwavepayment');

                $totalRefunded = Db::getInstance()->getValue(
                    'SELECT COALESCE(SUM(amount),0)
                    FROM ' . _DB_PREFIX_ . 'flutterwave_refund
                    WHERE id_order = ' . (int)$orderId . '
                    AND status = "completed"'
                );

                $orderTotal = (float)$order->total_paid_tax_incl;

                // STEP 5: UPDATE ORDER STATUS IF FULLY REFUNDED
                if (abs($totalRefunded - $orderTotal) < 0.01) {

                    $history = new OrderHistory();
                    $history->id_order = (int)$orderId;

                    $history->changeIdOrderState(
                        (int)Configuration::get('PS_OS_REFUND'),
                        $orderId
                    );

                    $history->addWithemail(true);
                }

                $this->confirmations[] =
                    'Refund completed successfully';

            } catch (Exception $e) {

                $this->errors[] =
                    $e->getMessage();
            }
        }
    }
}