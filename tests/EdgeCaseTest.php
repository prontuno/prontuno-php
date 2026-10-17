<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Prontuno\Exceptions\ApiException;
use Prontuno\Exceptions\ConfigurationException;
use Prontuno\Exceptions\ConnectionException;
use Prontuno\Prontuno;
use Prontuno\Webhooks\Exceptions\InvalidPayloadException;
use Prontuno\Webhooks\WebhookVerifier;

final class EdgeCaseTest extends TestCase
{
    public function test_it_rejects_invalid_client_options(): void
    {
        foreach ([
            ['timeout' => 0],
            ['connect_timeout' => -1],
            ['max_retries' => -1],
            ['base_url' => 'not a url'],
            ['base_url' => 'ftp://api.example.com/v1'],
            ['base_url' => 'http://api.example.com/v1'],
            ['base_url' => 'api.example.com/v1'],
            ['base_url' => 'https:///v1'],
            ['base_url' => 'http://localhost.example.com/v1'],
        ] as $options) {
            try {
                new Prontuno('pno_sk_test', $options);
                $this->fail('Expected options to be rejected: '.json_encode($options));
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_app_info_is_added_after_the_sdk_user_agent(): void
    {
        $sdk = 'prontuno-php/'.Prontuno::VERSION;

        foreach ([
            [[], $sdk],
            [['app_info' => '  '], $sdk],
            [['app_info' => ' my-app/1.2.0 '], $sdk.' my-app/1.2.0'],
        ] as [$options, $expected]) {
            $this->client([self::json(['id' => 'dom_1'])], $options)->domains->retrieve('dom_1');

            $this->assertSame($expected, $this->request(0)->getHeaderLine('User-Agent'));
        }

        foreach (["my-app\r\nX-Injected: 1", 42] as $app_info) {
            try {
                new Prontuno('pno_sk_test', ['app_info' => $app_info]);
                $this->fail('Expected app_info to be rejected.');
            } catch (ConfigurationException $exception) {
                $this->assertSame('app_info must be a single-line string.', $exception->getMessage());
            }
        }
    }

    public function test_it_reads_the_base_url_from_the_environment(): void
    {
        $original = getenv('PRONTUNO_BASE_URL');
        $handler = [self::json(['id' => 'e']), self::json(['id' => 'e']), self::json(['id' => 'e'])];

        try {
            putenv('PRONTUNO_BASE_URL= https://env.example.com/v1/ ');
            $this->client($handler, ['base_url' => null])->emails->retrieve('e');
            $envUrl = $this->requestLines()[0];

            $this->client([self::json(['id' => 'e'])], ['base_url' => 'https://option.example.com/v1'])->emails->retrieve('e');
            $optionUrl = $this->requestLines()[0];

            putenv('PRONTUNO_BASE_URL=   ');
            $this->client([self::json(['id' => 'e'])], ['base_url' => null])->emails->retrieve('e');
            $defaultUrl = $this->requestLines()[0];

            putenv('PRONTUNO_BASE_URL=http://env.example.com/v1');
            $this->expectException(ConfigurationException::class);
            new Prontuno('pno_sk_test');
        } finally {
            putenv($original === false ? 'PRONTUNO_BASE_URL' : "PRONTUNO_BASE_URL={$original}");

            $this->assertSame('GET https://env.example.com/v1/emails/e', $envUrl ?? null);
            $this->assertSame('GET https://option.example.com/v1/emails/e', $optionUrl ?? null);
            $this->assertSame('GET https://api.prontuno.net/v1/emails/e', $defaultUrl ?? null);
        }
    }

    public function test_it_accepts_https_and_loopback_http_base_urls(): void
    {
        foreach (['HTTPS://api.example.com/v1', 'http://localhost:8000/v1', 'http://127.0.0.1/v1', 'http://[::1]:8000/v1'] as $baseUrl) {
            $this->assertInstanceOf(Prontuno::class, new Prontuno('pno_sk_test', ['base_url' => $baseUrl]));
        }
    }

    public function test_it_rejects_empty_and_invalid_successful_responses(): void
    {
        foreach ([
            new Response(200, [], ''),
            new Response(200, [], 'not json'),
            new Response(200, [], '"a string"'),
        ] as $response) {
            try {
                $this->client([$response])->emails->retrieve('email_1');
                $this->fail('Expected a connection exception.');
            } catch (ConnectionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_normalizes_unusual_error_bodies(): void
    {
        $cases = [
            [new Response(404, [], ''), 'api_error', 'Prontuno API request failed with status 404.', false],
            [new Response(400, [], 'Bad request'), 'invalid_response', 'Bad request', false],
            [new Response(502, [], '"oops"'), 'api_error', 'Prontuno API request failed with status 502.', true],
        ];

        foreach ($cases as [$response, $code, $message, $retryable]) {
            try {
                $this->client([$response], ['max_retries' => 0])->emails->retrieve('email_1');
                $this->fail('Expected an API exception.');
            } catch (ApiException $exception) {
                $this->assertSame($code, $exception->errorCode());
                $this->assertSame($message, $exception->getMessage());
                $this->assertSame($retryable, $exception->isRetryable());
                $this->assertNull($exception->retryAfter());
            }
        }
    }

    public function test_api_exceptions_expose_the_full_response(): void
    {
        $body = ['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retryable' => true, 'retry_after_seconds' => 7]];
        $client = $this->client([self::json($body, 429, ['x-request-id' => 'req_1', 'RateLimit-Remaining' => '0'])], ['max_retries' => 0]);

        try {
            $client->emails->retrieve('email_1');
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame(7, $exception->retryAfter());
            $this->assertSame($body, $exception->responseBody());
            $this->assertSame(429, $exception->metadata()->status);
            $this->assertSame(0, $exception->metadata()->rateLimitRemaining);
            $this->assertSame([], $exception->errors());
        }
    }

    public function test_it_honours_http_date_retry_after_headers(): void
    {
        $client = $this->client([
            self::json(['error' => ['code' => 'unavailable', 'message' => 'Down']], 503, [
                'Retry-After' => gmdate('D, d M Y H:i:s', time() - 10).' GMT',
            ]),
            self::json(['id' => 'email_1']),
        ]);

        $client->emails->retrieve('email_1');

        $this->assertSame([0.0], $this->sleeps);
    }

    public function test_it_uses_exponential_backoff_without_retry_after(): void
    {
        $error = ['error' => ['code' => 'unavailable', 'message' => 'Down']];
        $client = $this->client([self::json($error, 503), self::json($error, 503), self::json(['id' => 'email_1'])]);

        $client->emails->retrieve('email_1');

        $this->assertCount(2, $this->sleeps);
        $this->assertGreaterThanOrEqual(0.375, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.625, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(0.75, $this->sleeps[1]);
        $this->assertLessThanOrEqual(1.25, $this->sleeps[1]);
    }

    public function test_it_sleeps_for_real_when_no_sleep_callback_is_given(): void
    {
        $client = $this->client([
            self::json(['error' => ['code' => 'rate_limited', 'message' => 'Slow']], 429, ['Retry-After' => '0']),
            self::json(['id' => 'email_1']),
        ], ['sleep' => null]);

        $this->assertSame('email_1', $client->emails->retrieve('email_1')['id']);
        $this->assertSame([], $this->sleeps);
    }

    public function test_single_page_list_methods(): void
    {
        $page = ['data' => [['id' => 'x']], 'pagination' => ['limit' => 5, 'has_more' => false, 'next_cursor' => null]];
        $client = $this->client([self::json($page), self::json($page)]);

        $this->assertSame('x', $client->domains->list(limit: 5, cursor: 'c1')['data'][0]['id']);
        $this->assertSame('x', $client->webhooks->list(limit: 5)['data'][0]['id']);
        $this->assertSame([
            'GET https://api.prontuno.test/v1/domains?limit=5&cursor=c1',
            'GET https://api.prontuno.test/v1/webhooks?limit=5',
        ], $this->requestLines());
    }

    public function test_iterating_emails_passes_filters_and_skips_non_array_items(): void
    {
        $client = $this->client([
            self::json(['data' => [['id' => 'email_1'], 'junk'], 'pagination' => ['limit' => 2, 'has_more' => false, 'next_cursor' => null]]),
        ]);

        $items = iterator_to_array($client->emails->listAll(limit: 2, domainId: 'dom_1', status: 'sent'), false);

        $this->assertSame([['id' => 'email_1']], $items);
        $this->assertSame('GET https://api.prontuno.test/v1/emails?limit=2&domain_id=dom_1&status=sent', $this->requestLines()[0]);
    }

    public function test_pagination_validates_inputs_and_cursors(): void
    {
        try {
            iterator_to_array($this->client([])->webhooks->listAll(maxPages: 0));
            $this->fail('Expected maxPages to be rejected.');
        } catch (ConfigurationException) {
            $this->addToAssertionCount(1);
        }

        $client = $this->client([
            self::json(['data' => [], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => null]]),
        ]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('without a next cursor');
        iterator_to_array($client->domains->listAll());
    }

    public function test_client_level_signature_verification(): void
    {
        $payload = '{"event_id":"evt_1","webhook_id":"whk_1","type":"email.sent","created_at":"x","data":{}}';
        $now = time();
        $signature = "t={$now},v1=".hash_hmac('sha256', "{$now}.{$payload}", 'secret');

        (new Prontuno('pno_sk_test'))->webhooks->verifySignature($payload, $signature, 'secret');
        $event = (new WebhookVerifier('secret', 0))->constructEvent($payload, $signature);

        $this->assertSame(json_decode($payload, true), $event->toArray());
    }

    public function test_webhook_verifier_rejects_negative_tolerance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WebhookVerifier('secret', -1);
    }

    public function test_webhook_verifier_rejects_invalid_payload_shapes(): void
    {
        $payloads = [
            'not json',
            '[1,2]',
            '"text"',
            '{"event_id":"evt_1","webhook_id":"whk_1","type":"email.sent","created_at":"x","data":"nope"}',
            '{"event_id":"evt_1","webhook_id":"whk_1","type":"email.sent","created_at":"x","data":[1,2]}',
        ];

        foreach ($payloads as $payload) {
            $signature = 't=100,v1='.hash_hmac('sha256', "100.{$payload}", 'secret');

            try {
                (new WebhookVerifier('secret', 0))->constructEvent($payload, $signature);
                $this->fail("Expected [{$payload}] to be rejected.");
            } catch (InvalidPayloadException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_webhook_verifier_accepts_an_empty_data_object(): void
    {
        $payload = '{"event_id":"evt_1","webhook_id":"whk_1","type":"email.sent","created_at":"x","data":{}}';
        $signature = 't=100,v1='.hash_hmac('sha256', "100.{$payload}", 'secret');

        $this->assertSame([], (new WebhookVerifier('secret', 0))->constructEvent($payload, $signature)->data);
    }
}
