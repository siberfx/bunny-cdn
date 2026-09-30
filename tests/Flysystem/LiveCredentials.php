<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests\Flysystem;

use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNRegion;

/**
 * Optional live storage zone for the Flysystem tests. When these environment variables are set the tests run against
 * the real zone instead of the in-memory mock (the zone is emptied between tests, so use a dedicated test zone):
 *
 *   BUNNY_TEST_STORAGE_ZONE, BUNNY_TEST_STORAGE_KEY, BUNNY_TEST_STORAGE_REGION (optional), BUNNY_TEST_PULL_ZONE (optional)
 */
final class LiveCredentials
{
    public static function enabled(): bool
    {
        return self::env('BUNNY_TEST_STORAGE_ZONE') !== null && self::env('BUNNY_TEST_STORAGE_KEY') !== null;
    }

    public static function client(): ?BunnyCDNClient
    {
        if (! self::enabled()) {
            return null;
        }

        return new BunnyCDNClient(
            (string) self::env('BUNNY_TEST_STORAGE_ZONE'),
            (string) self::env('BUNNY_TEST_STORAGE_KEY'),
            self::env('BUNNY_TEST_STORAGE_REGION') ?? BunnyCDNRegion::DEFAULT,
        );
    }

    public static function publicUrl(): ?string
    {
        return self::env('BUNNY_TEST_PULL_ZONE');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
