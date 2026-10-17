<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;
use Prontuno\HttpClient;

final class Emails extends Resource
{
    public readonly SentEmailAttachments $attachments;

    public function __construct(HttpClient $client)
    {
        parent::__construct($client);
        $this->attachments = new SentEmailAttachments($client);
    }

    /**
     * Send one email to up to 100 recipients across to, cc, and bcc.
     *
     * Pass an idempotency key so a retried request never sends twice; the SDK only
     * retries sends automatically when one is present.
     *
     * @param  array<string, mixed>  $email
     * @return array{emails: list<array<string, mixed>>}
     */
    public function send(array $email, ?string $idempotencyKey = null): array
    {
        /** @var array{emails: list<array<string, mixed>>} */
        return $this->client->request('POST', 'emails', body: $email, idempotencyKey: $idempotencyKey, sending: true);
    }

    /**
     * Send up to 1,000 separate emails in one request. If any item is invalid, nothing is sent.
     *
     * @param  list<array<string, mixed>>  $emails
     * @return array{emails: list<list<array<string, mixed>>>}
     */
    public function sendBatch(array $emails, ?string $idempotencyKey = null): array
    {
        /** @var array{emails: list<list<array<string, mixed>>>} */
        return $this->client->request('POST', 'emails/batch', body: ['emails' => $emails], idempotencyKey: $idempotencyKey, sending: true);
    }

    /**
     * One email with its status, timestamps, stored `text` and `html` bodies (null when
     * absent or unavailable), and `attachments` with fresh one-hour download links.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $emailId): array
    {
        return $this->client->request('GET', self::path('emails', $emailId));
    }

    /**
     * List one page of emails (one record per recipient).
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $domainId = null,
        ?string $status = null,
    ): array {
        /** @var array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}} */
        return $this->client->request('GET', 'emails', [
            'limit' => $limit,
            'cursor' => $cursor,
            'domain_id' => $domainId,
            'status' => $status,
        ]);
    }

    /**
     * Iterate every email across all pages.
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function listAll(
        ?int $limit = null,
        ?string $domainId = null,
        ?string $status = null,
        ?int $maxPages = null,
    ): Generator {
        return $this->paginate('emails', [
            'limit' => $limit,
            'domain_id' => $domainId,
            'status' => $status,
        ], $maxPages);
    }
}
