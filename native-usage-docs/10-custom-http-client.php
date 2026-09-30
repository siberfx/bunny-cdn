<?php

declare(strict_types=1);

/*
 * Custom HTTP clients: logging, retries, and fake responses for your own tests.
 * Every BunnyCore client sends its requests through Siberfx\BunnyCdn\BunnyCore\Http\HttpClient.
 *
 *   BUNNY_API_KEY=... php native-usage-docs/10-custom-http-client.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIPull;
use Siberfx\BunnyCdn\BunnyCore\Http\CurlHttpClient;
use Siberfx\BunnyCdn\BunnyCore\Http\HttpClient;
use Siberfx\BunnyCdn\BunnyCore\Http\HttpResponse;

/*
 * 1. A decorator that logs every request and retries rate limits / server errors
 */
final readonly class RetryingLoggingClient implements HttpClient
{
    public function __construct(
        private HttpClient $inner = new CurlHttpClient(),
        private int $retries = 3,
    ) {}

    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse
    {
        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);
            $response = $this->inner->send($method, $url, $headers, $body);
            printf("  %s %s -> %d (%.0f ms)\n", $method, $url, $response->status, (hrtime(true) - $started) / 1e6);

            $retryable = $response->status === 429 || $response->status >= 500;
            if (! $retryable || $attempt > $this->retries || is_resource($body)) {
                return $response;
            }

            usleep(200_000 * 2 ** $attempt);   // exponential backoff
        }
    }
}

$pull = new BunnyAPIPull(envVar('BUNNY_API_KEY'), http: new RetryingLoggingClient());
show('Pull zones via the logging client');
$pull->listPullZones(page: 1, per_page: 5);

/*
 * 2. A fake client for unit testing your own code without network access
 */
final class FakeBunny implements HttpClient
{
    /** @var list<array{string, string}> */
    public array $sent = [];

    /** @param array<string, array<mixed>> $routes "METHOD path" => JSON response */
    public function __construct(private readonly array $routes) {}

    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse
    {
        $this->sent[] = [$method, $url];
        $path = parse_url($url, PHP_URL_PATH);

        return isset($this->routes["$method $path"])
            ? new HttpResponse(200, (string) json_encode($this->routes["$method $path"]))
            : new HttpResponse(404, '{"Message":"Not found"}');
    }
}

$fake = new FakeBunny([
    'GET /pullzone/42' => ['Id' => 42, 'Name' => 'fake-zone', 'Hostnames' => [['Id' => 1, 'Value' => 'fake.b-cdn.net', 'ForceSSL' => true]]],
]);
$pull = new BunnyAPIPull('any-key', http: $fake);

show('Fake pull zone hostnames', $pull->pullZoneHostnames(42));
show('Requests sent', $fake->sent);
