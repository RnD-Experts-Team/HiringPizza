<?php

namespace App\Services\Tcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TcpEmployeeClient implements TcpEmployeeClientInterface
{
    private const TOKEN_KEY = 'tcp:access_token';

    // No TCP_ENV guard: TCP has no sandbox, so the label could only describe
    // the one live account. Safety is driver=fake + writes_enabled=false
    // defaults + the EXTERNAL_WRITE_ALLOWED_STORES rollout allowlist.

    public function getEmployee(string $employeeId): ?array
    {
        $rows = $this->request('get', "employees/{$employeeId}", [], 'get employee', allowMissing: true);

        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    public function listEmployees(): array
    {
        $perPage = 50;
        $page = 1;
        $employees = [];

        do {
            $rows = $this->request('get', 'employees', [
                'perPage' => $perPage,
                'page' => $page,
            ], 'list employees');

            $employees = array_merge($employees, array_values(array_filter($rows, 'is_array')));
            $page++;
            // Fewer than a full page means the end — no total is returned.
        } while (count($rows) === $perPage && $page <= 200);

        return $employees;
    }

    public function createEmployee(array $payload): array
    {
        $this->assertWritesEnabled('create employee');

        // POST bodies are arrays of records on this API.
        $rows = $this->request('post', 'employees', [$payload], 'create employee');

        $first = $rows[0] ?? null;

        if (!is_array($first)) {
            throw new TcpException('TCP create employee returned no record.');
        }

        return $first;
    }

    public function updateEmployee(string $employeeId, array $payload): array
    {
        $this->assertWritesEnabled('update employee');

        $rows = $this->request('put', "employees/{$employeeId}", $payload, 'update employee');

        $first = $rows[0] ?? null;

        return is_array($first) ? $first : ['employeeId' => $employeeId];
    }

    public function listJobCodes(): array
    {
        return array_values(array_filter(
            $this->request('get', 'jobcodes', ['perPage' => 1000], 'list job codes'),
            'is_array'
        ));
    }

    // ---------------------------------------------------------------- plumbing

    private function request(string $method, string $path, array $payload, string $context, bool $allowMissing = false): array
    {
        $response = $this->send($method, $path, $payload);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_KEY);
            $response = $this->send($method, $path, $payload);
        }

        if ($allowMissing && $response->status() === 404) {
            return [];
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->status() === 429) {
            throw new TcpException(
                "TCP {$context} rate limited (60/min, 2500/day).",
                429,
                requestId: data_get($body, 'meta.requestId'),
            );
        }

        if (!$response->successful()) {
            $message = $this->summariseErrors($body['errors'] ?? []) ?: "HTTP {$response->status()}";

            // Logs AND throws. Swallowing would let a failed push commit an
            // employee that does not exist in TCP.
            //
            // The RAW error array is logged because TCP's field-naming key is
            // not stable across endpoints — "The cell must have a value" tells
            // you a required field is empty but not which one, and summarising
            // it can drop the only clue. Payload keys (never values: this
            // carries names, email, address) show what we actually sent.
            Log::error('TCP employee call failed', [
                'context' => $context,
                'http_status' => $response->status(),
                'request_id' => data_get($body, 'meta.requestId'),
                'message' => $message,
                'raw_errors' => $body['errors'] ?? null,
                'sent_fields' => $this->describePayloadShape($payload),
            ]);

            throw new TcpException(
                "TCP {$context} failed: {$message}",
                $response->status(),
                (array) ($body['errors'] ?? []),
                data_get($body, 'meta.requestId'),
            );
        }

