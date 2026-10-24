<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use Prontuno\Exceptions\ApiException;

final class CredentialsTest extends TestCase
{
    private const string KEY_ID = 'key_01kfzeb03ae59a98z11nhxnrkj';

    private const string SMTP_ID = 'smtp_01kfzeb03ae59a98z11nhxnrkm';

    private const string DOMAIN_ID = 'dom_01kfzeb03ae59a98z11nhxnrkk';

    public function test_it_creates_finds_and_revokes_api_keys(): void
    {
        $key = ['id' => self::KEY_ID, 'name' => 'Sender', 'type' => 'sending', 'scopes' => ['email:send']];
        $client = $this->client([
            self::json($key + ['key' => 'pno_sk_secret'], 201),
            self::json($key),
            self::json(null, 204),
        ]);

        $this->assertSame('pno_sk_secret', $client->apiKeys->create('Sender', 'sending', domainId: self::DOMAIN_ID)['key']);
        $this->assertSame(['email:send'], $client->apiKeys->retrieve(self::KEY_ID)['scopes']);
        $client->apiKeys->revoke(self::KEY_ID);

        $this->assertSame([
            'POST https://api.prontuno.test/v1/api-keys',
            'GET https://api.prontuno.test/v1/api-keys/'.self::KEY_ID,
            'DELETE https://api.prontuno.test/v1/api-keys/'.self::KEY_ID,
        ], $this->requestLines());
        $this->assertSame(['name' => 'Sender', 'type' => 'sending', 'domain_id' => self::DOMAIN_ID], $this->requestBody(0));
    }

    public function test_it_only_sends_optional_api_key_fields_when_given(): void
    {
        $client = $this->client([self::json(['key' => 'a'], 201), self::json(['key' => 'b'], 201)]);

        $client->apiKeys->create('Admin', 'management');
        $client->apiKeys->create('Agent', 'mcp', self::DOMAIN_ID, allowEmailSending: true);

        $this->assertSame(['name' => 'Admin', 'type' => 'management'], $this->requestBody(0));
        $this->assertSame(
            ['name' => 'Agent', 'type' => 'mcp', 'domain_id' => self::DOMAIN_ID, 'allow_email_sending' => true],
            $this->requestBody(1),
        );
    }

    public function test_it_lists_and_iterates_api_keys_with_filters(): void
    {
        $client = $this->client([
            self::json(['data' => [['id' => 'key_1']], 'pagination' => ['limit' => 5, 'has_more' => false, 'next_cursor' => null]]),
            self::json(['data' => [['id' => 'key_1']], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'c2']]),
            self::json(['data' => [['id' => 'key_2']], 'pagination' => ['limit' => 1, 'has_more' => false, 'next_cursor' => null]]),
        ]);

        $client->apiKeys->list(limit: 5, cursor: 'c1', status: 'all', type: 'mcp');
        $ids = array_column(iterator_to_array($client->apiKeys->listAll(limit: 1, status: 'revoked', type: 'sending'), false), 'id');

        $this->assertSame(['key_1', 'key_2'], $ids);
        $this->assertSame([
            'GET https://api.prontuno.test/v1/api-keys?limit=5&cursor=c1&status=all&type=mcp',
            'GET https://api.prontuno.test/v1/api-keys?limit=1&status=revoked&type=sending',
            'GET https://api.prontuno.test/v1/api-keys?cursor=c2&limit=1&status=revoked&type=sending',
        ], $this->requestLines());
    }

    public function test_it_surfaces_already_revoked_conflicts(): void
    {
        $client = $this->client([self::json([
            'error' => ['code' => 'already_revoked', 'message' => 'API key is already revoked.', 'retryable' => false],
        ], 409)]);

        try {
            $client->apiKeys->revoke(self::KEY_ID);
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->status());
            $this->assertSame('already_revoked', $exception->errorCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function test_it_manages_smtp_credentials(): void
    {
        $credential = ['id' => self::SMTP_ID, 'name' => 'CRM', 'username' => 'pno_smtp_01kfzeb03ae59a98z11nhxnrkn'];
        $client = $this->client([
            self::json($credential + ['password' => 'first'], 201),
            self::json($credential),
            self::json($credential + ['password' => 'second']),
            self::json(null, 204),
        ]);

        $created = $client->smtpCredentials->create('CRM', self::DOMAIN_ID);
        $client->smtpCredentials->retrieve(self::SMTP_ID);
        $reset = $client->smtpCredentials->resetPassword(self::SMTP_ID);
        $client->smtpCredentials->revoke(self::SMTP_ID);

        $this->assertSame('first', $created['password']);
        $this->assertSame('second', $reset['password']);
        $this->assertSame(['name' => 'CRM', 'domain_id' => self::DOMAIN_ID], $this->requestBody(0));
        $this->assertSame('', (string) $this->request(2)->getBody());
        $this->assertSame([
            'POST https://api.prontuno.test/v1/smtp-credentials',
            'GET https://api.prontuno.test/v1/smtp-credentials/'.self::SMTP_ID,
            'POST https://api.prontuno.test/v1/smtp-credentials/'.self::SMTP_ID.'/reset-password',
            'DELETE https://api.prontuno.test/v1/smtp-credentials/'.self::SMTP_ID,
        ], $this->requestLines());
    }

    public function test_it_lists_and_iterates_smtp_credentials(): void
    {
        $page = ['data' => [['id' => self::SMTP_ID]], 'pagination' => ['limit' => 10, 'has_more' => false, 'next_cursor' => null]];
        $client = $this->client([self::json($page), self::json($page)]);

        $client->smtpCredentials->list(limit: 10, cursor: 'c1', status: 'revoked');
        $items = iterator_to_array($client->smtpCredentials->listAll(status: 'all'), false);

        $this->assertCount(1, $items);
        $this->assertSame([
            'GET https://api.prontuno.test/v1/smtp-credentials?limit=10&cursor=c1&status=revoked',
            'GET https://api.prontuno.test/v1/smtp-credentials?status=all',
        ], $this->requestLines());
    }

    public function test_secret_creating_requests_are_not_retried(): void
    {
        $error = ['error' => ['code' => 'internal_error', 'message' => 'Boom', 'retryable' => true]];

        foreach ([
            fn ($client) => $client->apiKeys->create('Admin', 'management'),
            fn ($client) => $client->smtpCredentials->create('CRM', self::DOMAIN_ID),
            fn ($client) => $client->smtpCredentials->resetPassword(self::SMTP_ID),
        ] as $call) {
            $client = $this->client([self::json($error, 500), self::json($error, 500)]);

            try {
                $call($client);
                $this->fail('Expected an API exception.');
            } catch (ApiException) {
                $this->assertCount(1, $this->history);
            }
        }
    }

    public function test_it_retrieves_usage(): void
    {
        $usage = [
            'subscription' => null,
            'credits' => ['balance' => 25000, 'expires_at' => null],
            'limits' => [
                'domains' => ['used' => 2, 'limit' => 2],
                'shared_teams' => ['used' => 0, 'limit' => 5],
                'api_keys' => ['used' => 3, 'limit' => 10],
                'smtp_credentials' => ['used' => 0, 'limit' => null],
            ],
        ];
        $client = $this->client([self::json($usage)]);

        $this->assertSame($usage, $client->usage->retrieve());
        $this->assertSame(['GET https://api.prontuno.test/v1/usage'], $this->requestLines());
    }
}
