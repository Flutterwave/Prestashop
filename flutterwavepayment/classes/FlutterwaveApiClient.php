<?php
/**
 * Flutterwave API Client
 *
 * @author    Flutterwave Payment
 * @copyright 2024 Flutterwave Payment
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace FlutterwavePayment\classes;

if (!defined('_PS_VERSION_')) {
    exit;
}



class FlutterwaveApiClient
{
    private $apiUrl;
    private $secretKey;
    private $publicKey;

    public function __construct($apiUrl, $secretKey, $publicKey)
    {
        $this->apiUrl    = rtrim($apiUrl, '/') . '/';
        $this->secretKey = $secretKey;
        $this->publicKey = $publicKey;
    }

    /**
     * Initiate checkout with Flutterwave API
     *
     * @param array $data Checkout data
     * @return array API response
     * @throws Exception on API errors
     */
    public function initiateCheckout($data)
    {
        $endpoint = 'payments';

        $name = isset($data['customer']['name']) ? trim($data['customer']['name']) : '';
        $firstName = null;
        $lastName  = null;

        if ($name !== '') {
            $parts     = preg_split('/\s+/', $name);
            $firstName = $parts[0];
            $lastName  = isset($parts[1]) ? $parts[1] : null;
        }

        // $checkoutData = [
        //     'amount' => $total,
        //     'currency' => $currency->iso_code,
        //     'reference' => $reference,
        //     'customer' => $customerData,
        //     'callback_url' => $callbackUrl,
        //     'return_url' => $returnUrl,
        //     'metadata' => [
        //         'cart_id' => (string) $cart->id,
        //         'customer_id' => (string) $customer->id,
        //         'shop_name' => Configuration::get('PS_SHOP_NAME'),
        //     ],
        // ];

        $payload = [
            'tx_ref'       => $data['reference'],
            'amount'       => $data['amount'],
            'currency'     => $data['currency'],
            'redirect_url' => $data['return_url'],
            'customer'     => [
                'email'     => $data['customer']['email'],
                'name' => $firstName . ' ' . $lastName,
                // 'phone_number'     => $data['customer']['phone'],
            ],
            'customizations' => [
                'title'  => $data['metadata']['shop_name'] . ' Order #' . $data['metadata']['cart_id'],
                // 'logo'   => $data['customizations']['logo'],
            ],
        ];

        return $this->postRequest($endpoint, $payload, [
            "Authorization: Bearer {$this->secretKey}",
        ]);
    }

    /**
     * Verify transaction with Flutterwave API
     *
     * @param string $reference Transaction reference
     * @return array API response
     * @throws Exception on API errors
     */
    public function verifyTransaction($reference)
    {
        $endpoint = 'transactions/verify_by_reference?tx_ref=' . urlencode($reference);

        return $this->getRequest($endpoint, [
            "Authorization: Bearer {$this->secretKey}",
        ]);
    }

    /**
     * Perform a GET request
     *
     * @param string $endpoint
     * @param array  $headers
     * @return array
     * @throws Exception
     */
    private function getRequest($endpoint, $headers = [])
    {
        $url = $this->apiUrl . $endpoint;

        $headers = array_merge($headers, [
            'Accept: application/json',
        ]);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        return $this->executeRequest($ch);
    }

    /**
     * Perform a POST request
     *
     * @param string $endpoint
     * @param array  $data
     * @param array  $headers
     * @return array
     * @throws Exception
     */
    private function postRequest($endpoint, array $data, $headers = [])
    {
        $url     = $this->apiUrl . $endpoint;
        $payload = json_encode($data);

        if ($payload === false) {
            throw new \Exception('Failed to encode request payload as JSON');
        }

        $headers = array_merge($headers, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Flutterwave Payment PrestaShop Module'
        ]);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        return $this->executeRequest($ch);
    }

    /**
     * Execute the prepared cURL request and handle response/errors
     *
     * @param resource $ch
     * @return array
     * @throws Exception
     */
    private function executeRequest($ch)
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL Error: ' . $error);
        }

        if ($response === false || $response === '') {
            throw new \Exception('Empty response from Flutterwave API');
        }


        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from Flutterwave API: ' . json_last_error_msg());
        }

        if ($httpCode >= 400) {
            $errorMessage = 'API Error';

            if (isset($result['message'])) {
                $errorMessage = $result['message'];
            } elseif (isset($result['error'])) {
                $errorMessage = is_array($result['error'])
                    ? json_encode($result['error'])
                    : $result['error'];
            }

            throw new \Exception($errorMessage, $httpCode);
        }

        return $result;
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload Webhook payload (raw POST body)
     * @param string $signature Webhook signature from header
     * @param string $webhookSecret Webhook secret from Flutterwave Dashboard
     * @return bool
     */
    public static function verifyWebhookSignature($payload, $signature, $webhookSecret)
    {
        if (empty($webhookSecret) || empty($signature)) {
            return false;
        }

        // Remove any prefix if present (e.g., 'sha256=')
        $cleanSignature = $signature;
        if (strpos($signature, '=') !== false) {
            list($algorithm, $cleanSignature) = explode('=', $signature, 2);
        }

        $computedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($computedSignature, $cleanSignature);
    }
}