        // TCP reports partial failures in `errors` while still returning 2xx,
        // so a success status alone is not proof the write landed.
        $errors = $body['errors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            throw new TcpException(
                "TCP {$context} reported errors: " . $this->summariseErrors($errors),
                $response->status(),
                $errors,
                data_get($body, 'meta.requestId'),
            );
        }

        $data = $body['data'] ?? $body;

        if (!is_array($data)) {
            return [];
        }

        return array_is_list($data) ? $data : [$data];
    }

    private function send(string $method, string $path, array $payload)
    {
        $url = rtrim((string) config('tcp.base_url'), '/') . '/' . ltrim($path, '/');

        $request = Http::withToken($this->accessToken())
            ->withHeaders(array_filter([
                'x-api-key' => config('tcp.api_key'),
                'X-Tcp-CompanyId' => (string) config('tcp.company_id', '1'),
            ]))
            ->acceptJson()
            ->timeout((int) config('tcp.timeout', 5));

        // A POST is never auto-retried: TCP has no idempotency key, so a
        // timed-out create may have landed. The exportCode roster scan in
        // TcpEmployeeSyncService::resolveRemoteId is the recovery path instead.
        if ($method !== 'post') {
            $retries = (int) config('tcp.retries', 1);

            if ($retries > 0) {
                $request = $request->retry($retries, (int) config('tcp.retry_ms', 200), throw: false);
            }
        }

        return $method === 'get'
            ? $request->get($url, $payload)
            : $request->{$method}($url, $payload);
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::lock('tcp:oauth:lock', 20)->block(15, function () {
            $cached = Cache::get(self::TOKEN_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            return $this->requestToken();
        });
    }

    private function requestToken(): string
    {
        $config = (array) config('tcp');

        foreach (['client_id', 'client_secret'] as $key) {
            if (empty($config[$key])) {
                throw new TcpException("TCP config missing: tcp.{$key}");
            }
        }

        $response = Http::asForm()
            ->timeout((int) ($config['timeout'] ?? 5))
            ->post($config['token_url'], [
                'grant_type' => 'client_credentials',
                'scope' => $config['scope'],
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ]);

        $data = $response->json();

        if (!$response->successful() || !is_array($data) || empty($data['access_token'])) {
            throw new TcpException('TCP token request failed: ' . $response->body(), $response->status());
        }

        Cache::put(self::TOKEN_KEY, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 60));

        return $data['access_token'];
    }

    private function summariseErrors(mixed $errors): string
    {
        if (!is_array($errors) || $errors === []) {
            return '';
        }

        return implode('; ', array_map(function ($error) {
            if (is_string($error)) {
                return $error;
            }

            if (!is_array($error)) {
                return 'unknown error';
            }

            // TCP is not consistent about what it calls the offending field,
            // and losing it turns a precise complaint into "The cell must have
            // a value" with no way to act on it. Try every spelling seen.
            $field = null;

            foreach (['field', 'property', 'propertyName', 'column', 'columnName', 'name', 'target', 'path'] as $key) {
                $candidate = $error[$key] ?? null;

                if (is_string($candidate) && trim($candidate) !== '') {
                    $field = trim($candidate);

                    break;
                }
            }

            $text = $error['message'] ?? $error['description'] ?? $error['detail'] ?? null;

            return trim(implode(' ', array_filter([
                $field === null ? null : "[{$field}]",
                is_string($text) ? $text : null,
            ])));
        }, array_slice($errors, 0, 5)));
    }

    /**
     * Field names and which of them we sent empty — never the values, since
     * this payload carries a person's name, email and address.
     *
     * @return array{fields:array<int,string>, empty:array<int,string>}
     */
    private function describePayloadShape(array $payload): array
    {
        // POST bodies are arrays of records; inspect the first.
        $record = array_is_list($payload) ? ($payload[0] ?? []) : $payload;

        if (!is_array($record)) {
            return ['fields' => [], 'empty' => []];
        }

        $empty = array_keys(array_filter(
            $record,
            fn ($value) => $value === null || $value === '' || $value === []
        ));

        return [
            'fields' => array_keys($record),
            'empty' => array_values($empty),
        ];
    }

    private function assertWritesEnabled(string $operation): void
    {
        if (!config('tcp.writes_enabled')) {
            throw new TcpException(
                "TCP writes are disabled (TCP_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }
    }
}
