<?php

declare(strict_types=1);

use League\Flysystem\FilesystemAdapter;
use Siberfx\BunnyCdn\Tests\BunnyCore\FakeHttpClient;
use Siberfx\BunnyCdn\Tests\Flysystem\MockClient;

/*
 * The Flysystem conformance suites (tests/Flysystem/*ConformanceTest.php) are League's PHPUnit test case and run
 * as classes; everything else is written as Pest tests.
 */

function fakeHttp(): FakeHttpClient
{
    return new FakeHttpClient();
}

/** Remove everything an adapter wrote (needed when running against a live storage zone). */
function clearAdapter(FilesystemAdapter $adapter): void
{
    try {
        foreach (iterator_to_array($adapter->listContents('', false), false) as $item) {
            $item->isDir() ? $adapter->deleteDirectory($item->path()) : $adapter->delete($item->path());
        }
    } catch (Throwable) {
        // best effort
    }
}

/**
 * A storage listing item as returned by the Edge Storage API.
 *
 * @param array<string, mixed> $override
 * @return array<string, mixed>
 */
function bunnyListingItem(string $path, string $storage_zone = 'test_storage_zone', array $override = [], bool $directory = false): array
{
    return MockClient::listingItem($path, $storage_zone, $override, $directory);
}
