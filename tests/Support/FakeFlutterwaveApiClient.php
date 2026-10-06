<?php

namespace Tests\Support;

use FlutterwavePayment\classes\FlutterwaveApiClient;

/**
 * API client that records requests and returns queued responses instead of calling Flutterwave.
 */
class FakeFlutterwaveApiClient extends FlutterwaveApiClient
{
    /** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
    public array $requests = [];

    /** @var array<int, array{0: string|false, 1: int, 2: string}> */
    private array $responses = [];

    public function __construct(string $apiUrl = 'https://api.flutterwave.com/v3', string $secretKey = 'FLWSECK_TEST-secret', string $publicKey = 'FLWPUBK_TEST-public')
    {
        parent::__construct($apiUrl, $secretKey, $publicKey);
    }

    public function respondWith(array|string|false $body, int $httpCode = 200, string $curlError = ''): self
    {
        $this->responses[] = [is_array($body) ? json_encode($body) : $body, $httpCode, $curlError];

        return $this;
    }

    public function lastRequest(): array
    {
        return end($this->requests);
    }

    public function lastPayload(): array
    {
        return json_decode($this->lastRequest()['body'], true);
    }

    protected function sendHttpRequest($method, $url, array $headers, $body = null)
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        if (!$this->responses) {
            throw new \LogicException('No fake response queued for ' . $method . ' ' . $url);
        }

        return array_shift($this->responses);
    }
}
