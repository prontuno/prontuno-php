<?php

declare(strict_types=1);

namespace Prontuno\Webhooks;

use InvalidArgumentException;
use JsonException;
use Prontuno\Webhooks\Exceptions\InvalidPayloadException;
use Prontuno\Webhooks\Exceptions\InvalidSignatureException;
use Prontuno\Webhooks\Exceptions\StaleWebhookException;
use Prontuno\Webhooks\Exceptions\WebhookVerificationException;

/**
 * Verifies `Prontuno-Signature: t=<unix-seconds>,v1=<hex HMAC-SHA256 of "t.body">` headers.
 */
final class WebhookVerifier
{
    public const string SIGNATURE_HEADER = 'Prontuno-Signature';

    public const int DEFAULT_TOLERANCE = 300;

    /**
     * @param  int  $tolerance  Maximum age of the signature in seconds; 0 disables the check.
     * @param  (callable(): int)|null  $clock  Returns the current Unix time.
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $tolerance = self::DEFAULT_TOLERANCE,
        private $clock = null,
    ) {
        if (trim($secret) === '') {
            throw new WebhookVerificationException('A Prontuno webhook signing secret is required.');
        }

        if ($tolerance < 0) {
            throw new InvalidArgumentException('Prontuno webhook timestamp tolerance cannot be negative.');
        }
    }

    /**
     * @param  string  $payload  The exact raw request body.
     */
    public function constructEvent(string $payload, string $signature): WebhookEvent
    {
        $timestamp = $this->verifiedTimestamp($payload, $signature);
        $decoded = $this->decodePayload($payload);

        return new WebhookEvent(
            eventId: $this->requiredString($decoded, 'event_id'),
            webhookId: $this->requiredString($decoded, 'webhook_id'),
            type: $this->requiredString($decoded, 'type'),
            createdAt: $this->requiredString($decoded, 'created_at'),
            data: $this->requiredData($decoded),
            signatureTimestamp: $timestamp,
            payload: $decoded,
        );
    }

    /**
     * @param  string  $payload  The exact raw request body.
     */
    public function verify(string $payload, string $signature): void
    {
        $this->verifiedTimestamp($payload, $signature);
    }

    private function verifiedTimestamp(string $payload, string $signature): int
    {
        [$timestamp, $signatures] = $this->parseSignature($signature);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $matches = false;
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, strtolower($candidate))) {
                $matches = true;

                break;
            }
        }

        if (! $matches) {
            throw new InvalidSignatureException('The Prontuno webhook signature is invalid.');
        }

        $now = $this->clock === null ? time() : ($this->clock)();

        if ($this->tolerance > 0 && abs($now - $timestamp) > $this->tolerance) {
            throw new StaleWebhookException('The Prontuno webhook signature timestamp is outside the allowed tolerance.');
        }

        return $timestamp;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function parseSignature(string $signature): array
    {
        if (trim($signature) === '') {
            throw new InvalidSignatureException('The Prontuno-Signature header is missing.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signature) as $part) {
            $segments = explode('=', trim($part), 2);

            if (count($segments) !== 2) {
                throw new InvalidSignatureException('The Prontuno webhook signature header is malformed.');
            }

            [$scheme, $value] = array_map('trim', $segments);

            if ($scheme === 't') {
                if ($timestamp !== null || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
                    throw new InvalidSignatureException('The Prontuno webhook signature timestamp is invalid.');
                }

                $timestamp = (int) $value;
            } elseif ($scheme === 'v1' && preg_match('/\A[a-f0-9]{64}\z/i', $value) === 1) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new InvalidSignatureException('The Prontuno webhook signature header must contain t and v1 values.');
        }

        return [$timestamp, $signatures];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidPayloadException('The Prontuno webhook payload is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidPayloadException('The Prontuno webhook payload must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidPayloadException("The Prontuno webhook payload requires a non-empty {$field} field.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requiredData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        // An empty JSON object decodes to [], so only non-empty lists are rejected.
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidPayloadException('The Prontuno webhook payload requires a data object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
