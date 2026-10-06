<?php

namespace Tests\Support;

use FlutterwavePayment\classes\FlutterwaveSignozLogger;

/**
 * SigNoz logger backed by in-memory config/state that records HTTP requests
 * and returns queued responses instead of calling the SigNoz service.
 */
class FakeSignozLogger extends FlutterwaveSignozLogger
{
    /** @var array<string, mixed> */
    public array $config = [];

    /** @var array<string, array{value: mixed, expires_at: int}> */
    public array $state = [];

    /** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
    public array $requests = [];

    /** @var array<int, array{0: string|false, 1: int, 2: string}> */
    private array $eventResponses = [];

    /** @var array{0: string|false, 1: int, 2: string} */
    private array $healthResponse = ['{"status":"ok"}', 200, ''];

    public int $sleeps = 0;

    /** Fake clock, in seconds. */
    public float $clock = 1000.0;

    /** Simulated latency (ms) of every HTTP call; a call slower than its timeout fails. */
    public int $latencyMs = 0;

    public function __construct()
    {
        parent::__construct();
    }

    public static function make(array $config = []): self
    {
        $logger = new self();
        $logger->config = $config;

        return $logger;
    }

    public function respondToEvents(array|string|false $body, int $httpCode = 200, string $error = ''): self
    {
        $this->eventResponses[] = [is_array($body) ? json_encode($body) : $body, $httpCode, $error];

        return $this;
    }

    public function respondToHealth(array|string|false $body, int $httpCode = 200, string $error = ''): self
    {
        $this->healthResponse = [is_array($body) ? json_encode($body) : $body, $httpCode, $error];

        return $this;
    }

    /** @return array<int, array{name: string, data: array, timestamp: string}> */
    public function sentEvents(): array
    {
        $events = [];
        foreach ($this->requests as $request) {
            if ($request['method'] === 'POST') {
                $events[] = json_decode($request['body'], true);
            }
        }

        return $events;
    }

    public function healthChecks(): int
    {
        return count(array_filter($this->requests, fn ($r) => str_ends_with($r['url'], self::HEALTH_PATH)));
    }

    protected function getConfig($key)
    {
        return $this->config[$key] ?? false;
    }

    protected function updateConfig($key, $value)
    {
        $this->config[$key] = $value;
    }

    protected function stateGet($name)
    {
        if (!isset($this->state[$name]) || $this->state[$name]['expires_at'] < time()) {
            return null;
        }

        return json_decode(json_encode($this->state[$name]['value']), true);
    }

    protected function stateSet($name, $value, $ttl)
    {
        $this->state[$name] = ['value' => $value, 'expires_at' => time() + $ttl];
    }

    protected function stateDelete($name)
    {
        unset($this->state[$name]);
    }

    protected function purgeExpiredState()
    {
        $this->state = array_filter($this->state, fn ($entry) => $entry['expires_at'] >= time());
    }

    protected function libraryVersion()
    {
        return '1.1.0';
    }

    protected function backoffSleep($attempt)
    {
        $this->sleeps++;
    }

    protected function now()
    {
        return $this->clock;
    }

    protected function sendHttpRequest($method, $url, array $headers, $body, $timeoutMs)
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeoutMs');

        if ($this->latencyMs > $timeoutMs) {
            $this->clock += $timeoutMs / 1000;
            return [false, 0, 'Operation timed out after ' . $timeoutMs . ' milliseconds'];
        }

        $this->clock += $this->latencyMs / 1000;

        if ($method === 'GET') {
            return $this->healthResponse;
        }

        if (!$this->eventResponses) {
            return ['{}', 200, ''];
        }

        return array_shift($this->eventResponses);
    }
}
