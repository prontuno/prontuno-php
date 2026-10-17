<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;

final class Domains extends Resource
{
    /**
     * Add a sending domain. The response includes the DNS records to publish.
     *
     * @return array<string, mixed>
     */
    public function create(string $domain): array
    {
        return $this->client->request('POST', 'domains', body: ['domain' => $domain]);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $domainId): array
    {
        return $this->client->request('GET', self::path('domains', $domainId));
    }

    /**
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function list(?int $limit = null, ?string $cursor = null): array
    {
        /** @var array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}} */
        return $this->client->request('GET', 'domains', ['limit' => $limit, 'cursor' => $cursor]);
    }

    /**
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function listAll(?int $limit = null, ?int $maxPages = null): Generator
    {
        return $this->paginate('domains', ['limit' => $limit], $maxPages);
    }
}
