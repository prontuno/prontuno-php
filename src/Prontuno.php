<?php

declare(strict_types=1);

namespace Prontuno;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Prontuno\Exceptions\ConfigurationException;
use Prontuno\Resources\ApiKeys;
use Prontuno\Resources\Domains;
use Prontuno\Resources\Emails;
use Prontuno\Resources\SmtpCredentials;
use Prontuno\Resources\Suppressions;
use Prontuno\Resources\Webhooks;

final class Prontuno
{
    public const string VERSION = '0.1.0';

    public readonly Emails $emails;

    public readonly Domains $domains;

    public readonly Suppressions $suppressions;

    public readonly Webhooks $webhooks;

    public readonly ApiKeys $apiKeys;

    public readonly SmtpCredentials $smtpCredentials;

    private readonly HttpClient $client;

    private readonly ?string $apiKey;

    private readonly ?string $managementKey;

    /**
     * Pass one API key for every request, or separate keys:
     * `new Prontuno(sendingKey: $sending, managementKey: $management)`. Sends use the
     * sending key and every other request uses the management key. Role-specific keys
     * take precedence over `$apiKey`, and blank keys count as unset.
     *
     * @param  array{
     *     base_url?: string,
     *     timeout?: int|float,
     *     connect_timeout?: int|float,
     *     max_retries?: int,
     *     app_info?: string,
     *     http_client?: ClientInterface,
     *     sleep?: callable(float): void,
     * }  $options
     */
    public function __construct(
        ?string $apiKey = null,
        private readonly array $options = [],
        ?string $sendingKey = null,
        ?string $managementKey = null,
    ) {
        $unknown = array_diff(array_keys($options), [
            'base_url', 'timeout', 'connect_timeout', 'max_retries', 'app_info', 'http_client', 'sleep',
        ]);

        if ($unknown !== []) {
            throw new ConfigurationException('Unknown Prontuno option(s): '.implode(', ', $unknown).'.');
        }

        $this->apiKey = $apiKey;
        $this->managementKey = $managementKey;

        $this->client = new HttpClient(
            sendingKey: self::firstKey($sendingKey, $apiKey),
            managementKey: self::firstKey($managementKey, $apiKey),
            baseUrl: $options['base_url'] ?? self::baseUrlFromEnv() ?? HttpClient::DEFAULT_BASE_URL,
            timeout: (float) ($options['timeout'] ?? 30),
            connectTimeout: (float) ($options['connect_timeout'] ?? 10),
            maxRetries: $options['max_retries'] ?? 2,
            userAgent: self::userAgent($options['app_info'] ?? null),
            http: $options['http_client'] ?? new Client,
            sleep: $options['sleep'] ?? null,
        );

        $this->emails = new Emails($this->client);
        $this->domains = new Domains($this->client);
        $this->suppressions = new Suppressions($this->client);
        $this->webhooks = new Webhooks($this->client);
        $this->apiKeys = new ApiKeys($this->client);
        $this->smtpCredentials = new SmtpCredentials($this->client);
    }

    /**
     * Returns a client that sends with a different domain's sending key, keeping
     * every other setting, including the management key.
     */
    public function withSendingKey(string $sendingKey): self
    {
        if (trim($sendingKey) === '') {
            throw new ConfigurationException('withSendingKey() requires a non-empty sending key.');
        }

        return new self($this->apiKey, $this->options, $sendingKey, $this->managementKey);
    }

    /**
     * The SDK's own identifier always comes first, so requests can be traced to an SDK
     * version; `app_info` (for example "my-app/1.2.0") is added after it.
     */
    private static function userAgent(mixed $app_info): string
    {
        $user_agent = 'prontuno-php/'.self::VERSION;

        if ($app_info === null) {
            return $user_agent;
        }

        if (! is_string($app_info) || preg_match('/[\x00-\x1F\x7F]/', $app_info) === 1) {
            throw new ConfigurationException('app_info must be a single-line string.');
        }

        return trim($app_info) === '' ? $user_agent : $user_agent.' '.trim($app_info);
    }

    private static function firstKey(?string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if ($key !== null && trim($key) !== '') {
                return $key;
            }
        }

        return null;
    }

    private static function baseUrlFromEnv(): ?string
    {
        $value = getenv('PRONTUNO_BASE_URL');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Request ID and rate-limit headers from the most recent API response.
     */
    public function lastResponse(): ?ResponseMetadata
    {
        return $this->client->lastResponse();
    }
}
