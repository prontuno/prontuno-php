<?php

declare(strict_types=1);

namespace Prontuno;

use Psr\Http\Message\ResponseInterface;

final class ResponseMetadata
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $requestId,
        public readonly ?int $rateLimitLimit,
        public readonly ?int $rateLimitRemaining,
        public readonly ?int $rateLimitReset,
    ) {}

    public static function fromResponse(ResponseInterface $response): self
    {
        $requestId = $response->getHeaderLine('x-request-id');

        return new self(
            status: $response->getStatusCode(),
            requestId: $requestId === '' ? null : $requestId,
            rateLimitLimit: self::integerHeader($response, 'RateLimit-Limit'),
            rateLimitRemaining: self::integerHeader($response, 'RateLimit-Remaining'),
            rateLimitReset: self::integerHeader($response, 'RateLimit-Reset'),
        );
    }

    private static function integerHeader(ResponseInterface $response, string $name): ?int
    {
        $value = $response->getHeaderLine($name);

        return is_numeric($value) ? (int) $value : null;
    }
}
