<?php

namespace App\Services\Humanity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HumanityStaffClient implements HumanityStaffClientInterface
{
    private const TOKEN_KEY = 'humanity:access_token';
    private const REFRESH_KEY = 'humanity:refresh_token';

    /**
     * Humanity's application status codes. The v2 reference documents only HTTP
     * 200 and 400, and the official SDK sends suppress_response_codes=1 — so a
     * failure arrives as HTTP 200 with `status: 91`. Anything that trusts the
     * HTTP status treats a throttle or a permission denial as success.
     */
    private const SUCCESS = 1;
    private const THROTTLED = 91;

    private const MESSAGES = [
        -3 => 'Flagged API Key - Permanently Banned',
        -2 => 'Flagged API Key - Too Many invalid access attempts',
        -1 => 'Flagged API Key - Temporarily Disabled',
        2 => 'Invalid API key',
        3 => 'Invalid token key - Please re-authenticate',
        7 => 'Authentication Failed - You do not have permissions',
        8 => 'Missing parameters',
        9 => 'Invalid parameters (bad type)',
        12 => 'Create Failed',
        13 => 'Update Failed',
        15 => 'Get Failed',
        20 => 'Incorrect Permissions',
        90 => 'Suspended API key',
        91 => 'Throttle exceeded - max allowed requests. Try again later',
        99 => 'Service Offline',
    ];

    public function __construct()
    {
        if (blank(config('humanity.environment'))) {
            throw new HumanityException(
                'HUMANITY_ENV is not set. Refusing to talk to Humanity without an explicit environment.'
            );
        }
    }

    public function findByEid(string $eid): ?array
    {
        $rows = $this->request('get', 'employees/by-eid', ['eid' => $eid], 'find employee by eid', allowMissing: true);

        return $this->firstRow($rows);
    }

    public function findByEmail(string $email): ?array
    {
        // There is no by-email lookup, so this scans the roster. Only used by
        // the backfill and as a pre-check before creating, because a duplicate
        // email returns nothing more useful than the generic code 12.
        foreach ($this->listEmployees() as $employee) {
            $candidate = strtolower(trim((string) ($employee['email'] ?? '')));

            if ($candidate !== '' && $candidate === strtolower(trim($email))) {
                return $employee;
            }
        }

        return null;
    }

    public function listEmployees(bool $includeInactive = true): array
    {
        $query = $includeInactive ? ['disabled' => 1, 'inactive' => 1] : [];

        return array_values(array_filter(
            $this->request('get', 'employees', $query, 'list employees'),
            'is_array'
        ));
    }

    public function createEmployee(array $payload): array
    {
        $this->assertWritesEnabled('create employee');

        $rows = $this->request('post', 'employees', $payload, 'create employee');
        $row = $this->firstRow($rows);

        if ($row === null || $this->extractId($row) === null) {
            throw new HumanityException('Humanity create employee returned no id.');
        }

        return $row;
    }

    public function updateEmployee(string $humanityEmployeeId, array $payload): array
    {
        $this->assertWritesEnabled('update employee');

        $rows = $this->request('put', "employees/{$humanityEmployeeId}", $payload, 'update employee');

        return $this->firstRow($rows) ?? ['id' => $humanityEmployeeId];
    }

