<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests\Flysystem;

use Exception;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\Request;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\Exceptions\NotFoundException;
use Siberfx\BunnyCdn\Flysystem\Util;

/**
 * BunnyCDNClient backed by an in-memory filesystem that answers like the Edge Storage API.
 */
class MockClient extends BunnyCDNClient
{
    public Filesystem $filesystem;

    public Guzzle $guzzleClient;

    public function __construct(string $storage_zone_name, string $api_key, string $region = '')
    {
        parent::__construct($storage_zone_name, $api_key, $region);
        $this->filesystem = new Filesystem(new InMemoryFilesystemAdapter());
    }

    /**
     * A storage listing item as returned by the Edge Storage API.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function listingItem(string $path, string $storage_zone = 'test_storage_zone', array $override = [], bool $directory = false): array
    {
        ['file' => $file, 'dir' => $dir] = Util::splitPathIntoDirectoryAndFile($path);
        $now = date('Y-m-d\TH:i:s.v');

        return [
            'Guid' => 'bf91bc4e-0e60-411a-b475-4416926d20f7',
            'StorageZoneName' => $storage_zone,
            'Path' => Util::normalizePath('/'.$storage_zone.'/'.Util::normalizePath($dir).'/'),
            'ObjectName' => $file,
            'Length' => $directory ? 0 : 10,
            'LastChanged' => $now,
            'ServerId' => 1,
            'ArrayNumber' => 0,
            'IsDirectory' => $directory,
            'UserId' => 'bf91bc4e-0e60-411a-b475-4416926d20f7',
            'ContentType' => '',
            'DateCreated' => $now,
            'StorageZoneId' => 1,
            'Checksum' => $directory ? '' : strtoupper(hash('sha256', $file)),
            'ReplicatedZones' => '',
            ...$override,
        ];
    }

    #[\Override]
    public function list(string $path): array
    {
        try {
            return $this->filesystem->listContents($path)->map(fn (StorageAttributes $item): array => $item instanceof FileAttributes
                ? self::listingItem($item->path(), $this->storage_zone_name, [
                    'Length' => $item->fileSize(),
                    'Checksum' => hash('sha256', $this->filesystem->read($item->path())),
                ])
                : self::listingItem($item->path(), $this->storage_zone_name, directory: true)
            )->toArray();
        } catch (FilesystemException) {
            return [];
        }
    }

    #[\Override]
    public function download(string $path): string
    {
        return $this->filesystem->read($path);
    }

    /** @return resource */
    #[\Override]
    public function stream(string $path)
    {
        return $this->filesystem->readStream($path);
    }

    #[\Override]
    public function upload(string $path, mixed $contents): array
    {
        try {
            is_resource($contents)
                ? $this->filesystem->writeStream($path, $contents)
                : $this->filesystem->write($path, $contents);
        } catch (FilesystemException) {
            return [];
        }

        return ['HttpCode' => 201, 'Message' => 'File uploaded.'];
    }

    #[\Override]
    public function make_directory(string $path): array
    {
        try {
            $this->filesystem->createDirectory($path);
        } catch (FilesystemException) {
            return [];
        }

        return ['HttpCode' => 201, 'Message' => 'Directory created.'];
    }

    #[\Override]
    public function delete(string $path): array
    {
        if (! $this->filesystem->has($path)) {
            throw new NotFoundException('404');
        }

        try {
            $this->filesystem->deleteDirectory($path);
            $this->filesystem->delete($path);
        } catch (Exception) {
            // deleting a missing file or directory is not an error here
        }

        return ['HttpCode' => 200, 'Message' => 'File deleted successfully.'];
    }

    #[\Override]
    public function getUploadRequest(string $path, mixed $contents): Request
    {
        return new Request('PUT', $path, [], $contents);
    }
}
