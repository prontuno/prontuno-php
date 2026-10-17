<?php

declare(strict_types=1);

namespace Prontuno\Exceptions;

use Prontuno\ResponseMetadata;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class ApiException extends RuntimeException implements ProntunoException
{
    /**
     * @param  array<string, mixed>  $body
     * @param  list<array<string, mixed>>  $errors
     */
    final public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $apiErrorCode,
        private readonly bool $retryable,
        private readonly ?string $action,
        private readonly ?int $retryAfterSeconds,
        private readonly array $body,
        private readonly array $errors,
        private readonly ResponseMetadata $metadata,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(ResponseInterface $response, array $body): static
    {
        $status = $response->getStatusCode();
        $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : [];
        $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : [];

        $retryAfter = $error['retry_after_seconds'] ?? $response->getHeaderLine('Retry-After');

        return new static(
            message: is_string($error['message'] ?? null)
                ? $error['message']
                : "Prontuno API request failed with status {$status}.",
            status: $status,
            apiErrorCode: is_string($error['code'] ?? null) ? $error['code'] : 'api_error',
            retryable: is_bool($error['retryable'] ?? null) ? $error['retryable'] : $status >= 500,
            action: is_string($error['suggested_action'] ?? null) ? $error['suggested_action'] : null,
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
            body: $body,
            errors: self::errorList($errors),
            metadata: ResponseMetadata::fromResponse($response),
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->apiErrorCode;
    }

    public function requestId(): ?string
    {
        return $this->metadata->requestId;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function suggestedAction(): ?string
    {
        return $this->action;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * Field validation errors, or per-item errors (with an `index`) for batch sends.
     *
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function responseBody(): array
    {
        return $this->body;
    }

    public function metadata(): ResponseMetadata
    {
        return $this->metadata;
    }

    /**
     * @param  array<mixed>  $errors
     * @return list<array<string, mixed>>
     */
    private static function errorList(array $errors): array
    {
        $list = [];

        foreach ($errors as $error) {
            if (is_array($error)) {
                /** @var array<string, mixed> $error */
                $list[] = $error;
            }
        }

        return $list;
    }
}
