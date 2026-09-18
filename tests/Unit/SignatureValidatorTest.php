<?php

use FlutterwavePayment\classes\FlutterwaveApiClient;

it('accepts the configured secret hash', function () {
    expect(FlutterwaveApiClient::verifyWebhookHash('my-dashboard-secret', 'my-dashboard-secret'))->toBeTrue();
});

it('rejects a different secret hash', function (string $header) {
    expect(FlutterwaveApiClient::verifyWebhookHash($header, 'my-dashboard-secret'))->toBeFalse();
})->with([
    'wrong value' => 'another-secret',
    'prefix only' => 'my-dashboard',
    'different case' => 'MY-DASHBOARD-SECRET',
    'hmac of body' => hash_hmac('sha256', '{"event":"charge.completed"}', 'my-dashboard-secret'),
]);

it('fails closed when no secret is configured', function (?string $secret) {
    expect(FlutterwaveApiClient::verifyWebhookHash('', $secret))->toBeFalse()
        ->and(FlutterwaveApiClient::verifyWebhookHash('anything', $secret))->toBeFalse();
})->with([
    'empty string' => '',
    'null' => null,
]);

it('rejects a missing header', function () {
    expect(FlutterwaveApiClient::verifyWebhookHash('', 'my-dashboard-secret'))->toBeFalse()
        ->and(FlutterwaveApiClient::verifyWebhookHash(null, 'my-dashboard-secret'))->toBeFalse();
});
