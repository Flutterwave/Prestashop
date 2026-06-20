<?php

use FlutterwavePayment\classes\FlutterwaveApiClient;

it('builds correct payment payload', function () {
    $client = new FlutterwaveApiClient('https://api.flutterwave.com/v3/', 'test-secret', 'test-public');

    $payload = $client->initiateCheckout([
        'amount' => 100,
        'currency' => 'USD',
        'reference' => 'ORD123',
        'callback_url' => 'https://example.com/callback',
        'return_url' => 'https://example.com/return',
        'customer' => [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'phone' => '1234567890'
        ],
        'metadata' => [
            'cart_id' => '1',
            'customer_id' => '1',
            'shop_name' => 'Test Shop'
        ],
    ]);

    expect($payload['amount'])->toBe(100);
    expect($payload['currency'])->toBe('USD');
    expect($payload['tx_ref'])->toBe('ORD123');
});