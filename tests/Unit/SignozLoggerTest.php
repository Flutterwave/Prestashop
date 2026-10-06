<?php

use FlutterwavePayment\classes\FlutterwaveSignozLogger as Logger;
use Tests\Support\FakeSignozLogger;

const SIGNOZ_PUBLIC_KEY = 'FLWPUBK_TEST-abc123-X';

function signozLogger(array $config = []): FakeSignozLogger
{
    return FakeSignozLogger::make(array_replace([
        'FLUTTERWAVE_PUBLIC_KEY' => SIGNOZ_PUBLIC_KEY,
        'FLUTTERWAVE_LIVE_MODE' => 0,
    ], $config));
}

describe('deferred events', function () {
    it('buffers events until flush and stamps them when tracked', function () {
        $logger = signozLogger();

        $logger->trackRequestSent('POST', 'PS_42_1700000000_abcd1234', '/payments');

        expect($logger->requests)->toBeEmpty()
            ->and($logger->getPendingEvents())->toHaveCount(1);

        $logger->flush();

        $events = $logger->sentEvents();
        expect($events)->toHaveCount(1)
            ->and($events[0]['name'])->toBe('request.sent')
            ->and($events[0]['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/')
            ->and($events[0]['data'])->toMatchArray([
                'app_id' => SIGNOZ_PUBLIC_KEY,
                'environment' => 'sandbox',
                'library' => 'PrestaShop',
                'library_version' => '1.1.0',
                'method' => 'POST',
                'path' => '/payments',
                'reference' => 'PS_42_1700000000_abcd1234',
            ])
            ->and($logger->getPendingEvents())->toBeEmpty();
    });

    it('sends request.sent once per reference', function () {
        $logger = signozLogger();

        $logger->trackRequestSent('POST', 'PS_42_1', '/payments');
        $logger->trackRequestSent('POST', 'PS_42_1', '/payments');

        expect($logger->getPendingEvents())->toHaveCount(1);
    });

    it('reports production when live mode is on', function () {
        expect(signozLogger(['FLUTTERWAVE_LIVE_MODE' => 1])->getCurrentEnvironment())->toBe('production')
            ->and(signozLogger()->getCurrentEnvironment())->toBe('sandbox');
    });

    it('truncates error messages and stack traces', function () {
        $logger = signozLogger();

        $logger->trackError('PAYMENT_FAILED', str_repeat('a', 5000), 'PS_1_2', null, str_repeat('b', 20000));
        $data = $logger->getPendingEvents()[0][1];

        expect(strlen($data['error_message']))->toBe(Logger::ERROR_MESSAGE_MAX_LENGTH)
            ->and(strlen($data['error_stacktrace']))->toBe(Logger::ERROR_STACKTRACE_MAX_LENGTH)
            ->and($data['reference'])->toBe('PS_1_2');
    });

    it('omits the reference on errors without one', function () {
        $logger = signozLogger();

        $logger->trackError('WEBHOOK_SIGNATURE_MISMATCH', 'nope');

        expect($logger->getPendingEvents()[0][1])->not->toHaveKey('reference');
    });

    it('throttles unreferenced errors per error code', function () {
        $logger = signozLogger();

        $logger->trackError('WEBHOOK_SECRET_HASH_MISSING', 'no secret');
        $logger->trackError('WEBHOOK_SECRET_HASH_MISSING', 'no secret');
        $logger->trackError('CALLBACK_REJECTED', 'not bound');

        expect(array_column(array_column($logger->getPendingEvents(), 1), 'error_code'))
            ->toBe(['WEBHOOK_SECRET_HASH_MISSING', 'CALLBACK_REJECTED']);
    });

    it('sends an unreferenced error again once the window expires', function () {
        $logger = signozLogger();

        $logger->trackError('CALLBACK_REJECTED', 'not bound');
        $logger->state[Logger::ERROR_THROTTLE_KEY_PREFIX . md5('CALLBACK_REJECTED')]['expires_at'] = time() - 1;
        $logger->trackError('CALLBACK_REJECTED', 'not bound');

        expect($logger->getPendingEvents())->toHaveCount(2);
    });

    it('does not throttle errors tied to a reference', function () {
        $logger = signozLogger();

        $logger->trackError('PAYMENT_FAILED', 'declined', 'PS_1_1');
        $logger->trackError('PAYMENT_FAILED', 'declined', 'PS_2_1');

        expect($logger->getPendingEvents())->toHaveCount(2);
    });

    it('normalizes references', function () {
        $logger = signozLogger();

        $logger->trackTransaction(' PS_1 2/../x ', 'NGN', 10, 'card', 0.14);

        expect($logger->getPendingEvents()[0][1]['reference'])->toBe('PS_1-2-x');
    });
});

describe('trace context', function () {
    it('links events for the same reference into one trace', function () {
        $logger = signozLogger();

        $logger->trackRequestSent('POST', 'PS_7_1', '/payments');
        $logger->trackTransaction('PS_7_1', 'NGN', 100, 'card', 1.4);

        [$first, $second] = array_map(fn ($e) => $e[1]['trace_context'], $logger->getPendingEvents());

        expect($first['trace_id'])->toMatch('/^[0-9a-f]{32}$/')
            ->and($first['span_id'])->toMatch('/^[0-9a-f]{16}$/')
            ->and($first)->not->toHaveKey('parent_span_id')
            ->and($second['trace_id'])->toBe($first['trace_id'])
            ->and($second['parent_span_id'])->toBe($first['span_id'])
            ->and($second['span_id'])->not->toBe($first['span_id']);
    });

    it('survives across requests through the state store', function () {
        $checkout = signozLogger();
        $checkout->trackRequestSent('POST', 'PS_7_1', '/payments');
        $root = $checkout->getPendingEvents()[0][1]['trace_context'];

        $webhook = signozLogger();
        $webhook->state = $checkout->state;
        $webhook->trackError('PAYMENT_FAILED', 'declined', 'PS_7_1');

        expect($webhook->getPendingEvents()[0][1]['trace_context']['trace_id'])->toBe($root['trace_id']);
    });

    it('honours a W3C traceparent', function () {
        $logger = signozLogger();

        $logger->trackError('X', 'y', 'PS_1_1', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);

        expect($logger->getPendingEvents()[0][1]['trace_context'])->toMatchArray([
            'trace_id' => '0af7651916cd43dd8448eb211c80319c',
            'parent_span_id' => 'b7ad6b7169203331',
        ]);
    });
});

describe('app registration', function () {
    it('stores the backend app_id and uses it on later events', function () {
        $logger = signozLogger()->respondToEvents(['app_id' => 'app 123']);

        expect($logger->registerApp(SIGNOZ_PUBLIC_KEY, '1.1.0'))->toBe('app-123')
            ->and($logger->config[Logger::CONFIG_APP_ID])->toBe('app-123')
            ->and($logger->isAppRegistered('1.1.0'))->toBeTrue()
            ->and($logger->isAppRegistered('1.2.0'))->toBeFalse();

        $created = $logger->sentEvents()[0];
        expect($created['name'])->toBe('app.created')
            ->and($created['data'])->toMatchArray(['public_key' => SIGNOZ_PUBLIC_KEY, 'library' => 'PrestaShop']);

        $logger->trackError('X', 'y');
        expect($logger->getPendingEvents()[0][1]['app_id'])->toBe('app-123');
    });

    it('does not call the service again once an app_id is stored', function () {
        $logger = signozLogger([Logger::CONFIG_APP_ID => 'app-1', Logger::CONFIG_REGISTERED_KEY => SIGNOZ_PUBLIC_KEY]);

        expect($logger->registerApp(SIGNOZ_PUBLIC_KEY, '1.1.0'))->toBe('app-1')
            ->and($logger->requests)->toBeEmpty();
    });

    it('registers a new app when the public key changes', function () {
        $logger = signozLogger([
            Logger::CONFIG_APP_ID => 'app-old',
            Logger::CONFIG_APP_REGISTERED => 1,
            Logger::CONFIG_REGISTERED_KEY => 'FLWPUBK_TEST-old',
        ])->respondToEvents(['app_id' => 'app-new']);

        expect($logger->registerApp(SIGNOZ_PUBLIC_KEY, '1.1.0'))->toBe('app-new')
            ->and($logger->config[Logger::CONFIG_REGISTERED_KEY])->toBe(SIGNOZ_PUBLIC_KEY);
    });

    it('leaves registration pending when the service is down', function () {
        $logger = signozLogger()->respondToHealth('', 0, 'Could not resolve host');

        expect($logger->registerApp(SIGNOZ_PUBLIC_KEY, '1.1.0'))->toBeNull()
            ->and($logger->isAppRegistered('1.1.0'))->toBeFalse();
    });

    it('throttles background registration attempts', function () {
        $logger = signozLogger();

        $logger->maybeRegisterInBackground('1.1.0');
        expect($logger->state)->toHaveKey(Logger::REGISTER_ATTEMPT_KEY);

        $logger->state[Logger::REGISTER_ATTEMPT_KEY]['value'] = 'marker';
        $logger->maybeRegisterInBackground('1.1.0');
        expect($logger->state[Logger::REGISTER_ATTEMPT_KEY]['value'])->toBe('marker');
    });

    it('skips background registration without a public key', function () {
        $logger = signozLogger(['FLUTTERWAVE_PUBLIC_KEY' => '']);

        $logger->maybeRegisterInBackground('1.1.0');

        expect($logger->state)->toBeEmpty();
    });
});

describe('transport', function () {
    it('caches a passing health check', function () {
        $logger = signozLogger();

        $logger->trackError('A', 'a');
        $logger->trackError('B', 'b');
        $logger->flush();

        expect($logger->healthChecks())->toBe(1)
            ->and($logger->sentEvents())->toHaveCount(2);
    });

    it('drops events when the health check fails', function () {
        $logger = signozLogger()->respondToHealth(['status' => 'starting']);

        $logger->trackError('A', 'a');
        $logger->flush();

        expect($logger->sentEvents())->toBeEmpty();
    });

    it('retries 503 responses with backoff on blocking sends', function () {
        $logger = signozLogger()
            ->respondToEvents('', 503)
            ->respondToEvents('', 503)
            ->respondToEvents(['app_id' => 'app-1'], 202);

        expect($logger->registerApp(SIGNOZ_PUBLIC_KEY, '1.1.0'))->toBe('app-1')
            ->and($logger->sentEvents())->toHaveCount(3)
            ->and($logger->sleeps)->toBe(2)
            ->and($logger->state)->not->toHaveKey(Logger::CB_FAILURES_KEY);
    });

    it('does not retry 503 responses during a flush', function () {
        $logger = signozLogger()->respondToEvents('', 503);

        $logger->trackError('A', 'a');
        $logger->flush();

        expect($logger->sentEvents())->toHaveCount(1)
            ->and($logger->sleeps)->toBe(0);
    });

    it('uses short timeouts during a flush and longer ones for blocking sends', function () {
        $logger = signozLogger()->respondToEvents(['app_id' => 'app-1']);

        $logger->registerApp(SIGNOZ_PUBLIC_KEY, '1.1.0');
        $logger->trackError('A', 'a');
        $logger->flush();

        expect(array_column($logger->requests, 'timeoutMs'))->toBe([
            Logger::HEALTH_TIMEOUT_MS,
            Logger::BLOCKING_TIMEOUT_MS,
            Logger::EVENT_TIMEOUT_MS,
        ]);
    });

    it('stops flushing once the time budget is spent', function () {
        $logger = signozLogger();
        $logger->latencyMs = 600;

        foreach (range(1, 5) as $i) {
            $logger->trackError('E' . $i, 'boom');
        }
        $start = $logger->clock;
        $logger->flush();

        // Health (600ms) + 1 event (600ms) leaves 300ms: one more event fits
        // with a clipped timeout, which times out without blaming the service.
        expect(($logger->clock - $start) * 1000)->toBeLessThanOrEqual(Logger::FLUSH_BUDGET_MS)
            ->and($logger->sentEvents())->toHaveCount(2)
            ->and($logger->state)->not->toHaveKey(Logger::CB_FAILURES_KEY)
            ->and($logger->getPendingEvents())->toBeEmpty();
    });

    it('treats a response slower than the timeout as a failure', function () {
        $logger = signozLogger();
        $logger->state[Logger::HEALTH_OK_KEY] = ['value' => true, 'expires_at' => time() + 60];
        $logger->latencyMs = Logger::EVENT_TIMEOUT_MS + 500;

        $logger->trackError('A', 'a');
        $logger->flush();

        expect($logger->state[Logger::CB_FAILURES_KEY]['value'])->toBe(1);
    });

    it('opens the circuit when the service is persistently slow', function () {
        $state = [Logger::HEALTH_OK_KEY => ['value' => true, 'expires_at' => time() + 60]];

        foreach (range(1, Logger::CB_FAILURE_THRESHOLD) as $i) {
            $logger = signozLogger();
            $logger->state = $state;
            $logger->latencyMs = 5000;
            $logger->trackError('E' . $i, 'slow');
            $logger->flush();
            $state = $logger->state;
        }

        $next = signozLogger();
        $next->state = $state;
        $next->trackError('LATER', 'x');
        $next->flush();

        expect($state)->toHaveKey(Logger::CB_OPEN_KEY)
            ->and($next->requests)->toBeEmpty();
    });

    it('does not retry other errors', function () {
        $logger = signozLogger()->respondToEvents('', 500);

        $logger->trackError('A', 'a');
        $logger->flush();

        expect($logger->sentEvents())->toHaveCount(1)
            ->and($logger->sleeps)->toBe(0);
    });

    it('opens the circuit after consecutive failures and stops sending', function () {
        $logger = signozLogger()
            ->respondToEvents('', 500)
            ->respondToEvents('', 500)
            ->respondToEvents('', 500);

        foreach (range(1, 4) as $i) {
            $logger->trackError('E' . $i, 'boom');
        }
        $logger->flush();

        expect($logger->sentEvents())->toHaveCount(3)
            ->and($logger->state)->toHaveKey(Logger::CB_OPEN_KEY)
            ->and($logger->state)->not->toHaveKey(Logger::HEALTH_OK_KEY);
    });

    it('closes the circuit after a success', function () {
        $logger = signozLogger()->respondToEvents('', 500)->respondToEvents('{}', 200);

        $logger->trackError('A', 'a');
        $logger->trackError('B', 'b');
        $logger->flush();

        expect($logger->state)->not->toHaveKey(Logger::CB_FAILURES_KEY);
    });

    it('never throws when the transport blows up', function () {
        $logger = new class extends FakeSignozLogger {
            protected function sendHttpRequest($method, $url, array $headers, $body, $timeout)
            {
                throw new RuntimeException('boom');
            }
        };

        $logger->trackError('A', 'a');
        $logger->flush();

        expect($logger->getPendingEvents())->toBeEmpty();
    });
});
