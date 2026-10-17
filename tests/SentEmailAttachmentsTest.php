<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use Prontuno\Exceptions\ApiException;
use Prontuno\Exceptions\ConfigurationException;
use Prontuno\Prontuno;

final class SentEmailAttachmentsTest extends TestCase
{
    private const array ATTACHMENT = [
        'id' => 'att_01kfzeb03ae59a98z11nhxnrkp',
        'filename' => 'invoice.pdf',
        'content_type' => 'application/pdf',
        'size' => 48213,
        'sha256' => null,
        'content_id' => null,
        'content_disposition' => 'attachment',
        'download_url' => 'https://files.example.com/sent/2026/10/18/att_01kfzeb03ae59a98z11nhxnrkp?token=HS256-abc&expires=1792328400',
        'expires_at' => '2026-10-18T13:00:00.000Z',
    ];

    public function test_retrieving_an_email_returns_its_bodies_and_attachments(): void
    {
        $client = $this->client([self::json([
            'id' => 'email_1',
            'text' => 'Your invoice is attached.',
            'html' => null,
            'attachments' => [self::ATTACHMENT],
        ])]);

        $email = $client->emails->retrieve('email_1');

        $this->assertSame('Your invoice is attached.', $email['text']);
        $this->assertNull($email['html']);
        $this->assertSame([self::ATTACHMENT], $email['attachments']);
    }

    public function test_it_lists_one_page_of_attachments(): void
    {
        $client = $this->client([self::json([
            'data' => [self::ATTACHMENT, ['download_url' => null, 'expires_at' => null] + self::ATTACHMENT],
            'pagination' => ['limit' => 2, 'has_more' => false, 'next_cursor' => null],
        ])]);

        $page = $client->emails->attachments->list('email_1', limit: 2, cursor: 'c1');

        $this->assertNull($page['data'][1]['download_url']);
        $this->assertSame(['GET https://api.prontuno.test/v1/emails/email_1/attachments?limit=2&cursor=c1'], $this->requestLines());
    }

    public function test_it_iterates_attachments_across_pages(): void
    {
        $client = $this->client([
            self::json(['data' => [['id' => 'att_1']], 'pagination' => ['limit' => 1, 'has_more' => true, 'next_cursor' => 'c2']]),
            self::json(['data' => [['id' => 'att_2']], 'pagination' => ['limit' => 1, 'has_more' => false, 'next_cursor' => null]]),
        ]);

        $ids = array_column(iterator_to_array($client->emails->attachments->listAll('email_1', limit: 1), false), 'id');

        $this->assertSame(['att_1', 'att_2'], $ids);
        $this->assertSame('GET https://api.prontuno.test/v1/emails/email_1/attachments?cursor=c2&limit=1', $this->requestLines()[1]);
    }

    public function test_it_retrieves_one_attachment_and_encodes_identifiers(): void
    {
        $client = $this->client([self::json(self::ATTACHMENT)]);

        $attachment = $client->emails->attachments->retrieve('email_1', 'att_01/x');

        $this->assertSame('invoice.pdf', $attachment['filename']);
        $this->assertSame(['GET https://api.prontuno.test/v1/emails/email_1/attachments/att_01%2Fx'], $this->requestLines());
    }

    public function test_it_requires_both_identifiers(): void
    {
        foreach ([['', 'att_1'], ['email_1', '']] as [$emailId, $attachmentId]) {
            try {
                $this->client([])->emails->attachments->retrieve($emailId, $attachmentId);
                $this->fail('Expected a configuration exception.');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ConfigurationException::class);
        $this->client([])->emails->attachments->list('');
    }

    public function test_it_retries_temporary_storage_outages(): void
    {
        $client = $this->client([
            self::json(['error' => ['code' => 'storage_unavailable', 'message' => 'Unavailable', 'retryable' => true]], 503, ['Retry-After' => '0']),
            self::json(self::ATTACHMENT),
        ]);

        $this->assertSame(self::ATTACHMENT['id'], $client->emails->attachments->retrieve('email_1', self::ATTACHMENT['id'])['id']);
        $this->assertCount(2, $this->history);
    }

    public function test_it_surfaces_attachments_that_have_not_finished_uploading(): void
    {
        $client = $this->client([self::json(['error' => [
            'code' => 'attachment_unavailable',
            'message' => 'This attachment is unavailable because its storage is incomplete',
            'retryable' => false,
            'suggested_action' => 'contact_support',
        ]], 403)]);

        try {
            $client->emails->attachments->retrieve('email_1', 'att_1');
            $this->fail('Expected an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame(403, $exception->status());
            $this->assertSame('attachment_unavailable', $exception->errorCode());
            $this->assertFalse($exception->isRetryable());
        }
        $this->assertCount(1, $this->history);
    }

    public function test_attachments_require_the_management_key(): void
    {
        $client = new Prontuno(sendingKey: 'send');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('GET /emails/email_1/attachments requires a management API key');
        $client->emails->attachments->list('email_1');
    }
}
