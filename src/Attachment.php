<?php

declare(strict_types=1);

namespace Prontuno;

use Prontuno\Exceptions\ConfigurationException;

/**
 * Builds attachment arrays for the `attachments` field of an email.
 */
final class Attachment
{
    /**
     * @return array{filename: string, content: string, encoding: 'base64', content_type?: string, content_id?: string}
     */
    public static function fromContent(
        string $filename,
        string $content,
        ?string $contentType = null,
        ?string $contentId = null,
    ): array {
        if (trim($filename) === '') {
            throw new ConfigurationException('An attachment filename is required.');
        }

        $attachment = [
            'filename' => $filename,
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ];

        if ($contentType !== null) {
            $attachment['content_type'] = $contentType;
        }

        if ($contentId !== null) {
            $attachment['content_id'] = $contentId;
        }

        return $attachment;
    }

    /**
     * @return array{filename: string, content: string, encoding: 'base64', content_type?: string, content_id?: string}
     */
    public static function fromPath(
        string $path,
        ?string $filename = null,
        ?string $contentType = null,
        ?string $contentId = null,
    ): array {
        $content = is_file($path) ? @file_get_contents($path) : false;

        if ($content === false) {
            throw new ConfigurationException("Attachment file [{$path}] could not be read.");
        }

        return self::fromContent($filename ?? basename($path), $content, $contentType, $contentId);
    }
}
