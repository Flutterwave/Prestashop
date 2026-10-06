<?php
/**
 * SigNoz observability service integration
 *
 * Sends integration events (app.created, request.sent, app.transaction, app.error)
 * to the Flutterwave SigNoz service for developer analytics and TTFS/TTGL tracking.
 *
 * PrestaShop has no background job runner, so events are buffered in memory and
 * sent from a shutdown function after the response has been flushed to the
 * shopper (fastcgi_finish_request / litespeed_finish_request when available).
 * The sender applies a health gate + circuit breaker, so failures are contained
 * and never surface to users. The only call that blocks is app.created, which
 * needs the response body (backend-generated app_id) and only runs from the
 * back office.
 *
 * @author    Flutterwave Developers
 * @copyright 2024 Flutterwave Developers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace FlutterwavePayment\classes;

if (!defined('_PS_VERSION_')) {
    exit;
}

class FlutterwaveSignozLogger
{
    const BASE_URL = 'https://signozservice-prod.f4b-flutterwave.com';
    const API_KEY = '%%SIGNOZ_API_KEY%%';
    const LIBRARY = 'PrestaShop';
    const LIBRARY_VERSION_FALLBACK = '1.1.0';

    // --- Configuration keys ---
    const CONFIG_APP_ID = 'FLUTTERWAVE_SIGNOZ_APP_ID';
    const CONFIG_APP_REGISTERED = 'FLUTTERWAVE_SIGNOZ_APP_REGISTERED';
    const CONFIG_REGISTERED_KEY = 'FLUTTERWAVE_SIGNOZ_REGISTERED_KEY';
    const CONFIG_REGISTERED_VERSION = 'FLUTTERWAVE_SIGNOZ_REGISTERED_VERSION';

    // --- State table (TTL key/value store, stands in for WP transients) ---
    const STATE_TABLE = 'flutterwave_signoz_state';

    // --- Health check ---
    const HEALTH_PATH = '/health/ready';
    const HEALTH_CACHE_TTL = 60;   // Seconds a successful health check is trusted.

    // --- Circuit breaker ---
    const CB_FAILURE_THRESHOLD = 3;    // Consecutive failures before opening.
    const CB_OPEN_TTL = 120;           // Seconds the circuit stays open (cooldown).
    const CB_FAILURES_KEY = 'cb_failures';
    const CB_OPEN_KEY = 'cb_open';
    const HEALTH_OK_KEY = 'health_ok';

    // --- Timeouts ---
    const HEALTH_TIMEOUT_MS = 1000;
    const EVENT_TIMEOUT_MS = 1000;       // Events flushed at the end of a request.
    const BLOCKING_TIMEOUT_MS = 2000;    // app.created from the back office.
    // Wall-clock cap for one end-of-request flush. The worker stays busy while
    // it runs, so slow responses must not hold it for long; events still
    // queued when it runs out are dropped.
    const FLUSH_BUDGET_MS = 1500;

    // --- Retry / backoff (503 only, blocking sends only) ---
    const MAX_ATTEMPTS = 3;      // Total attempts (1 initial + 2 retries).
    const BASE_DELAY_MS = 200;   // Backoff base.
    const MAX_DELAY_MS = 1500;   // Per-retry delay cap.

    // --- Payload limits ---
    const ERROR_MESSAGE_MAX_LENGTH = 4096;
    const ERROR_STACKTRACE_MAX_LENGTH = 16384;

    // --- Trace context ---
    const TRACE_CTX_TTL = 3600;  // Lifetime of a stored trace context.
    const TRACE_CTX_KEY_PREFIX = 'trace_';
    const REQUEST_SENT_TTL = 300;
    const REQUEST_SENT_KEY_PREFIX = 'req_';

    // --- Unreferenced error throttle ---
    // Errors without a transaction reference can be triggered by anyone hitting
    // a public endpoint, so each error code is sent at most once per window.
    const ERROR_THROTTLE_TTL = 60;
    const ERROR_THROTTLE_KEY_PREFIX = 'err_';

    // --- Back-office auto registration throttle ---
    const REGISTER_ATTEMPT_KEY = 'register_attempt';
    const REGISTER_ATTEMPT_TTL = 300;

    /** @var static|null */
    private static $instance = null;

    /** @var string Backend-generated app identifier (from the app.created response). */
    private $appId = '';

    /** @var array<string, array> In-process trace context registry (warm cache in front of the state table). */
    private $traceContextsByReference = [];

    /** @var array|null Default trace context applied when none is registered for a reference. */
    private $defaultTraceContext = null;

    /** @var array<int, array{0: string, 1: array, 2: string}> Events waiting for the shutdown flush. */
    private $pendingEvents = [];

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var float|null microtime(true) deadline while a flush is running. */
    private $flushDeadline = null;

    protected function __construct()
    {
    }

    /**
     * @return static
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * Swap the singleton (tests only).
     *
     * @param FlutterwaveSignozLogger|null $instance
     */
    public static function setInstance($instance)
    {
        self::$instance = $instance;
    }

    // --- Environment / identity -----------------------------------------

    /**
     * @return string "production" or "sandbox"
     */
    public function getCurrentEnvironment()
    {
        return (int) $this->getConfig('FLUTTERWAVE_LIVE_MODE') ? 'production' : 'sandbox';
    }

    /**
     * Prefers the backend-generated app_id (from app.created), then the
     * configured public key, then an anonymous marker.
     *
     * @return string
     */
    public function getAppId()
    {
        if ('' !== $this->appId) {
            return $this->appId;
        }

        $stored = (string) $this->getConfig(self::CONFIG_APP_ID);
        if ('' !== $stored) {
            $this->appId = $this->normalizeAppId($stored);
            return $this->appId;
        }

        $publicKey = (string) $this->getConfig('FLUTTERWAVE_PUBLIC_KEY');
        if ('' !== $publicKey) {
            return $this->normalizeAppId($publicKey);
        }

        return 'unknown';
    }

    /**
     * Whether app.created has succeeded for the given module version.
     *
     * @param string $version
     * @return bool
     */
    public function isAppRegistered($version)
    {
        return (bool) $this->getConfig(self::CONFIG_APP_REGISTERED)
            && '' !== (string) $this->getConfig(self::CONFIG_APP_ID)
            && (string) $this->getConfig(self::CONFIG_REGISTERED_VERSION) === (string) $version;
    }

    /**
     * Forget the stored app_id when the merchant switches to a different
     * public key, so the next registration creates an app for the new key.
     *
     * @param string $publicKey
     */
    public function resetRegistrationIfKeyChanged($publicKey)
    {
        $registeredKey = (string) $this->getConfig(self::CONFIG_REGISTERED_KEY);

        if ('' === $registeredKey || $registeredKey === (string) $publicKey) {
            return;
        }

        $this->appId = '';
        $this->updateConfig(self::CONFIG_APP_ID, '');
        $this->updateConfig(self::CONFIG_APP_REGISTERED, 0);
        $this->updateConfig(self::CONFIG_REGISTERED_KEY, '');
    }

    /**
     * Register the app if it is missing or was registered on an older
     * module version. Blocks on the network, so only call it from the back office.
     *
     * @param string $publicKey
     * @param string $version   Running module version.
     * @return string|null app_id, or null when the service is unavailable.
     */
    public function registerApp($publicKey, $version)
    {
        try {
            if ('' === (string) $publicKey) {
                return null;
            }

            $this->resetRegistrationIfKeyChanged($publicKey);
            $appId = $this->trackAppCreated($publicKey);

            if (null === $appId) {
                return null; // Service unavailable / circuit open — retried later.
            }

            $this->updateConfig(self::CONFIG_APP_REGISTERED, 1);
            $this->updateConfig(self::CONFIG_REGISTERED_KEY, (string) $publicKey);
            $this->updateConfig(self::CONFIG_REGISTERED_VERSION, (string) $version);

            return $appId;
        } catch (\Throwable $e) {
            // Observability must never break the shop.
            unset($e);
        }

        return null;
    }

    /**
     * Back-office catch-all for merchants who upgrade without re-saving the
     * settings. Cheap when already registered; otherwise defers the network
     * call until after the admin page has been sent, at most once per
     * REGISTER_ATTEMPT_TTL.
     *
     * @param string $version Running module version.
     */
    public function maybeRegisterInBackground($version)
    {
        try {
            if ($this->isAppRegistered($version)) {
                return;
            }

            $publicKey = (string) $this->getConfig('FLUTTERWAVE_PUBLIC_KEY');
            if ('' === $publicKey) {
                return; // Merchant hasn't configured keys yet — nothing to register.
            }

            if ($this->stateGet(self::REGISTER_ATTEMPT_KEY)) {
                return; // Tried recently; avoid probing on every admin page.
            }

            $this->stateSet(self::REGISTER_ATTEMPT_KEY, true, self::REGISTER_ATTEMPT_TTL);

            $this->deferToShutdown(function () use ($publicKey, $version) {
                $this->registerApp($publicKey, $version);
            });
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    // --- Trace context ----------------------------------------------------

    /**
     * @param array|null $traceContext W3C-style trace context or null to clear.
     */
    public function setDefaultTraceContext($traceContext)
    {
        $this->defaultTraceContext = $traceContext;
    }

    /**
     * @return array|null
     */
    public function getDefaultTraceContext()
    {
        return $this->defaultTraceContext;
    }

    /**
     * Register (or clear) the trace context for a transaction reference.
     * Persisted in the state table so it survives across requests
     * (checkout -> return URL / webhook).
     *
     * @param string     $reference
     * @param array|null $traceContext
     */
    public function setTraceContextForReference($reference, $traceContext)
    {
        try {
            $key = $this->normalizeReference($reference);

            if ('' === $key) {
                return;
            }

            if (null === $traceContext) {
                unset($this->traceContextsByReference[$key]);
                $this->stateDelete($this->traceContextKey($key));
                return;
            }

            $this->traceContextsByReference[$key] = $traceContext;
            $this->stateSet($this->traceContextKey($key), $traceContext, self::TRACE_CTX_TTL);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * @param string $reference
     * @return array|null
     */
    public function getTraceContextForReference($reference)
    {
        try {
            $key = $this->normalizeReference($reference);

            if ('' === $key) {
                return null;
            }

            if (isset($this->traceContextsByReference[$key])) {
                return $this->traceContextsByReference[$key];
            }

            $stored = $this->stateGet($this->traceContextKey($key));

            if (is_array($stored)) {
                $this->traceContextsByReference[$key] = $stored;
                return $stored;
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        return null;
    }

    /**
     * Resolve the trace context for an event: explicit > default > by-reference.
     * Creates a root span when nothing is known about the reference, otherwise a
     * child span under the previous one so the trace stays linked by tx_ref.
     *
     * @param array|null $explicit
     * @param string     $reference
     * @return array
     */
    private function resolveTraceContext($explicit, $reference)
    {
        $parent = $explicit;
        if (null === $parent) {
            $parent = $this->defaultTraceContext;
        }
        if (null === $parent) {
            $parent = $this->getTraceContextForReference($reference);
        }

        $context = $this->buildTraceContext($parent);
        $this->setTraceContextForReference($reference, $context);

        return $context;
    }

    /**
     * @param array|null $parent
     * @return array
     */
    private function buildTraceContext($parent = null)
    {
        $context = [
            'trace_id' => $this->randomHex(16),
            'span_id' => $this->randomHex(8),
        ];

        if (null !== $parent) {
            $traceId = $this->extractTraceId($parent);
            if (null !== $traceId) {
                $context['trace_id'] = $traceId;
            }

            $spanId = $this->extractSpanId($parent);
            if (null !== $spanId && '' !== $spanId) {
                $context['parent_span_id'] = $spanId;
            }
        }

        return $context;
    }

    /**
     * @param int $bytes
     * @return string
     */
    private function randomHex($bytes)
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable $e) {
            return substr(md5(uniqid('', true) . microtime(true)), 0, $bytes * 2);
        }
    }

    /**
     * @param array $context
     * @return string|null
     */
    private function extractTraceId(array $context)
    {
        if (isset($context['trace_id']) && is_string($context['trace_id']) && '' !== trim($context['trace_id'])) {
            return $context['trace_id'];
        }

        if (isset($context['traceparent']) && is_string($context['traceparent'])) {
            $parts = explode('-', $context['traceparent']);
            return isset($parts[1]) ? $parts[1] : null;
        }

        return null;
    }

    /**
     * @param array $context
     * @return string|null
     */
    private function extractSpanId(array $context)
    {
        if (isset($context['span_id']) && is_string($context['span_id']) && '' !== trim($context['span_id'])) {
            return $context['span_id'];
        }

        if (isset($context['traceparent']) && is_string($context['traceparent'])) {
            $parts = explode('-', $context['traceparent']);
            return isset($parts[2]) ? $parts[2] : null;
        }

        return null;
    }

    /**
     * @param string $normalizedReference
     * @return string
     */
    private function traceContextKey($normalizedReference)
    {
        return self::TRACE_CTX_KEY_PREFIX . md5($normalizedReference);
    }

    // --- Events -------------------------------------------------------------

    /**
     * Fire the `app.created` event and persist the backend-generated app_id.
     * Idempotent: skips the network call when an app_id is already stored.
     *
     * Intentionally blocking: the response body carries the app_id, and this
     * only runs from the back office, never during checkout.
     *
     * @param string $publicKey
     * @return string|null
     */
    public function trackAppCreated($publicKey)
    {
        try {
            if ('' === (string) $publicKey) {
                return null;
            }

            $stored = (string) $this->getConfig(self::CONFIG_APP_ID);
            if ('' !== $stored) {
                $this->appId = $this->normalizeAppId($stored);
                return $this->appId;
            }

            $response = $this->sendNow(
                'app.created',
                [
                    'client_id' => null,
                    'public_key' => (string) $publicKey,
                    'library' => self::LIBRARY,
                    'library_version' => $this->libraryVersion(),
                ],
                $this->timestamp()
            );

            if (!is_array($response) || empty($response['app_id'])) {
                return null;
            }

            $this->appId = $this->normalizeAppId((string) $response['app_id']);
            $this->updateConfig(self::CONFIG_APP_ID, $this->appId);

            return $this->appId;
        } catch (\Throwable $e) {
            unset($e);
        }

        return null;
    }

    /**
     * Fire the `request.sent` event when a payment request is initiated.
     *
     * @param string     $method       HTTP method (e.g. "POST").
     * @param string     $reference    Transaction reference (tx_ref).
     * @param string     $path         Request path (e.g. "/payments").
     * @param array|null $traceContext Optional trace context override.
     */
    public function trackRequestSent($method, $reference, $path, $traceContext = null)
    {
        try {
            $safeReference = $this->normalizeReference($reference);

            $payload = [
                'app_id' => $this->getAppId(),
                'environment' => $this->getCurrentEnvironment(),
                'api_version' => 'v3',
                'library' => self::LIBRARY,
                'library_version' => $this->libraryVersion(),
                'method' => (string) $method,
                'path' => (string) $path,
                'reference' => $safeReference,
            ];

            $cacheKey = self::REQUEST_SENT_KEY_PREFIX . md5($safeReference);

            if ($this->stateGet($cacheKey)) {
                return; // Already sent recently.
            }

            $this->stateSet($cacheKey, true, self::REQUEST_SENT_TTL);

            $payload['trace_context'] = $this->resolveTraceContext($traceContext, $safeReference);

            $this->queueEvent('request.sent', $payload);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Fire the `app.transaction` event after a successful payment.
     *
     * @param string     $reference
     * @param string     $currency     ISO 4217 currency code.
     * @param float      $amount
     * @param string     $method       Payment method (e.g. "card").
     * @param float      $fee
     * @param array|null $traceContext
     */
    public function trackTransaction($reference, $currency, $amount, $method, $fee, $traceContext = null)
    {
        try {
            $safeReference = $this->normalizeReference($reference);

            $payload = [
                'app_id' => $this->getAppId(),
                'reference' => $safeReference,
                'library' => self::LIBRARY,
                'currency' => (string) $currency,
                'amount' => (float) $amount,
                'fee' => (float) $fee,
                'method' => (string) $method,
            ];

            $payload['trace_context'] = $this->resolveTraceContext($traceContext, $safeReference);

            $this->queueEvent('app.transaction', $payload);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Fire the `app.error` event. Errors without a reference are throttled to
     * one per error code every ERROR_THROTTLE_TTL seconds.
     *
     * @param string      $errorCode    Short machine-readable error code.
     * @param string      $errorMessage Human-readable description.
     * @param string      $reference    Optional transaction reference for trace correlation.
     * @param array|null  $traceContext
     * @param string|null $stackTrace   Optional stack trace (truncated before send).
     */
    public function trackError($errorCode, $errorMessage, $reference = '', $traceContext = null, $stackTrace = null)
    {
        try {
            $safeReference = $this->normalizeReference((string) $reference);

            if ('' === $safeReference) {
                $throttleKey = self::ERROR_THROTTLE_KEY_PREFIX . md5((string) $errorCode);

                if ($this->stateGet($throttleKey)) {
                    return;
                }

                $this->stateSet($throttleKey, true, self::ERROR_THROTTLE_TTL);
            }

            $payload = [
                'app_id' => $this->getAppId(),
                'library' => self::LIBRARY,
                'library_version' => $this->libraryVersion(),
                'error_code' => (string) $errorCode,
                'error_message' => $this->truncate((string) $errorMessage, self::ERROR_MESSAGE_MAX_LENGTH),
            ];

            if (null !== $stackTrace && '' !== $stackTrace) {
                $payload['error_stacktrace'] = $this->truncate((string) $stackTrace, self::ERROR_STACKTRACE_MAX_LENGTH);
            }

            if ('' !== $safeReference) {
                $payload['reference'] = $safeReference;
            }

            $payload['trace_context'] = $this->resolveTraceContext($traceContext, $safeReference);

            $this->queueEvent('app.error', $payload);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    // --- Deferred dispatch --------------------------------------------------

    /**
     * Buffer an event for the shutdown flush. The timestamp is captured now,
     * not at send time, so events reflect when they actually happened.
     *
     * @param string $eventName
     * @param array  $data
     */
    private function queueEvent($eventName, array $data)
    {
        $this->pendingEvents[] = [$eventName, $data, $this->timestamp()];
        $this->deferToShutdown(null);
    }

    /**
     * @return array<int, array{0: string, 1: array, 2: string}>
     */
    public function getPendingEvents()
    {
        return $this->pendingEvents;
    }

    /**
     * Send every buffered event. Registered as a shutdown function; never throws.
     */
    public function flush()
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];
        $this->flushDeadline = $this->now() + self::FLUSH_BUDGET_MS / 1000;

        foreach ($events as $event) {
            if (null === $this->remainingMs(self::EVENT_TIMEOUT_MS)) {
                break; // Budget spent — drop the rest rather than hold the worker.
            }

            try {
                $this->sendNow($event[0], $event[1], $event[2]);
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        $this->flushDeadline = null;

        try {
            $this->purgeExpiredState();
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Register the shutdown flush once per request, optionally with extra work.
     * Controllers end with exit/die and Tools::redirect, which still run
     * shutdown functions.
     *
     * @param callable|null $task
     */
    private function deferToShutdown($task)
    {
        if (null !== $task) {
            register_shutdown_function(function () use ($task) {
                $this->releaseClient();
                try {
                    $task();
                } catch (\Throwable $e) {
                    unset($e);
                }
            });
        }

        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function(function () {
            if (empty($this->pendingEvents)) {
                return;
            }
            $this->releaseClient();
            $this->flush();
        });
    }

    /**
     * Finish the HTTP response so the shopper never waits on SigNoz.
     */
    protected function releaseClient()
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    // --- Transport: health gate + circuit breaker + retry -------------------

    /**
     * @param string $eventName
     * @param array  $data
     * @param string $timestamp
     * @return array|null Decoded JSON response body, or null when the event was
     *                    dropped, failed, or returned a non-JSON body.
     */
    private function sendNow($eventName, array $data, $timestamp)
    {
        try {
            // 1. Circuit breaker gate: if open, drop the event immediately.
            if ($this->isCircuitOpen()) {
                return null;
            }

            // 2. Health gate (cached for HEALTH_CACHE_TTL). When the circuit has
            // just left cooldown this is the half-open probe.
            $healthy = $this->isServiceHealthy();
            if (null === $healthy) {
                return null; // Ran out of flush budget; no verdict on the service.
            }
            if (!$healthy) {
                $this->recordFailure();
                return null;
            }

            return $this->sendWithRetry($eventName, $data, $timestamp);
        } catch (\Throwable $e) {
            unset($e);
        }

        return null;
    }

    /**
     * POST the event. Blocking sends (app.created) retry 503s with jittered
     * backoff; sends during a flush get one attempt within the flush budget.
     *
     * @param string $eventName
     * @param array  $data
     * @param string $timestamp
     * @return array|null
     */
    private function sendWithRetry($eventName, array $data, $timestamp)
    {
        $body = json_encode([
            'name' => $eventName,
            'data' => $data,
            'timestamp' => $timestamp,
        ]);

        if (false === $body) {
            return null;
        }

        $flushing = null !== $this->flushDeadline;
        $maxAttempts = $flushing ? 1 : self::MAX_ATTEMPTS;
        $defaultTimeoutMs = $flushing ? self::EVENT_TIMEOUT_MS : self::BLOCKING_TIMEOUT_MS;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            $timeoutMs = $this->remainingMs($defaultTimeoutMs);
            if (null === $timeoutMs) {
                return null; // Out of budget; not the service's fault.
            }

            list($response, $statusCode, $error) = $this->sendHttpRequest(
                'POST',
                self::BASE_URL . '/events',
                ['Content-Type: application/json', 'Accept: application/json'],
                $body,
                $timeoutMs
            );

            // Network/transport error (including a slow response hitting the
            // full timeout): count as a failure, do not retry. A timeout we
            // shortened to fit the budget says nothing about the service.
            if ('' !== (string) $error) {
                if ($timeoutMs >= $defaultTimeoutMs) {
                    $this->recordFailure();
                }
                return null;
            }

            $statusCode = (int) $statusCode;

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->recordSuccess();
                $decoded = json_decode((string) $response, true);
                return is_array($decoded) ? $decoded : null;
            }

            // Only 503 (service temporarily unavailable) is retried.
            if (503 === $statusCode && $attempt < $maxAttempts) {
                $this->backoffSleep($attempt);
                continue;
            }

            $this->recordFailure();
            return null;
        }

        $this->recordFailure();
        return null;
    }

    /**
     * Time left for one HTTP call, capped at $defaultMs. Outside a flush there
     * is no budget and the default applies.
     *
     * @param int $defaultMs
     * @return int|null Milliseconds, or null when the flush budget is spent.
     */
    private function remainingMs($defaultMs)
    {
        if (null === $this->flushDeadline) {
            return $defaultMs;
        }

        $left = (int) floor(($this->flushDeadline - $this->now()) * 1000);

        // Too little time left for a meaningful request.
        return $left < 100 ? null : min($defaultMs, $left);
    }

    /**
     * @return float
     */
    protected function now()
    {
        return microtime(true);
    }

    /**
     * Exponential backoff with full jitter.
     *
     * @param int $attempt 1-indexed attempt number.
     */
    protected function backoffSleep($attempt)
    {
        $ceilingMs = min(self::MAX_DELAY_MS, self::BASE_DELAY_MS * (2 ** ($attempt - 1)));

        try {
            $delayMs = random_int(0, $ceilingMs);
        } catch (\Throwable $e) {
            $delayMs = $ceilingMs;
        }

        usleep($delayMs * 1000);
    }

    /**
     * GET /health/ready and require {"status":"ok"}. A pass is cached.
     *
     * @return bool|null null when the flush budget left no room for a fair check.
     */
    private function isServiceHealthy()
    {
        if ($this->stateGet(self::HEALTH_OK_KEY)) {
            return true;
        }

        try {
            $timeoutMs = $this->remainingMs(self::HEALTH_TIMEOUT_MS);
            if (null === $timeoutMs) {
                return null;
            }

            list($response, $statusCode, $error) = $this->sendHttpRequest(
                'GET',
                self::BASE_URL . self::HEALTH_PATH,
                ['Accept: application/json'],
                null,
                $timeoutMs
            );

            if ('' !== (string) $error && $timeoutMs < self::HEALTH_TIMEOUT_MS) {
                return null;
            }

            if ('' !== (string) $error || 200 !== (int) $statusCode) {
                return false;
            }

            $result = json_decode((string) $response, true);
            $healthy = is_array($result) && isset($result['status']) && 'ok' === $result['status'];

            if ($healthy) {
                $this->stateSet(self::HEALTH_OK_KEY, true, self::HEALTH_CACHE_TTL);
            }

            return $healthy;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return bool
     */
    private function isCircuitOpen()
    {
        return (bool) $this->stateGet(self::CB_OPEN_KEY);
    }

    private function recordSuccess()
    {
        $this->stateDelete(self::CB_FAILURES_KEY);
        $this->stateDelete(self::CB_OPEN_KEY);
    }

    private function recordFailure()
    {
        $failures = (int) $this->stateGet(self::CB_FAILURES_KEY) + 1;
        $this->stateSet(self::CB_FAILURES_KEY, $failures, self::CB_OPEN_TTL * 2);

        if ($failures >= self::CB_FAILURE_THRESHOLD) {
            $this->openCircuit();
        }
    }

    /**
     * Open the circuit and drop the cached health status so the first attempt
     * after cooldown re-probes /health/ready (half-open).
     */
    private function openCircuit()
    {
        $this->stateSet(self::CB_OPEN_KEY, true, self::CB_OPEN_TTL);
        $this->stateDelete(self::CB_FAILURES_KEY);
        $this->stateDelete(self::HEALTH_OK_KEY);
    }

    // --- Helpers --------------------------------------------------------------

    /**
     * @param string $appId
     * @return string
     */
    private function normalizeAppId($appId)
    {
        $normalized = preg_replace('/\s+/', '-', trim($appId));
        return null !== $normalized ? $normalized : $appId;
    }

    /**
     * Restrict references to [A-Za-z0-9_-] so state keys and payloads are stable.
     *
     * @param string $reference
     * @return string
     */
    private function normalizeReference($reference)
    {
        $normalized = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) $reference));

        if (null === $normalized) {
            return (string) $reference;
        }

        return trim($normalized, '-');
    }

    /**
     * @param string $value
     * @param int    $maxLength
     * @return string
     */
    private function truncate($value, $maxLength)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value) <= $maxLength ? $value : mb_substr($value, 0, $maxLength);
        }

        return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
    }

    /**
     * @return string
     */
    private function timestamp()
    {
        return gmdate('Y-m-d\TH:i:s.000\Z');
    }

    /**
     * @return string
     */
    protected function libraryVersion()
    {
        if (class_exists('Module', false)) {
            $module = \Module::getInstanceByName('flutterwavepayment');
            if ($module && !empty($module->version)) {
                return (string) $module->version;
            }
        }

        return self::LIBRARY_VERSION_FALLBACK;
    }

    // --- Platform adapters (overridden in tests) ------------------------------

    /**
     * @param string $key
     * @return mixed
     */
    protected function getConfig($key)
    {
        return \Configuration::get($key);
    }

    /**
     * @param string $key
     * @param mixed  $value
     */
    protected function updateConfig($key, $value)
    {
        \Configuration::updateValue($key, $value);
    }

    /**
     * Read a value from the TTL state table.
     *
     * @param string $name
     * @return mixed|null
     */
    protected function stateGet($name)
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `value`, `expires_at` FROM `' . _DB_PREFIX_ . self::STATE_TABLE . '`
            WHERE `name` = "' . pSQL($name) . '"',
            false
        );

        if (!$row || (int) $row['expires_at'] < time()) {
            return null;
        }

        return json_decode($row['value'], true);
    }

    /**
     * @param string $name
     * @param mixed  $value
     * @param int    $ttl   Seconds.
     */
    protected function stateSet($name, $value, $ttl)
    {
        \Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . self::STATE_TABLE . '` (`name`, `value`, `expires_at`)
            VALUES ("' . pSQL($name) . '", "' . pSQL(json_encode($value), true) . '", ' . (int) (time() + $ttl) . ')'
        );
    }

    /**
     * @param string $name
     */
    protected function stateDelete($name)
    {
        \Db::getInstance()->delete(self::STATE_TABLE, '`name` = "' . pSQL($name) . '"');
    }

    protected function purgeExpiredState()
    {
        \Db::getInstance()->delete(self::STATE_TABLE, '`expires_at` < ' . (int) time());
    }

    /**
     * @param string      $method
     * @param string      $url
     * @param array       $headers
     * @param string|null $body
     * @param int         $timeoutMs Total and connect timeout in milliseconds.
     * @return array [response body, HTTP status code, transport error]
     */
    protected function sendHttpRequest($method, $url, array $headers, $body, $timeoutMs)
    {
        $ch = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            // Sub-second timeouts need this, or libcurl's signal-based
            // resolver ignores them.
            CURLOPT_NOSIGNAL => true,
        ];

        if ('POST' === $method) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        return [$response, $httpCode, $error];
    }
}
