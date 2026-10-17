<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;

final class Suppressions extends Resource
{
    /**
     * Add a manual suppression. Returns 409 `suppression_exists` if the address is already suppressed.
     *
     * @return array<string, mixed>
     */
    public function create(string $email): array
    {
        return $this->client->request('POST', 'suppressions', body: ['email' => $email]);
    }

    /**
     * @param  string  $suppressionIdOrEmail  A `sup_` ID or the suppressed email address.
     * @return array<string, mixed>
     */
    public function retrieve(string $suppressionIdOrEmail): array
    {
        return $this->client->request('GET', self::path('suppressions', $suppressionIdOrEmail));
    }

    /**
     * Remove a manual suppression. Hard-bounce and complaint suppressions can't be removed.
     *
     * @param  string  $suppressionIdOrEmail  A `sup_` ID or the suppressed email address.
     */
    public function delete(string $suppressionIdOrEmail): void
    {
        $this->client->request('DELETE', self::path('suppressions', $suppressionIdOrEmail));
    }

    /**
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function list(?int $limit = null, ?string $cursor = null, ?string $email = null): array
    {
        /** @var array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}} */
        return $this->client->request('GET', 'suppressions', [
            'limit' => $limit,
            'cursor' => $cursor,
            'email' => $email,
        ]);
    }

    /**
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function listAll(?int $limit = null, ?string $email = null, ?int $maxPages = null): Generator
    {
        return $this->paginate('suppressions', ['limit' => $limit, 'email' => $email], $maxPages);
    }
}
