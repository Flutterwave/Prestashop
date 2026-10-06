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

    public function refundTransaction($transactionId, $amount = null)
    {
        $endpoint = 'transactions/' . urlencode($transactionId) . '/refund';

        $payload = [];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        return $this->postRequest($endpoint, $payload, [
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
        $headers = array_merge($headers, [
            'Accept: application/json',
        ]);

        return $this->executeRequest('GET', $this->apiUrl . $endpoint, $headers);
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
        $payload = json_encode($data);

        if ($payload === false) {
            throw new \Exception('Failed to encode request payload as JSON');
        }

        $headers = array_merge($headers, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Flutterwave Payment PrestaShop Module'
        ]);

        return $this->executeRequest('POST', $this->apiUrl . $endpoint, $headers, $payload);
    }

    /**
     * Send the request and handle response/errors
     *
     * @param string      $method
     * @param string      $url
     * @param array       $headers
     * @param string|null $body
     * @return array
     * @throws Exception
     */
    private function executeRequest($method, $url, array $headers, $body = null)
    {
        list($response, $httpCode, $error) = $this->sendHttpRequest($method, $url, $headers, $body);

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
     * Perform the HTTP request
     *
     * @param string      $method
     * @param string      $url
     * @param array       $headers
     * @param string|null $body
     * @return array [response body, HTTP status code, cURL error]
     */
    protected function sendHttpRequest($method, $url, array $headers, $body = null)
    {
        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        return [$response, $httpCode, $error];
    }

    /**
     * Verify the verif-hash webhook header
     *
     * Flutterwave sends the secret hash configured in the dashboard as-is; it
     * is not an HMAC of the body. It only authenticates the sender, so payment
     * details must still be requeried from the API.
     *
     * @param string $header        verif-hash header value
     * @param string $webhookSecret Secret hash from Flutterwave Dashboard
     * @return bool
     */
    public static function verifyWebhookHash($header, $webhookSecret)
    {
        if (empty($webhookSecret) || empty($header)) {
            return false;
        }

        return hash_equals((string) $webhookSecret, (string) $header);
    }
}