    public function extractId(array $row): ?string
    {
        foreach (['id', 'employee_id', 'user_id'] as $key) {
            $value = $row[$key] ?? null;

            if ($value !== null && $value !== '' && !is_array($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- plumbing

    private function request(string $method, string $path, array $payload, string $context, bool $allowMissing = false): array
    {
        $response = $this->send($method, $path, $payload);

        // The token may have been revoked mid-flight; re-auth once.
        if ($response['http'] === 401 || in_array($response['status'], [2, 3], true)) {
            Cache::forget(self::TOKEN_KEY);
            $response = $this->send($method, $path, $payload);
        }

        if ($allowMissing && ($response['http'] === 404 || $response['status'] === 15)) {
            return [];
        }

        $this->throwIfFailed($response, $context);

        return $this->unwrap($response['body']);
    }

    private function send(string $method, string $path, array $payload): array
    {
        $url = rtrim((string) config('humanity.base_url'), '/') . '/' . ltrim($path, '/');

        $request = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout((int) config('humanity.timeout', 5));

        // A POST is never auto-retried: Humanity has no idempotency key, so a
        // timed-out create may well have landed, and retrying blind would
        // duplicate the person. The eid lookup is the recovery path instead.
        if ($method !== 'post') {
            $retries = (int) config('humanity.retries', 1);

            if ($retries > 0) {
                $request = $request->retry($retries, (int) config('humanity.retry_ms', 200), throw: false);
            }
        }

        $response = $request->{$method}($url, $payload);

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $status = $body['status'] ?? null;

        return [
            'http' => $response->status(),
            'status' => is_numeric($status) ? (int) $status : null,
            'body' => $body,
        ];
    }

    private function throwIfFailed(array $response, string $context): void
    {
        $status = $response['status'];

        if ($status === self::SUCCESS) {
            return;
        }

        if ($status === null && $response['http'] >= 200 && $response['http'] < 300) {
            return;
        }

        $message = $response['body']['error']
            ?? $response['body']['message']
            ?? (self::MESSAGES[$status] ?? "HTTP {$response['http']}");

        // Unlike the auth-server client in this codebase, this LOGS and THROWS.
        // Swallowing here would let a failed push commit an employee that does
        // not exist in Humanity.
        Log::error('Humanity staff call failed', [
            'context' => $context,
            'http_status' => $response['http'],
            'humanity_status' => $status,
            'message' => $message,
        ]);

        throw new HumanityException(
            "Humanity {$context} failed: {$message}" . ($status === self::THROTTLED ? ' (rate limited)' : ''),
            $status,
            $response['http'],
            $response['body'],
        );
    }

    private function unwrap(array $body): array
    {
        $data = $body['data'] ?? $body;

        if (!is_array($data)) {
            return [];
        }

        if (isset($data['employees']) && is_array($data['employees'])) {
            return array_values($data['employees']);
        }

        if ($data !== [] && !array_is_list($data)) {
            return [$data];
        }

        return $data;
    }

    private function firstRow(array $rows): ?array
    {
        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::lock('humanity:oauth:lock', 20)->block(15, function () {
            $cached = Cache::get(self::TOKEN_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            return $this->requestToken();
        });
    }

    private function requestToken(): string
    {
        $config = config('humanity');

        foreach (['client_id', 'client_secret', 'username', 'password'] as $key) {
            if (empty($config[$key])) {
                // Fail fast and loudly on misconfiguration, matching the
                // auth-server middleware's abort-on-missing-config behaviour.
                throw new HumanityException("Humanity config missing: humanity.{$key}");
            }
        }

        $refreshToken = Cache::get(self::REFRESH_KEY);

        $body = $refreshToken
            ? [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]
            : [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type' => 'password',
                'username' => $config['username'],
                'password' => $config['password'],
                'redirect_uri' => $config['redirect_uri'] ?? '',
            ];

        $response = Http::asForm()->timeout((int) $config['timeout'])->post($config['token_url'], $body);
        $data = $response->json();

        if (!$response->successful() || !is_array($data) || empty($data['access_token'])) {
            if ($refreshToken) {
                Cache::forget(self::REFRESH_KEY);

                return $this->requestToken();
            }

            throw new HumanityException('Humanity token request failed: ' . $response->body(), httpStatus: $response->status());
        }

        Cache::put(self::TOKEN_KEY, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 60));

        if (!empty($data['refresh_token'])) {
            Cache::put(self::REFRESH_KEY, $data['refresh_token'], now()->addDays(25));
        }

        return $data['access_token'];
    }

    private function assertWritesEnabled(string $operation): void
    {
        if (!config('humanity.writes_enabled')) {
            throw new HumanityException(
                "Humanity writes are disabled (HUMANITY_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }
    }
}
