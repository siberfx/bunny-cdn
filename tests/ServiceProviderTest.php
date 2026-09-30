<?php

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Siberfx\BunnyCdn\BunnyAPIPull;
use Siberfx\BunnyCdn\BunnyAPIStorage;
use Siberfx\BunnyCdn\BunnyAPIStream;
use Siberfx\BunnyCdn\BunnyCdnServiceProvider;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;

const PROVIDER_ENV = [
    'BUNNY_API_KEY' => 'api-key',
    'BUNNY_STORAGE_ZONE' => 'my-zone',
    'BUNNY_STORAGE_ACCESS_KEY' => 'zone-pass',
    'BUNNY_STORAGE_REGION' => 'uk',
    'BUNNY_STREAM_LIBRARY_ID' => '42',
    'BUNNY_STREAM_ACCESS_KEY' => 'stream-key',
];

beforeEach(function () {
    foreach (PROVIDER_ENV as $key => $value) {
        $_SERVER[$key] = $_ENV[$key] = $value;
    }

    $this->app = new class extends Container
    {
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
});

afterEach(function () {
    foreach (array_keys(PROVIDER_ENV) as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
    }
    Container::setInstance();
});

test('api clients are configured singletons', function () {
    expect($this->app->make(BunnyAPIPull::class))->toBe($this->app->make(BunnyAPIPull::class))
        ->and($this->app->make(BunnyAPIStorage::class)->storageHostname())->toBe('uk.storage.bunnycdn.com')
        ->and($this->app->make(BunnyAPIStream::class))->toBeInstanceOf(BunnyAPIStream::class);
});

test('the config is publishable', function () {
    expect(array_values(BunnyCdnServiceProvider::pathsToPublish(BunnyCdnServiceProvider::class, 'bunny-cdn-config')))
        ->toBe(['/config/bunny-cdn.php']);
});

test('the bunnycdn disk falls back to the storage config', function () {
    $disk = $this->app->make('filesystem')->disk('bunny');
    $adapter = $disk->getAdapter();
    expect($adapter)->toBeInstanceOf(BunnyCDNAdapter::class);

    $requests = [];
    $client = (fn () => $this->client)->call($adapter);
    $client->guzzleClient = new Client(['handler' => function (Request $request) use (&$requests) {
        $requests[] = [$request->getMethod(), (string) $request->getUri(), $request->getHeaderLine('AccessKey')];

        return Create::promiseFor(new Response(201, [], '{"HttpCode":201}'));
    }]);

    $disk->put('hello.txt', 'hi');

    expect($requests)->toBe([['PUT', 'https://uk.storage.bunnycdn.com/my-zone/assets/hello.txt', 'zone-pass']])
        ->and($disk->url('hello.txt'))->toBe('https://cdn.test/assets/hello.txt')
        ->and($disk->temporaryUrl('hello.txt', new DateTimeImmutable('+1 hour')))->toStartWith('https://cdn.test/assets/hello.txt?token=');
});
