<?php

declare(strict_types=1);

namespace Prontuno;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Prontuno\Exceptions\ApiException;
use Prontuno\Exceptions\ConfigurationException;
use Prontuno\Exceptions\ConnectionException;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal Use the resource objects on {@see Prontuno} instead.
 */
final class HttpClient
{
    public const string DEFAULT_BASE_URL = 'https://api.prontuno.net/v1';

    private const int MAX_RETRY_DELAY_SECONDS = 60;

    private readonly string $baseUrl;

    private readonly Closure $sleep;

    private ?ResponseMetadata $lastResponse = null;

    private readonly ?string $sendingKey;

    private readonly ?string $managementKey;

    /**
     * @param  (callable(float): void)|null  $sleep  Receives the delay in seconds.
     */
    public function __construct(
        ?string $sendingKey,
        ?string $managementKey,
        string $baseUrl,
        private readonly float $timeout,
        private readonly float $connectTimeout,
        private readonly int $maxRetries,
        private readonly string $userAgent,
        private readonly ClientInterface $http = new Client,
        ?callable $sleep = null,
    ) {
        $this->sendingKey = self::normalizeKey($sendingKey);
        $this->managementKey = self::normalizeKey($managementKey);

        if ($this->sendingKey === null && $this->managementKey === null) {
            throw new ConfigurationException('A non-empty Prontuno API key is required (apiKey, sendingKey, or managementKey).');
        }

        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new ConfigurationException('Prontuno timeouts must be positive numbers.');
        }

        if ($maxRetries < 0) {
            throw new ConfigurationException('max_retries must be a non-negative integer.');
        }

        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->sleep = Closure::fromCallable($sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        });
    }

    public function lastResponse(): ?ResponseMetadata
    {
        return $this->lastResponse;
    }

    /**
     * @param  array<string, string|int|null>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        bool $sending = false,
    ): array {
        $apiKey = $sending ? $this->sendingKey : $this->managementKey;

        if ($apiKey === null) {
            [$role, $option] = $sending ? ['sending', 'sendingKey'] : ['management', 'managementKey'];

            throw new ConfigurationException(
                "{$method} /".ltrim($path, '/')." requires a {$role} API key; pass {$option} (or apiKey) when creating the client.",
            );
        }

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$apiKey,
            'User-Agent' => $this->userAgent,
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $options = [
            'headers' => $headers,
            'http_errors' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ];

        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($body !== null) {
            $options['json'] = $body;
        }

        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $retrySafe = $method === 'GET' || $method === 'DELETE' || ($idempotencyKey !== null && $idempotencyKey !== '');

        // Set once an attempt may have reached the API without a usable response, so a
        // retried DELETE that finds the resource already gone counts as success.
        $mayHaveApplied = false;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options);
            } catch (GuzzleException $exception) {
                if (! $retrySafe || $attempt >= $this->maxRetries) {
                    throw new ConnectionException('Could not connect to the Prontuno API: '.$exception->getMessage(), 0, $exception);
                }

                $mayHaveApplied = true;
                ($this->sleep)($this->retryDelay($attempt));

                continue;
            }

            $this->lastResponse = ResponseMetadata::fromResponse($response);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $this->decodeSuccess($response);
            }

            $exception = ApiException::fromResponse($response, $this->decodeError($response));

            if ($mayHaveApplied && $method === 'DELETE' && self::isAlreadyDeleted($exception)) {
                return [];
            }

            if (! $retrySafe || $attempt >= $this->maxRetries || ! self::isRetryableStatus($exception)) {
                throw $exception;
            }

            $mayHaveApplied = $mayHaveApplied || $status === 408 || $status >= 500;
            ($this->sleep)($this->retryDelay($attempt, $response));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSuccess(ResponseInterface $response): array
    {
        if ($response->getStatusCode() === 204) {
            return [];
        }

        $contents = (string) $response->getBody();

        if ($contents === '') {
            throw new ConnectionException('Prontuno API returned an empty response.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConnectionException('Prontuno API returned invalid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new ConnectionException('Prontuno API returned an unexpected response.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeError(ResponseInterface $response): array
    {
        $contents = (string) $response->getBody();

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $contents === ''
                ? []
                : ['error' => ['code' => 'invalid_response', 'message' => $contents, 'retryable' => false]];
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function normalizeKey(?string $key): ?string
    {
        return $key === null || trim($key) === '' ? null : trim($key);
    }

    /**
     * A DELETE retried after an attempt that may have succeeded finds the resource gone.
     */
    private static function isAlreadyDeleted(ApiException $exception): bool
    {
        return $exception->status() === 404
            || ($exception->status() === 409 && $exception->errorCode() === 'already_revoked');
    }

    private static function isRetryableStatus(ApiException $exception): bool
    {
        $status = $exception->status();

        return $status === 408
            || $status === 429
            || $status >= 500
            || ($status === 409 && $exception->errorCode() === 'idempotency_request_in_progress');
    }

    private function retryDelay(int $attempt, ?ResponseInterface $response = null): float
    {
        $retryAfter = $response?->getHeaderLine('Retry-After') ?? '';

        if (is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return min((float) $retryAfter, self::MAX_RETRY_DELAY_SECONDS);
        }

        if ($retryAfter !== '' && ($date = strtotime($retryAfter)) !== false) {
            return (float) min(max($date - time(), 0), self::MAX_RETRY_DELAY_SECONDS);
        }

        $base = min(0.5 * (2 ** $attempt), 5.0);

        return $base * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException('base_url must be a valid absolute URL.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $loopback)) {
            throw new ConfigurationException('base_url must use https (plain http is only allowed for loopback hosts).');
        }

        return rtrim($baseUrl, '/');
    }
}
