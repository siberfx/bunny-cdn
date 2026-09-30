<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Siberfx\BunnyCdn\Http\CurlHttpClient;
use Siberfx\BunnyCdn\Http\HttpClient;

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
    }
}
