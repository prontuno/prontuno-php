<?php

declare(strict_types=1);

namespace Prontuno\Resources;

use Generator;

final class SmtpCredentials extends Resource
{
    /**
     * Create an SMTP username and password for sending from one verified domain.
     * The response includes the `password`, which is returned only once.
     *
     * @return array<string, mixed>
     */
    public function create(string $name, string $domainId): array
    {
        return $this->client->request('POST', 'smtp-credentials', body: [
            'name' => $name,
            'domain_id' => $domainId,
        ]);
    }

    /**
     * Returns one SMTP credential, including revoked ones. The password is never included.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $smtpCredentialId): array
    {
        return $this->client->request('GET', self::path('smtp-credentials', $smtpCredentialId));
    }

    /**
     * Permanently revoke an SMTP credential. SMTP logins using it fail immediately.
     */
    public function revoke(string $smtpCredentialId): void
    {
        $this->client->request('DELETE', self::path('smtp-credentials', $smtpCredentialId));
    }

    /**
     * Replace the password. The old password stops working immediately, and the
     * response includes the new `password`, which is returned only once.
     *
     * @return array<string, mixed>
     */
    public function resetPassword(string $smtpCredentialId): array
    {
        return $this->client->request('POST', self::path('smtp-credentials', $smtpCredentialId, '/reset-password'));
    }

    /**
     * @param  'active'|'revoked'|'all'|null  $status  Defaults to `active` on the API.
     * @return array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function list(?int $limit = null, ?string $cursor = null, ?string $status = null): array
    {
        /** @var array{data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}} */
        return $this->client->request('GET', 'smtp-credentials', [
            'limit' => $limit,
            'cursor' => $cursor,
            'status' => $status,
        ]);
    }

    /**
     * @param  'active'|'revoked'|'all'|null  $status
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function listAll(?int $limit = null, ?string $status = null, ?int $maxPages = null): Generator
    {
        return $this->paginate('smtp-credentials', ['limit' => $limit, 'status' => $status], $maxPages);
    }
}
