<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Prontuno\Exceptions\ConfigurationException;
use Prontuno\Prontuno;
use Psr\Http\Message\RequestInterface;

final class KeysTest extends TestCase
{
    private const array EMAIL = ['from' => 'a@example.com', 'to' => 'b@example.com', 'subject' => 'Hi', 'text' => 'Hi'];

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    /**
     * @return array<string, mixed>
     */
    private function options(int $responses = 6): array
    {
        $this->sent = [];
        $queue = [];
        for ($i = 0; $i < $responses; $i++) {
            $queue[] = self::json(['emails' => [], 'data' => [], 'pagination' => ['has_more' => false]]);
        }

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->sent));

        return ['base_url' => 'https://api.prontuno.test/v1', 'http_client' => new Client(['handler' => $stack])];
    }

    /**
     * @return list<string>
     */
    private function keysUsed(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath().' '.$entry['request']->getHeaderLine('Authorization'),
            $this->sent,
        );
    }

    public function test_it_sends_with_the_sending_key_and_manages_with_the_management_key(): void
    {
        $client = new Prontuno(options: $this->options(), sendingKey: 'send', managementKey: 'manage');

        $client->emails->send(self::EMAIL);
        $client->emails->sendBatch([self::EMAIL]);
        $client->emails->list();
        $client->domains->list();

        $this->assertSame([
            'POST /v1/emails Bearer send',
            'POST /v1/emails/batch Bearer send',
            'GET /v1/emails Bearer manage',
            'GET /v1/domains Bearer manage',
        ], $this->keysUsed());
    }

    public function test_a_single_api_key_is_used_for_everything(): void
    {
        $client = new Prontuno(' shared ', $this->options());

        $client->emails->send(self::EMAIL);
        $client->webhooks->list();

        $this->assertSame(['POST /v1/emails Bearer shared', 'GET /v1/webhooks Bearer shared'], $this->keysUsed());
    }

    public function test_role_specific_keys_override_the_api_key(): void
    {
        $client = new Prontuno('shared', $this->options(), sendingKey: 'send');

        $client->emails->send(self::EMAIL);
        $client->suppressions->list();

        $this->assertSame(['POST /v1/emails Bearer send', 'GET /v1/suppressions Bearer shared'], $this->keysUsed());
    }

    public function test_it_fails_before_any_request_when_the_needed_key_is_missing(): void
    {
        $sendOnly = new Prontuno(options: $this->options(), sendingKey: 'send');

        try {
            $sendOnly->emails->retrieve('email_1');
            $this->fail('Expected a configuration exception.');
        } catch (ConfigurationException $exception) {
            $this->assertSame(
                'GET /emails/email_1 requires a management API key; pass managementKey (or apiKey) when creating the client.',
                $exception->getMessage(),
            );
        }

        $manageOnly = new Prontuno(options: $this->options(), managementKey: 'manage');

        try {
            $manageOnly->emails->sendBatch([self::EMAIL]);
            $this->fail('Expected a configuration exception.');
        } catch (ConfigurationException $exception) {
            $this->assertStringStartsWith('POST /emails/batch requires a sending API key; pass sendingKey', $exception->getMessage());
        }

        $this->assertSame([], $this->sent);
    }

    public function test_blank_keys_count_as_unset(): void
    {
        $client = new Prontuno('', $this->options(), sendingKey: '  ', managementKey: 'manage');
        $client->domains->list();
        $this->assertSame(['GET /v1/domains Bearer manage'], $this->keysUsed());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('apiKey, sendingKey, or managementKey');
        new Prontuno(sendingKey: ' ');
    }

    public function test_it_requires_at_least_one_key(): void
    {
        $this->expectException(ConfigurationException::class);
        new Prontuno;
    }

    public function test_with_sending_key_switches_only_the_sending_key(): void
    {
        $client = new Prontuno(options: $this->options(), sendingKey: 'send-a', managementKey: 'manage');
        $otherDomain = $client->withSendingKey('send-b');

        $otherDomain->emails->send(self::EMAIL);
        $otherDomain->domains->list();
        $client->emails->send(self::EMAIL);

        $this->assertNotSame($client, $otherDomain);
        $this->assertSame([
            'POST /v1/emails Bearer send-b',
            'GET /v1/domains Bearer manage',
            'POST /v1/emails Bearer send-a',
        ], $this->keysUsed());
    }

    public function test_with_sending_key_on_a_single_key_client_keeps_it_for_management(): void
    {
        $client = (new Prontuno('shared', $this->options()))->withSendingKey('send');

        $client->emails->send(self::EMAIL);
        $client->domains->list();

        $this->assertSame(['POST /v1/emails Bearer send', 'GET /v1/domains Bearer shared'], $this->keysUsed());
    }

    public function test_with_sending_key_rejects_a_blank_key(): void
    {
        $this->expectException(ConfigurationException::class);
        (new Prontuno('shared'))->withSendingKey(' ');
    }
}
