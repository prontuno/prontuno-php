<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;
use Prontuno\Exceptions\ConfigurationException;
use Prontuno\Exceptions\ConnectionException;
use Prontuno\HttpClient;

abstract class Resource
{
    private const int DEFAULT_MAX_PAGES = 1_000;

    public function __construct(protected readonly HttpClient $client) {}

    protected static function path(string $resource, string $id, string $suffix = ''): string
    {
        if ($id === '') {
            throw new ConfigurationException("A {$resource} identifier is required.");
        }

        return $resource.'/'.rawurlencode($id).$suffix;
    }

    /**
     * Follows `pagination.next_cursor` and yields every item, guarding against cursor loops.
     *
     * @param  array<string, string|int|null>  $query
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    protected function paginate(string $path, array $query, ?int $maxPages): Generator
    {
        $maxPages ??= self::DEFAULT_MAX_PAGES;

        if ($maxPages <= 0) {
            throw new ConfigurationException('maxPages must be a positive integer.');
        }

        $cursor = is_string($query['cursor'] ?? null) ? $query['cursor'] : null;
        $seen = $cursor === null ? [] : [$cursor => true];

        for ($pages = 1; ; $pages++) {
            $page = $this->client->request('GET', $path, ['cursor' => $cursor] + $query);

            $data = is_array($page['data'] ?? null) ? $page['data'] : [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    yield $item;
                }
            }

            $pagination = is_array($page['pagination'] ?? null) ? $page['pagination'] : [];

            if (($pagination['has_more'] ?? false) !== true) {
                return;
            }

            if ($pages >= $maxPages) {
                throw new ConnectionException("Prontuno API pagination exceeded the maximum of {$maxPages} pages.");
            }

            $cursor = $pagination['next_cursor'] ?? null;

            if (! is_string($cursor) || $cursor === '') {
                throw new ConnectionException('Prontuno API pagination indicated more results without a next cursor.');
            }

            if (isset($seen[$cursor])) {
                throw new ConnectionException('Prontuno API returned a repeated pagination cursor.');
            }

            $seen[$cursor] = true;
        }
    }
}
