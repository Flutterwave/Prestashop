<?php

use Tests\Support\FakeFlutterwaveApiClient;

function checkoutData(array $overrides = []): array
{
    return array_replace_recursive([
        'amount' => 100,
        'currency' => 'USD',
        'reference' => 'PS_1_1700000000_abcd1234',
        'callback_url' => 'https://example.com/callback',
        'return_url' => 'https://example.com/return',
        'customer' => [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'phone' => '1234567890',
        ],
        'metadata' => [
            'cart_id' => '1',
            'customer_id' => '1',
            'shop_name' => 'Test Shop',
        ],
    ], $overrides);
}

it('builds correct payment payload', function () {
    $client = (new FakeFlutterwaveApiClient())
        ->respondWith(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/pay/abc']]);

    $response = $client->initiateCheckout(checkoutData());

    $request = $client->lastRequest();
    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://api.flutterwave.com/v3/payments')
        ->and($request['headers'])->toContain('Authorization: Bearer FLWSECK_TEST-secret')
        ->and($request['headers'])->toContain('Content-Type: application/json');

    $payload = $client->lastPayload();
    expect($payload['amount'])->toBe(100)
        ->and($payload['currency'])->toBe('USD')
        ->and($payload['tx_ref'])->toBe('PS_1_1700000000_abcd1234')
        ->and($payload['redirect_url'])->toBe('https://example.com/return')
        ->and($payload['customer'])->toBe(['email' => 'john.doe@example.com', 'name' => 'John Doe'])
        ->and($payload['customizations']['title'])->toBe('Test Shop Order #1');

    expect($response['data']['link'])->toBe('https://checkout.flutterwave.com/pay/abc');
});

it('verifies a transaction by url-encoded reference', function () {
    $client = (new FakeFlutterwaveApiClient('https://api.flutterwave.com/v3/'))
        ->respondWith(['status' => 'success', 'data' => ['tx_ref' => 'PS_1_1&x=y']]);

    $client->verifyTransaction('PS_1_1&x=y');

    $request = $client->lastRequest();
    expect($request['method'])->toBe('GET')
        ->and($request['url'])->toBe('https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=PS_1_1%26x%3Dy')
        ->and($request['body'])->toBeNull();
});

it('sends a partial refund amount', function () {
    $client = (new FakeFlutterwaveApiClient())->respondWith(['status' => 'success']);

    $client->refundTransaction('12345', 25.5);

    expect($client->lastRequest()['url'])->toBe('https://api.flutterwave.com/v3/transactions/12345/refund')
        ->and($client->lastPayload())->toBe(['amount' => 25.5]);
});

it('sends a full refund without an amount', function () {
    $client = (new FakeFlutterwaveApiClient())->respondWith(['status' => 'success']);

    $client->refundTransaction('12345');

    expect($client->lastRequest()['body'])->toBe('[]');
});

it('throws the API message on an HTTP error', function () {
    $client = (new FakeFlutterwaveApiClient())
        ->respondWith(['status' => 'error', 'message' => 'Invalid authorization key'], 401);

    $client->verifyTransaction('PS_1_1');
})->throws(Exception::class, 'Invalid authorization key');

it('throws an encoded error object when there is no message', function () {
    $client = (new FakeFlutterwaveApiClient())
        ->respondWith(['error' => ['code' => 'E500']], 500);

    $client->verifyTransaction('PS_1_1');
})->throws(Exception::class, '{"code":"E500"}');

it('throws on a cURL error', function () {
    $client = (new FakeFlutterwaveApiClient())->respondWith(false, 0, 'Could not resolve host');

    $client->verifyTransaction('PS_1_1');
})->throws(Exception::class, 'cURL Error: Could not resolve host');

it('throws on an empty response', function () {
    $client = (new FakeFlutterwaveApiClient())->respondWith('');

    $client->verifyTransaction('PS_1_1');
})->throws(Exception::class, 'Empty response from Flutterwave API');

it('throws on invalid JSON', function () {
    $client = (new FakeFlutterwaveApiClient())->respondWith('<html>Bad gateway</html>', 502);

    $client->verifyTransaction('PS_1_1');
})->throws(Exception::class, 'Invalid JSON response from Flutterwave API');
