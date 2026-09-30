<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Http;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body = '',
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }
        return json_decode($this->body, true);
    }
}
