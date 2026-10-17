<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use Prontuno\Prontuno;
use Prontuno\Webhooks\Exceptions\InvalidPayloadException;
use Prontuno\Webhooks\Exceptions\InvalidSignatureException;
use Prontuno\Webhooks\Exceptions\StaleWebhookException;
use Prontuno\Webhooks\Exceptions\WebhookVerificationException;
use Prontuno\Webhooks\WebhookEvent;
use Prontuno\Webhooks\WebhookVerifier;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const TIMESTAMP = 1_719_230_400;

    private static function payload(string $type = 'email.delivered'): string
    {
        return json_encode([
            'event_id' => 'evt_1',
            'webhook_id' => 'whk_1',
            'type' => $type,
            'created_at' => '2026-06-28T11:20:58.412Z',
            'data' => ['email_id' => 'email_1'],
        ], JSON_THROW_ON_ERROR);
    }

    private static function sign(string $payload, int $timestamp = self::TIMESTAMP): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);
    }

    private static function verifier(int $now = self::TIMESTAMP): WebhookVerifier
    {
        return new WebhookVerifier(self::SECRET, clock: static fn (): int => $now);
    }

    public function test_it_constructs_a_verified_event(): void
    {
        $payload = self::payload(WebhookEvent::EMAIL_COMPLAINED);
        $event = self::verifier()->constructEvent($payload, self::sign($payload));

        $this->assertTrue($event->is(WebhookEvent::EMAIL_COMPLAINED));
        $this->assertSame('evt_1', $event->eventId);
        $this->assertSame('email_1', $event->data['email_id']);
        $this->assertSame(self::TIMESTAMP, $event->signatureTimestamp);
    }

    public function test_it_accepts_any_matching_v1_signature(): void
    {
        $payload = self::payload();
        $signature = 't='.self::TIMESTAMP.',v1='.str_repeat('0', 64).','.explode(',', self::sign($payload))[1];

        self::verifier()->verify($payload, $signature);
        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_tampered_payloads(): void
    {
        $payload = self::payload();

        $this->expectException(InvalidSignatureException::class);
        self::verifier()->verify($payload.' ', self::sign($payload));
    }

    public function test_it_rejects_stale_signatures(): void
    {
        $payload = self::payload();

        $this->expectException(StaleWebhookException::class);
        self::verifier(self::TIMESTAMP + 301)->verify($payload, self::sign($payload));
    }

    public function test_it_rejects_malformed_headers(): void
    {
        foreach (['', 'garbage', 't=abc,v1='.str_repeat('a', 64), 't=1'] as $header) {
            try {
                self::verifier()->verify(self::payload(), $header);
                $this->fail("Expected [{$header}] to be rejected.");
            } catch (InvalidSignatureException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_invalid_payloads_after_verifying(): void
    {
        $payload = '{"event_id":"evt_1"}';

        $this->expectException(InvalidPayloadException::class);
        self::verifier()->constructEvent($payload, self::sign($payload));
    }

    public function test_it_requires_a_secret(): void
    {
        $this->expectException(WebhookVerificationException::class);
        new WebhookVerifier(' ');
    }

    public function test_the_client_exposes_verification_helpers(): void
    {
        $payload = self::payload();
        $now = time();

        $event = (new Prontuno('pno_sk_test'))->webhooks->constructEvent($payload, self::sign($payload, $now), self::SECRET);

        $this->assertSame('email.delivered', $event->type);
    }
}
