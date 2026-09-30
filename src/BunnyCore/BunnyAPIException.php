<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\BunnyCore;

use Exception;
use Siberfx\BunnyCdn\BunnyCore\Http\HttpResponse;
use Throwable;

class BunnyAPIException extends Exception
{
    public function __construct(
        string $message = '',
        private readonly ?int $status_code = null,
        private readonly mixed $response_body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status_code ?? 0, $previous);
    }

    public static function fromResponse(string $method, string $url, HttpResponse $response): self
    {
        $body = $response->json() ?? $response->body;
        $detail = is_array($body) ? ($body['Message'] ?? $body['message'] ?? null) : null;
        $message = "Bunny API $method $url failed with HTTP {$response->status}" . ($detail ? ": $detail" : '');
        return new self($message, $response->status, $body);
    }

    public function getStatusCode(): ?int
    {
        return $this->status_code;
    }

    public function getResponseBody(): mixed
    {
        return $this->response_body;
    }

    public function errorMessage(): string
    {
        return "Error on line {$this->getLine()} in {$this->getFile()}. {$this->getMessage()}.";
    }
}
