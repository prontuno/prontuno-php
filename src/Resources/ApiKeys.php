<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;

final class ApiKeys extends Resource
{
    /**
     * Create an API key. Its scopes are derived from `$type`: `sending` keys send from
     * `$domainId`; `mcp` keys manage the account and also send when `$allowEmailSending`
     * is true; `management` keys can also manage API keys and SMTP credentials.
     *
     * The response includes the plaintext `key`, which is returned only once.
     *
     * @return array<string, mixed>
     */
    public function create(
        string $name,
        string $type,
        ?string $domainId = null,
        bool $allowEmailSending = false,
    ): array {
        $body = ['name' => $name, 'type' => $type];

        if ($domainId !== null) {
            $body['domain_id'] = $domainId;
        }

        if ($allowEmailSending) {
            $body['allow_email_sending'] = true;
        }

        return $this->client->request('POST', 'api-keys', body: $body);
    }

    /**
     * Returns one API key, including revoked keys. The secret is never included.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $apiKeyId): array
    {
        return $this->client->request('GET', self::path('api-keys', $apiKeyId));
    }

    /**
     * Permanently revoke an API key. Requests using it fail immediately.
     */
    public function revoke(string $apiKeyId): void
    {
        $this->client->request('DELETE', self::path('api-keys', $apiKeyId));
    }

    /**
     * @param  'active'|'revoked'|'all'|null  $status  Defaults to `active` on the API.
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function list(?int $limit = null, ?string $cursor = null, ?string $status = null, ?string $type = null): array
    {
        /** @var array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}} */
        return $this->client->request('GET', 'api-keys', [
            'limit' => $limit,
            'cursor' => $cursor,
            'status' => $status,
            'type' => $type,
        ]);
    }

    /**
     * @param  'active'|'revoked'|'all'|null  $status
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function listAll(?int $limit = null, ?string $status = null, ?string $type = null, ?int $maxPages = null): Generator
    {
        return $this->paginate('api-keys', ['limit' => $limit, 'status' => $status, 'type' => $type], $maxPages);
    }
}
