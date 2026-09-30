<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPI;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIDNS;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIPull;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStream;
use Siberfx\BunnyCdn\BunnyCore\Http\CurlHttpClient;
use Siberfx\BunnyCdn\BunnyCore\Http\HttpClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNRegion;

class BunnyCdnServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/bunny-cdn.php', 'bunny-cdn');

        $this->app->singletonIf(HttpClient::class, CurlHttpClient::class);

        foreach ([BunnyAPI::class, BunnyAPIPull::class, BunnyAPIDNS::class] as $class) {
            $this->app->singleton($class, fn (Container $app) => new $class(
                (string)$app['config']->get('bunny-cdn.api_key'),
                (string)$app['config']->get('bunny-cdn.stream.access_key'),
                $app->make(HttpClient::class),
            ));
        }

        $this->app->singleton(BunnyAPIStorage::class, function (Container $app) {
            $config = $app['config']->get('bunny-cdn');
            $storage = new BunnyAPIStorage((string)$config['api_key'], (string)$config['stream']['access_key'], $app->make(HttpClient::class));
            if (!empty($config['storage']['zone'])) {
                $storage->setStorageZone($config['storage']['zone'], (string)$config['storage']['access_key'], (string)$config['storage']['region']);
            }
            return $storage;
        });

        $this->app->singleton(BunnyAPIStream::class, function (Container $app) {
            $config = $app['config']->get('bunny-cdn');
            $stream = new BunnyAPIStream((string)$config['api_key'], (string)$config['stream']['access_key'], $app->make(HttpClient::class));
            if (!empty($config['stream']['library_id'])) {
                $stream->setStreamLibraryId((int)$config['stream']['library_id']);
            }
            return $stream;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/bunny-cdn.php' => $this->app->configPath('bunny-cdn.php'),
            ], 'bunny-cdn-config');
        }

        $this->callAfterResolving('filesystem', function (FilesystemManager $manager) {
            // Laravel binds this callback to the manager, so it must not rely on $this
            $manager->extend('bunnycdn', fn (Container $app, array $config) => BunnyCdnServiceProvider::createBunnyDisk($app, $config));
        });
    }

    /**
     * Build the "bunnycdn" disk. Missing disk options fall back to the bunny-cdn storage config.
     *
     * @param array<string, mixed> $config
     */
    public static function createBunnyDisk(Container $app, array $config): FilesystemAdapter
    {
        $defaults = (array)$app['config']->get('bunny-cdn.storage', []);

        $adapter = new BunnyCDNAdapter(
            new BunnyCDNClient(
                (string)($config['storage_zone'] ?? $defaults['zone'] ?? ''),
                (string)($config['api_key'] ?? $defaults['access_key'] ?? ''),
                (string)($config['region'] ?? $defaults['region'] ?? BunnyCDNRegion::DEFAULT),
            ),
            (string)($config['pull_zone'] ?? ''),
            (string)($config['root'] ?? ''),
        );
        $adapter->setTokenAuthKey((string)($config['token_auth_key'] ?? ''));

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    }
}
