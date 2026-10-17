<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Prontuno\Attachment;
use Prontuno\Exceptions\ApiException;
use Prontuno\Exceptions\ConfigurationException;
use Prontuno\Exceptions\ConnectionException;
use Prontuno\Prontuno;

final class ClientTest extends TestCase
{
    public function test_it_requires_an_api_key_and_https(): void
    {
        foreach (['', '   '] as $key) {
            try {
                new Prontuno($key);
                $this->fail('Expected a configuration exception.');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ConfigurationException::class);
        new Prontuno('pno_sk_test', ['base_url' => 'http://example.com/v1']);
    }

    public function test_it_allows_http_for_loopback_and_rejects_unknown_options(): void
    {
        new Prontuno('pno_sk_test', ['base_url' => 'http://127.0.0.1:3000/v1']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown Prontuno option(s): timout.');
        new Prontuno('pno_sk_test', ['timout' => 5]);
    }

    public function test_it_sends_an_email_with_auth_and_idempotency_key(): void
    {
        $client = $this->client([
            self::json(['emails' => [[
                'id' => 'email_1', 'to' => 'ada@example.com', 'status' => 'queued',
                'rejection_code' => null, 'rejection_reason' => null,
            ]]], 202, ['x-request-id' => 'req_1', 'RateLimit-Remaining' => '9']),
        ]);

        $response = $client->emails->send([
            'from' => 'Acme <hello@example.com>',
            'to' => 'ada@example.com',
            'subject' => 'Welcome',
            'html' => '<p>Hi</p>',
            'attachments' => [Attachment::fromContent('note.txt', 'hello', 'text/plain')],
        ], 'order-1');

        $this->assertSame('email_1', $response['emails'][0]['id']);
        $request = $this->request(0);
        $this->assertSame('POST https://api.prontuno.test/v1/emails', $this->requestLines()[0]);
        $this->assertSame('Bearer pno_sk_test', $request->getHeaderLine('Authorization'));
        $this->assertSame('order-1', $request->getHeaderLine('Idempotency-Key'));
        $this->assertStringStartsWith('prontuno-php/', $request->getHeaderLine('User-Agent'));
        $body = $this->requestBody(0);
        $this->assertSame(
            ['filename' => 'note.txt', 'content' => base64_encode('hello'), 'encoding' => 'base64', 'content_type' => 'text/plain'],
            $body['attachments'][0] ?? null,
        );
        $this->assertSame('req_1', $client->lastResponse()?->requestId);
        $this->assertSame(9, $client->lastResponse()->rateLimitRemaining);
    }

    public function test_it_sends_a_batch(): void
    {
        $client = $this->client([self::json(['emails' => [[['id' => 'email_1']], [['id' => 'email_2']]]], 202)]);

        $client->emails->sendBatch([
            ['from' => 'a@example.com', 'to' => 'b@example.com', 'subject' => 'One', 'text' => '1'],
            ['from' => 'a@example.com', 'to' => 'c@example.com', 'subject' => 'Two', 'text' => '2'],
        ], 'batch-1');

        $this->assertSame('POST https://api.prontuno.test/v1/emails/batch', $this->requestLines()[0]);
        $this->assertCount(2, $this->requestBody(0)['emails'] ?? []);
        $this->assertSame('batch-1', $this->request(0)->getHeaderLine('Idempotency-Key'));
    }

    public function test_it_builds_list_queries_and_encodes_identifiers(): void
    {
        $page = ['data' => [], 'pagination' => ['limit' => 10, 'has_more' => false, 'next_cursor' => null]];
        $client = $this->client([self::json($page), self::json(['id' => 'sup_1']), self::json($page)]);

        $client->emails->list(limit: 10, domainId: 'dom_1', status: 'bounced');
        $client->suppressions->retrieve('user+tag@example.com');
        $client->suppressions->list(email: 'user@example.com');

        $this->assertSame([
            'GET https://api.prontuno.test/v1/emails?limit=10&domain_id=dom_1&status=bounced',
            'GET https://api.prontuno.test/v1/suppressions/user%2Btag%40example.com',
            'GET https://api.prontuno.test/v1/suppressions?email=user%40example.com',
        ], $this->requestLines());
    }

    public function test_it_manages_domains_and_suppressions(): void
    {
        $client = $this->client([
            self::json(['id' => 'dom_1', 'dns_records' => []], 201),
            self::json(['id' => 'dom_1', 'dns_records' => []]),
            self::json(['id' => 'sup_1', 'email' => 'x@example.com'], 201),
            self::json(null, 204),
        ]);

        $this->assertSame('dom_1', $client->domains->create('mg.example.com')['id']);
        $client->domains->retrieve('dom_1');
        $client->suppressions->create('x@example.com');
        $client->suppressions->delete('sup_1');

        $this->assertSame(['domain' => 'mg.example.com'], $this->requestBody(0));
        $this->assertSame('DELETE https://api.prontuno.test/v1/suppressions/sup_1', $this->requestLines()[3]);
    }

    public function test_it_manages_webhooks(): void
    {
        $webhook = ['id' => 'whk_1', 'name' => 'Prod', 'url' => 'https://example.com/hook', 'events' => ['email.delivered']];
        $client = $this->client([
            self::json($webhook + ['secret' => 'whsec_1'], 201),
            self::json($webhook + ['secret' => 'whsec_3'], 201),
            self::json($webhook),
            self::json($webhook),
            self::json(['id' => 'whk_1', 'secret' => 'whsec_2']),
            self::json(null, 204),
        ]);

        $this->assertSame('whsec_1', $client->webhooks->create(
            name: 'Prod', url: 'https://example.com/hook', events: ['email.delivered'],
        )['secret']);
        $client->webhooks->create(
            name: 'Prod', url: 'https://example.com/hook', events: ['email.delivered'],
            domainId: 'dom_1', isGlobal: false, isEnabled: false,
        );
        $client->webhooks->retrieve('whk_1');
        $client->webhooks->update('whk_1', ['is_enabled' => false]);
        $this->assertSame('whsec_2', $client->webhooks->rotateSecret('whk_1')['secret']);
        $client->webhooks->delete('whk_1');

        $this->assertSame([
            'POST https://api.prontuno.test/v1/webhooks',
            'POST https://api.prontuno.test/v1/webhooks',
            'GET https://api.prontuno.test/v1/webhooks/whk_1',
            'PATCH https://api.prontuno.test/v1/webhooks/whk_1',
            'POST https://api.prontuno.test/v1/webhooks/whk_1/rotate-secret',
            'DELETE https://api.prontuno.test/v1/webhooks/whk_1',
        ], $this->requestLines());
        $this->assertSame(['name' => 'Prod', 'url' => 'https://example.com/hook', 'events' => ['email.delivered']], $this->requestBody(0));
        $this->assertSame([
            'name' => 'Prod', 'url' => 'https://example.com/hook', 'events' => ['email.delivered'],
            'domain_id' => 'dom_1', 'is_global' => false, 'is_enabled' => false,
        ], $this->requestBody(1));
        $this->assertSame(['is_enabled' => false], $this->requestBody(3));
    }

    public function test_it_sends_is_global_with_domain_id_when_switching_a_webhooks_scope(): void
    {
        $webhook = ['id' => 'whk_1', 'name' => 'Prod', 'url' => 'https://example.com/hook', 'events' => ['email.delivered']];
        $client = $this->client([self::json($webhook), self::json($webhook)]);

        $client->webhooks->update('whk_1', ['is_global' => false, 'domain_id' => 'dom_1']);
        $client->webhooks->update('whk_1', ['is_global' => true, 'domain_id' => null]);

        $this->assertSame(['is_global' => false, 'domain_id' => 'dom_1'], $this->requestBody(0));
        $this->assertSame(['is_global' => true, 'domain_id' => null], $this->requestBody(1));
    }

    public function test_it_rejects_empty_identifiers(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client([])->emails->retrieve('');
    }

    public function test_it_iterates_every_page(): void
    {
        $client = $this->client([
            self::json(['data' => [['id' => 'whk_1']], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'c2']]),
            self::json(['data' => [['id' => 'whk_2']], 'pagination' => ['limit' => 1, 'has_more' => false, 'next_cursor' => null]]),
        ]);

        $ids = array_map(static fn (array $item): mixed => $item['id'], iterator_to_array($client->webhooks->listAll(limit: 1), false));

        $this->assertSame(['whk_1', 'whk_2'], $ids);
        $this->assertSame('GET https://api.prontuno.test/v1/webhooks?cursor=c2&limit=1', $this->requestLines()[1]);
    }

    public function test_pagination_rejects_repeated_cursors_and_page_overflow(): void
    {
        $repeat = ['data' => [], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'same']];
        $client = $this->client([self::json($repeat), self::json($repeat)]);

        try {
            iterator_to_array($client->domains->listAll());
            $this->fail('Expected a repeated cursor failure.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('repeated pagination cursor', $exception->getMessage());
        }

        $client = $this->client([self::json($repeat)]);
        $this->expectExceptionMessage('maximum of 1 pages');
        iterator_to_array($client->suppressions->listAll(maxPages: 1));
    }

    public function test_it_maps_api_errors(): void
    {
        $client = $this->client([self::json([
            'error' => [
                'code' => 'validation_failed', 'message' => 'Invalid request', 'retryable' => false,
                'suggested_action' => 'fix_input',
            ],
            'errors' => [['code' => 'required', 'message' => 'from is required', 'field' => 'from', 'retryable' => false]],
        ], 400, ['x-request-id' => 'req_9'])]);

        try {
            $client->emails->send(['to' => 'ada@example.com']);
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame(400, $exception->status());
            $this->assertSame('validation_failed', $exception->errorCode());
            $this->assertSame('Invalid request', $exception->getMessage());
            $this->assertSame('req_9', $exception->requestId());
            $this->assertSame('fix_input', $exception->suggestedAction());
            $this->assertFalse($exception->isRetryable());
            $this->assertSame('from', $exception->errors()[0]['field'] ?? null);
        }
    }

    public function test_it_retries_safe_requests_and_honours_retry_after(): void
    {
        $client = $this->client([
            self::json(['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retryable' => true]], 429, ['Retry-After' => '2']),
            new ConnectException('reset', new Request('GET', 'https://api.prontuno.test/v1/emails/email_1')),
            self::json(['id' => 'email_1']),
        ]);

        $this->assertSame('email_1', $client->emails->retrieve('email_1')['id']);
        $this->assertCount(3, $this->history);
        $this->assertSame(2.0, $this->sleeps[0]);
    }

