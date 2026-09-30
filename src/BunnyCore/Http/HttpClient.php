<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\BunnyCore\Http;

interface HttpClient
{
    /**
     * Send an HTTP request.
     *
     * @param array<string, string> $headers
     * @param string|resource|null $body String payload, an open stream to upload, or null for no body
     */
    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse;
}
