<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;
use Prontuno\Webhooks\WebhookEvent;
use Prontuno\Webhooks\WebhookVerifier;

final class Webhooks extends Resource
{
    /**
     * Create a webhook. The response includes the signing `secret`, which is returned only once.
     *
     * Pass `domainId` to scope it to one verified domain; omit it for a team-wide webhook.
     * Omitted optional settings use the API defaults.
     *
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    public function create(
        string $name,
        string $url,
        array $events,
        ?string $domainId = null,
        ?bool $isGlobal = null,
        ?bool $isEnabled = null,
    ): array {
        $body = array_filter([
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'domain_id' => $domainId,
            'is_global' => $isGlobal,
            'is_enabled' => $isEnabled,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->client->request('POST', 'webhooks', body: $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $webhookId): array
    {
        return $this->client->request('GET', self::path('webhooks', $webhookId));
    }

    /**
     * Change only the fields you pass; other settings are kept. To switch between all domains
     * and one domain, set `is_global` too: `['is_global' => true, 'domain_id' => null]` or
     * `['is_global' => false, 'domain_id' => 'dom_...']`.
     *
     * @param  array{name?: string, url?: string, events?: list<string>, domain_id?: string|null, is_global?: bool, is_enabled?: bool}  $changes
     * @return array<string, mixed>
     */
    public function update(string $webhookId, array $changes): array
    {
        return $this->client->request('PATCH', self::path('webhooks', $webhookId), body: $changes);
    }

    public function delete(string $webhookId): void
    {
        $this->client->request('DELETE', self::path('webhooks', $webhookId));
    }

    /**
     * Replace the signing secret. The old secret stops working immediately.
     *
     * @return array{id: string, secret: string}
     */
    public function rotateSecret(string $webhookId): array
    {
        /** @var array{id: string, secret: string} */
        return $this->client->request('POST', self::path('webhooks', $webhookId, '/rotate-secret'));
    }

    /**
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function list(?int $limit = null, ?string $cursor = null): array
    {
        /** @var array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}} */
        return $this->client->request('GET', 'webhooks', ['limit' => $limit, 'cursor' => $cursor]);
    }

    /**
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function listAll(?int $limit = null, ?int $maxPages = null): Generator
    {
        return $this->paginate('webhooks', ['limit' => $limit], $maxPages);
    }

    /**
     * Verify a delivery's `Prontuno-Signature` header against the exact raw request body.
     */
    public function verifySignature(string $payload, string $signature, string $secret, int $tolerance = WebhookVerifier::DEFAULT_TOLERANCE): void
    {
        (new WebhookVerifier($secret, $tolerance))->verify($payload, $signature);
    }

    /**
     * Verify a delivery and decode it into a {@see WebhookEvent}.
     */
    public function constructEvent(string $payload, string $signature, string $secret, int $tolerance = WebhookVerifier::DEFAULT_TOLERANCE): WebhookEvent
    {
        return (new WebhookVerifier($secret, $tolerance))->constructEvent($payload, $signature);
    }
}
