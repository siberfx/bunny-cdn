<?php

use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Siberfx\BunnyCdn\Flysystem\Util;
use Siberfx\BunnyCdn\Tests\BunnyCore\FakeHttpClient;

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
        /** @var StorageAttributes $item */
        foreach (iterator_to_array($adapter->listContents('', false)) as $item) {
            $item->isDir() ? $adapter->deleteDirectory($item->path()) : $adapter->delete($item->path());
        }
    } catch (Throwable) {
        // best effort
    }
}

/**
 * A storage listing item as returned by the Bunny Edge Storage API.
 *
 * @param array<string, mixed> $override
 * @return array<string, mixed>
 */
function bunnyListingItem(string $path, string $storage_zone = 'test_storage_zone', array $override = [], bool $directory = false): array
{
    ['file' => $file, 'dir' => $dir] = Util::splitPathIntoDirectoryAndFile($path);

    return array_merge([
        'Guid' => 'bf91bc4e-0e60-411a-b475-4416926d20f7',
        'StorageZoneName' => $storage_zone,
        'Path' => Util::normalizePath('/'.$storage_zone.'/'.Util::normalizePath($dir).'/'),
        'ObjectName' => $file,
        'Length' => $directory ? 0 : 10,
        'LastChanged' => date('Y-m-d\TH:i:s.v'),
        'ServerId' => 1,
        'ArrayNumber' => 0,
        'IsDirectory' => $directory,
        'UserId' => 'bf91bc4e-0e60-411a-b475-4416926d20f7',
        'ContentType' => '',
        'DateCreated' => date('Y-m-d\TH:i:s.v'),
        'StorageZoneId' => 1,
        'Checksum' => $directory ? '' : strtoupper(hash('sha256', $file)),
        'ReplicatedZones' => '',
    ], $override);
}
