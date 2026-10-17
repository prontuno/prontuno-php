<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;
use Prontuno\Exceptions\ConfigurationException;

final class SentEmailAttachments extends Resource
{
    /**
     * One page of a sent email's attachments, each with a fresh one-hour `download_url`
     * (null while the file hasn't finished uploading).
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function list(string $emailId, ?int $limit = null, ?string $cursor = null): array
    {
        /** @var array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}} */
        return $this->client->request('GET', self::path('emails', $emailId, '/attachments'), [
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    /**
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function listAll(string $emailId, ?int $limit = null, ?int $maxPages = null): Generator
    {
        return $this->paginate(self::path('emails', $emailId, '/attachments'), ['limit' => $limit], $maxPages);
    }

    /**
     * Attachment metadata with a fresh signed `download_url` that expires after one hour.
     * Treat the URL as opaque: it may be served from a CDN or directly from storage.
     * Throws a 403 `attachment_unavailable` while the file hasn't finished uploading.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $emailId, string $attachmentId): array
    {
        if ($attachmentId === '') {
            throw new ConfigurationException('An attachment identifier is required.');
        }

        return $this->client->request('GET', self::path('emails', $emailId, '/attachments/'.rawurlencode($attachmentId)));
    }
}
