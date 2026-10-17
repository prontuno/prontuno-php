<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use Prontuno\Attachment;
use Prontuno\Exceptions\ConfigurationException;

final class AttachmentTest extends TestCase
{
    public function test_it_builds_an_attachment_from_content(): void
    {
        $this->assertSame([
            'filename' => 'logo.png',
            'content' => base64_encode('png-bytes'),
            'encoding' => 'base64',
            'content_type' => 'image/png',
            'content_id' => 'logo',
        ], Attachment::fromContent('logo.png', 'png-bytes', 'image/png', 'logo'));

        $this->assertSame(
            ['filename' => 'a.txt', 'content' => base64_encode('a'), 'encoding' => 'base64'],
            Attachment::fromContent('a.txt', 'a'),
        );
    }

    public function test_it_requires_a_filename(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('An attachment filename is required.');

        Attachment::fromContent('  ', 'content');
    }

    public function test_it_reads_a_file_from_disk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'prontuno');
        $this->assertIsString($path);
        file_put_contents($path, 'file contents');

        try {
            $this->assertSame([
                'filename' => basename($path),
                'content' => base64_encode('file contents'),
                'encoding' => 'base64',
            ], Attachment::fromPath($path));

            $this->assertSame([
                'filename' => 'invoice.pdf',
                'content' => base64_encode('file contents'),
                'encoding' => 'base64',
                'content_type' => 'application/pdf',
                'content_id' => 'invoice',
            ], Attachment::fromPath($path, 'invoice.pdf', 'application/pdf', 'invoice'));
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_missing_files_and_directories(): void
    {
        foreach ([sys_get_temp_dir().'/prontuno-missing-'.uniqid(), sys_get_temp_dir()] as $path) {
            try {
                Attachment::fromPath($path);
                $this->fail("Expected [{$path}] to be rejected.");
            } catch (ConfigurationException $exception) {
                $this->assertStringContainsString('could not be read', $exception->getMessage());
            }
        }
    }
}
