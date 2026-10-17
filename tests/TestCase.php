<?php

declare(strict_types=1);

namespace Prontuno\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Prontuno\Prontuno;
use Psr\Http\Message\RequestInterface;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    /** @var list<float> */
    protected array $sleeps = [];

    /**
     * @param  list<Response|Throwable>  $responses
     * @param  array<string, mixed>  $options
     */
    protected function client(array $responses, array $options = []): Prontuno
    {
        $this->history = [];
        $this->sleeps = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Prontuno('pno_sk_test', $options + [
            'base_url' => 'https://api.prontuno.test/v1',
            'http_client' => new Client(['handler' => $stack]),
            'sleep' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }

    /**
     * @param  array<mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    protected static function json(?array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function request(int $index): RequestInterface
    {
        $this->assertArrayHasKey($index, $this->history);

        return $this->history[$index]['request'];
    }

    /**
     * @return array<mixed>
     */
    protected function requestBody(int $index): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    protected function requestLines(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri(),
            $this->history,
        );
    }
}
