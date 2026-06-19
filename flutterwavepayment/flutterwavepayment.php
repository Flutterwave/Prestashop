<?php
/**
 * Flutterwave Payment Module for PrestaShop
 *
 * @author    Flutterwave Developers
 * @copyright 2024 Flutterwave Developers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FlutterwavePayment extends PaymentModule
{
    const FLUTTERWAVE_PRODUCTION_URL = 'https://api.flutterwave.com/v3';
    const FLUTTERWAVE_SANDBOX_URL = 'https://api.flutterwave.com/v3';
    const REFERENCE_PREFIX = 'PS_';
    const AMOUNT_TOLERANCE = 0.01;

    public function __construct()
    {
        $this->name = 'flutterwavepayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Flutterwave Developers';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;
        $this->controllers = ['payment', 'validation', 'webhook'];

        parent::__construct();

        $this->displayName = $this->l('Flutterwave Payment');
        $this->description = $this->l('Accept payments securely with Flutterwave');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Flutterwave Payment module?');
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('actionOrderStatusUpdate');
        //            && Configuration::updateValue('FLUTTERWAVE_LIVE_MODE', 0)
//            && Configuration::updateValue('FLUTTERWAVE_PUBLIC_KEY', '')
//            && Configuration::updateValue('FLUTTERWAVE_SECRET_KEY', '')
//            && Configuration::updateValue('FLUTTERWAVE_WEBHOOK_SECRET', '');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('FLUTTERWAVE_LIVE_MODE')
            && Configuration::deleteByName('FLUTTERWAVE_PUBLIC_KEY')
            && Configuration::deleteByName('FLUTTERWAVE_SECRET_KEY')
            && Configuration::deleteByName('FLUTTERWAVE_WEBHOOK_SECRET');
    }

    public function generateReference($cartId)
    {
        return self::REFERENCE_PREFIX . $cartId . '_' . time();
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $flutterwaveLiveMode = (int) Tools::getValue('FLUTTERWAVE_LIVE_MODE');
            $flutterwavePublicKey = (string) Tools::getValue('FLUTTERWAVE_PUBLIC_KEY');
            $flutterwaveSecretKey = (string) Tools::getValue('FLUTTERWAVE_SECRET_KEY');
            $flutterwaveWebhookSecret = (string) Tools::getValue('FLUTTERWAVE_WEBHOOK_SECRET');

            if (empty($flutterwavePublicKey) || empty($flutterwaveSecretKey)) {
                $output .= $this->displayError($this->l('Invalid Configuration values'));
            } else {
                Configuration::updateValue('FLUTTERWAVE_LIVE_MODE', $flutterwaveLiveMode);
                Configuration::updateValue('FLUTTERWAVE_PUBLIC_KEY', $flutterwavePublicKey);
                Configuration::updateValue('FLUTTERWAVE_SECRET_KEY', $flutterwaveSecretKey);
                Configuration::updateValue('FLUTTERWAVE_WEBHOOK_SECRET', $flutterwaveWebhookSecret);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $webhookUrl = $this->context->link->getModuleLink(
            $this->name,
            'webhook',
            [],
            true
        );

        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'switch',
                    'label' => $this->l('Live mode'),
                    'name' => 'FLUTTERWAVE_LIVE_MODE',
                    'is_bool' => true,
                    'desc' => $this->l('Use live mode to accept real payments'),
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ],
                        [
                            'id' => 'active_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        ]
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Webhook URL'),
                    'name' => 'FLUTTERWAVE_WEBHOOK_URL_DISPLAY',
                    'readonly' => true,
                    'size' => 100,
                    'desc' => $this->l('Copy this URL and configure it in your Flutterwave dashboard.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Public Key'),
                    'name' => 'FLUTTERWAVE_PUBLIC_KEY',
                    'size' => 50,
                    'required' => true,
                    'desc' => $this->l('Enter your Flutterwave public key'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Secret Key'),
                    'name' => 'FLUTTERWAVE_SECRET_KEY',
                    'size' => 50,
                    'required' => true,
                    'desc' => $this->l('Enter your Flutterwave secret key'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Webhook Secret'),
                    'name' => 'FLUTTERWAVE_WEBHOOK_SECRET',
                    'size' => 50,
                    'required' => false,
                    'desc' => $this->l('Enter your Flutterwave webhook secret (optional)'),
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right',
            ]
        ];

        $helper = new HelperForm();

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                    '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        $helper->fields_value['FLUTTERWAVE_LIVE_MODE'] = (int) Configuration::get('FLUTTERWAVE_LIVE_MODE');
        $helper->fields_value['FLUTTERWAVE_PUBLIC_KEY'] = Configuration::get('FLUTTERWAVE_PUBLIC_KEY');
        $helper->fields_value['FLUTTERWAVE_SECRET_KEY'] = Configuration::get('FLUTTERWAVE_SECRET_KEY');
        $helper->fields_value['FLUTTERWAVE_WEBHOOK_SECRET'] = Configuration::get('FLUTTERWAVE_WEBHOOK_SECRET');
        $helper->fields_value['FLUTTERWAVE_WEBHOOK_URL_DISPLAY'] = $webhookUrl;

        return $helper->generateForm($fieldsForm);
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $paymentOption->setCallToActionText($this->l('Pay with Flutterwave'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
            ->setAdditionalInformation($this->fetch('module:flutterwavepayment/views/templates/hook/payment_infos.tpl'));

        return [$paymentOption];
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $order = $params['order'];

        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR')) {
            $this->smarty->assign([
                'status' => 'ok',
                'reference' => $order->reference,
            ]);
        }

        return $this->fetch('module:flutterwavepayment/views/templates/hook/payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getApiUrl()
    {
        $liveMode = (int) Configuration::get('FLUTTERWAVE_LIVE_MODE');
        return $liveMode ? self::FLUTTERWAVE_PRODUCTION_URL : self::FLUTTERWAVE_SANDBOX_URL;
    }

    public function getSecretKey()
    {
        return Configuration::get('FLUTTERWAVE_SECRET_KEY');
    }

    public function getPublicKey()
    {
        return Configuration::get('FLUTTERWAVE_PUBLIC_KEY');
    }

    public function getWebhookSecret()
    {
        return Configuration::get('FLUTTERWAVE_WEBHOOK_SECRET');
    }
}