    public function test_it_does_not_retry_unsafe_requests_without_an_idempotency_key(): void
    {
        $error = ['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]];
        $client = $this->client([self::json($error, 500), self::json($error, 500)]);

        try {
            $client->webhooks->update('whk_1', ['name' => 'x']);
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertTrue($exception->isRetryable());
        }
        $this->assertCount(1, $this->history);

        $client = $this->client([self::json($error, 503), self::json(['emails' => []], 202)]);
        $client->emails->send(['to' => 'x@example.com'], 'key-1');
        $this->assertCount(2, $this->history);
    }

    public function test_it_wraps_connection_failures(): void
    {
        $client = $this->client([
            new ConnectException('refused', new Request('POST', 'https://api.prontuno.test/v1/domains')),
        ]);

        $this->expectException(ConnectionException::class);
        $client->domains->create('example.com');
    }

    public function test_a_retried_delete_that_finds_the_resource_gone_succeeds(): void
    {
        $unavailable = ['error' => ['code' => 'unavailable', 'message' => 'Down', 'retryable' => true]];
        $notFound = ['error' => ['code' => 'not_found', 'message' => 'Missing', 'retryable' => false]];
        $revoked = ['error' => ['code' => 'already_revoked', 'message' => 'Revoked', 'retryable' => false]];

        $client = $this->client([self::json($unavailable, 503), self::json($notFound, 404)]);
        $client->webhooks->delete('whk_1');
        $this->assertCount(2, $this->history);

        $client = $this->client([
            new ConnectException('reset', new Request('DELETE', 'https://api.prontuno.test/v1/api-keys/key_1')),
            self::json($revoked, 409),
        ]);
        $client->apiKeys->revoke('key_1');
        $this->assertCount(2, $this->history);
    }

    public function test_a_delete_still_fails_when_no_earlier_attempt_could_have_applied(): void
    {
        $notFound = ['error' => ['code' => 'not_found', 'message' => 'Missing', 'retryable' => false]];
        $limited = ['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retryable' => true]];
        $conflict = ['error' => ['code' => 'conflict', 'message' => 'Conflict', 'retryable' => false]];
        $failed = ['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]];

        foreach ([
            [self::json($notFound, 404)],
            [self::json($limited, 429), self::json($notFound, 404)],
            [self::json($failed, 500), self::json($conflict, 409)],
        ] as $responses) {
            $client = $this->client($responses);

            try {
                $client->suppressions->delete('sup_1');
                $this->fail('Expected an API exception.');
            } catch (ApiException $exception) {
                $this->assertNotSame(200, $exception->status());
            }
            $this->assertCount(count($responses), $this->history);
        }
    }
}
