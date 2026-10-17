<?php

declare(strict_types=1);

namespace Prontuno\Webhooks;

final class WebhookEvent
{
    public const string EMAIL_SENT = 'email.sent';

    public const string EMAIL_REJECTED = 'email.rejected';

    public const string EMAIL_DEFERRED = 'email.deferred';

    public const string EMAIL_DELIVERED = 'email.delivered';

    public const string EMAIL_BOUNCED = 'email.bounced';

    public const string EMAIL_EXPIRED = 'email.expired';

    public const string EMAIL_COMPLAINED = 'email.complained';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $webhookId,
        public readonly string $type,
        public readonly string $createdAt,
        public readonly array $data,
        public readonly int $signatureTimestamp,
        private readonly array $payload,
    ) {}

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
