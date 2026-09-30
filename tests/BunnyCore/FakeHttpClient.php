<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests\BunnyCore;

use Siberfx\BunnyCdn\BunnyCore\Http\HttpClient;
use Siberfx\BunnyCdn\BunnyCore\Http\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: mixed}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $queue = [];

    public function push(int $status = 200, mixed $body = []): self
    {
        $this->queue[] = new HttpResponse($status, is_string($body) ? $body : json_encode($body));
        return $this;
    }

    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        return array_shift($this->queue) ?? new HttpResponse(200, '{}');
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: mixed} */
    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }

    public function lastJson(): mixed
    {
        $body = $this->last()['body'];
        return $body === null ? null : json_decode($body, true);
    }
}
