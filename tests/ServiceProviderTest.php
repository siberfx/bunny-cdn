<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use PHPUnit\Framework\TestCase;
use Siberfx\BunnyCdn\BunnyAPIPull;
use Siberfx\BunnyCdn\BunnyAPIStorage;
use Siberfx\BunnyCdn\BunnyAPIStream;
use Siberfx\BunnyCdn\BunnyCdnServiceProvider;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;

final class ServiceProviderTest extends TestCase
{
    private const array ENV = [
        'BUNNY_API_KEY' => 'api-key',
        'BUNNY_STORAGE_ZONE' => 'my-zone',
        'BUNNY_STORAGE_ACCESS_KEY' => 'zone-pass',
        'BUNNY_STORAGE_REGION' => 'uk',
        'BUNNY_STREAM_LIBRARY_ID' => '42',
        'BUNNY_STREAM_ACCESS_KEY' => 'stream-key',
    ];

    private Container $app;

    protected function setUp(): void
    {
        foreach (self::ENV as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }

        $this->app = new class extends Container {
            public function runningInConsole(): bool
            {
                return true;
            }

            public function configPath(string $path = ''): string
            {
                return "/config/$path";
            }
        };
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository(['filesystems' => ['disks' => [
            'bunny' => ['driver' => 'bunnycdn', 'pull_zone' => 'https://cdn.test', 'root' => 'assets', 'token_auth_key' => 'secret'],
        ]]]));
        $this->app->singleton('filesystem', fn (Container $app) => new FilesystemManager($app));

        $provider = new BunnyCdnServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENV) as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }
        Container::setInstance();
    }

    public function testApiClientsAreConfiguredSingletons(): void
    {
        self::assertSame($this->app->make(BunnyAPIPull::class), $this->app->make(BunnyAPIPull::class));
        self::assertSame('uk.storage.bunnycdn.com', $this->app->make(BunnyAPIStorage::class)->storageHostname());
        self::assertInstanceOf(BunnyAPIStream::class, $this->app->make(BunnyAPIStream::class));
    }

    public function testConfigIsPublishable(): void
    {
        self::assertSame(['/config/bunny-cdn.php'], array_values(BunnyCdnServiceProvider::pathsToPublish(BunnyCdnServiceProvider::class, 'bunny-cdn-config')));
    }

    public function testBunnyDiskFallsBackToStorageConfig(): void
    {
        $disk = $this->app->make('filesystem')->disk('bunny');
        $adapter = $disk->getAdapter();
        self::assertInstanceOf(BunnyCDNAdapter::class, $adapter);

        $requests = [];
        $client = (fn () => $this->client)->call($adapter);
        $client->guzzleClient = new Client(['handler' => function (Request $request) use (&$requests) {
            $requests[] = [$request->getMethod(), (string)$request->getUri(), $request->getHeaderLine('AccessKey')];
            return Create::promiseFor(new Response(201, [], '{"HttpCode":201}'));
        }]);

        $disk->put('hello.txt', 'hi');
        self::assertSame([['PUT', 'https://uk.storage.bunnycdn.com/my-zone/assets/hello.txt', 'zone-pass']], $requests);
        self::assertSame('https://cdn.test/assets/hello.txt', $disk->url('hello.txt'));
        self::assertStringStartsWith('https://cdn.test/assets/hello.txt?token=', $disk->temporaryUrl('hello.txt', new DateTimeImmutable('+1 hour')));
    }
}
