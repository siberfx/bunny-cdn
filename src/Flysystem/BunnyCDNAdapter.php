<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Flysystem;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use League\Flysystem\CalculateChecksumFromStream;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use RuntimeException;
use Siberfx\BunnyCdn\Flysystem\Exceptions\BunnyCDNException;
use Siberfx\BunnyCdn\Flysystem\Exceptions\NotFoundException;
use TypeError;

class BunnyCDNAdapter implements ChecksumProvider, FilesystemAdapter, PublicUrlGenerator, TemporaryUrlGenerator
{
    use CalculateChecksumFromStream;

    private string $token_auth_key = '';

    /**
     * @param string $pullzone_url Pull zone URL (or custom hostname) used for public and temporary URLs
     * @param string $root Path prefix all operations are scoped to (like the S3 adapter's "root" option)
     */
    public function __construct(
        private readonly BunnyCDNClient $client,
        private readonly string $pullzone_url = '',
        private string $root = '',
    ) {
        $this->root = rtrim(Util::normalizePath($this->root), '/');
    }

    /** Set the token authentication key used to sign temporary URLs. */
    public function setTokenAuthKey(string $tokenAuthKey): static
    {
        $this->token_auth_key = $tokenAuthKey;

        return $this;
    }

    /** Prefix a logical (Flysystem relative) path with the configured root. */
    private function resolvePath(string $path): string
    {
        return rtrim(Util::normalizePath($this->root.'/'.$path), '/');
    }

    public function fileExists(string $path): bool
    {
        return $this->exists(StorageAttributes::TYPE_FILE, $path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->exists(StorageAttributes::TYPE_DIRECTORY, $path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents);
    }

    public function writeStream(string $path, mixed $contents, Config $config): void
    {
        $this->upload($path, $contents);
    }

    /** @param string|resource $contents */
    private function upload(string $path, mixed $contents): void
    {
        try {
            $this->client->upload($this->resolvePath($path), $contents);
        } catch (BunnyCDNException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage());
        }
    }

    /**
     * Upload many local files concurrently.
     *
     * @param list<WriteBatchFile> $writeBatches
     */
    public function writeBatch(array $writeBatches, Config $config): void
    {
        $concurrency = (int) $config->get('concurrency', 50);

        foreach (array_chunk($writeBatches, $concurrency) as $batch) {
            $requests = function () use ($batch) {
                foreach ($batch as $file) {
                    yield $this->client->getUploadRequest($this->resolvePath($file->targetPath), (string) file_get_contents($file->localPath));
                }
            };

            $pool = new Pool($this->client->guzzleClient, $requests(), [
                'concurrency' => $concurrency,
                'rejected' => function (RequestException|RuntimeException $reason, int $index) use ($batch): never {
                    throw UnableToWriteFile::atLocation($batch[$index]->targetPath ?? (string) $index, $reason->getMessage());
                },
            ]);

            $pool->promise()->wait();
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->client->download($this->resolvePath($path));
        } catch (BunnyCDNException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
    }

    /** @return resource */
    public function readStream(string $path)
    {
        try {
            return $this->client->stream($this->resolvePath($path))
                ?? throw UnableToReadFile::fromLocation($path, 'Empty stream');
        } catch (BunnyCDNException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage());
        }
    }

    public function delete(string $path): void
    {
        // An empty path or a trailing slash means a directory
        if ($path === '' || str_ends_with($path, '/')) {
            throw UnableToDeleteFile::atLocation($path, 'Deletion of directories prevented.');
        }

        try {
            $this->client->delete($this->resolvePath($path));
        } catch (NotFoundException) {
            // already gone
        } catch (BunnyCDNException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage());
        }
    }

    public function deleteDirectory(string $path): void
    {
        $resolvedPath = $this->resolvePath($path);

        if ($resolvedPath === '') {
            throw UnableToDeleteDirectory::atLocation($path, 'Deletion of the storage zone root is not allowed.');
        }

        try {
            $this->client->delete($resolvedPath.'/');
        } catch (NotFoundException) {
            // already gone
        } catch (BunnyCDNException $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage());
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->make_directory($this->resolvePath($path));
        } catch (BunnyCDNException $e) {
            // Creating an existing directory is not an error for Flysystem
            if ($e->getMessage() !== 'Directory already exists') {
                throw UnableToCreateDirectory::atLocation($path, $e->getMessage());
            }
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'BunnyCDN does not support visibility');
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            return new FileAttributes($this->getObject($path)->path(), null, $this->pullzone_url !== '' ? Visibility::PUBLIC : Visibility::PRIVATE);
        } catch (UnableToReadFile|TypeError $e) {
            throw new UnableToRetrieveMetadata($e->getMessage());
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $object = $this->getObject($path);
        } catch (UnableToReadFile $e) {
            throw new UnableToRetrieveMetadata($e->getMessage());
        }

        if (! $object instanceof FileAttributes) {
            throw new UnableToRetrieveMetadata('Cannot retrieve mimeType of folder');
        }

        if ($object->mimeType()) {
            return $object;
        }

        $mimeType = $this->detectMimeType($path);

        // Flysystem expects unknown types to fail instead of falling back to text/plain
        if ($mimeType === '' || $mimeType === 'text/plain') {
            throw new UnableToRetrieveMetadata('Unknown Mimetype');
        }

        return new FileAttributes($path, null, null, null, $mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->fileMetadata($path, 'Last Modified only accepts files as parameters, not directories');
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->fileMetadata($path, 'Cannot retrieve size of folder');
    }

    private function fileMetadata(string $path, string $directoryMessage): FileAttributes
    {
        try {
            $object = $this->getObject($path);
        } catch (UnableToReadFile $e) {
            throw new UnableToRetrieveMetadata($e->getMessage());
        }

        return $object instanceof FileAttributes ? $object : throw new UnableToRetrieveMetadata($directoryMessage);
    }

    /** @return iterable<StorageAttributes> */
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $entries = $this->client->list($this->resolvePath($path));
        } catch (BunnyCDNException $e) {
            throw UnableToRetrieveMetadata::create($path, 'folder', $e->getMessage());
        }

        foreach ($entries as $item) {
            $content = $this->normalizeObject($item);
            yield $content;

            if ($deep && $content instanceof DirectoryAttributes) {
                // not "yield from": that keeps the nested keys and callers use iterator_to_array()
                foreach ($this->listContents($content->path(), $deep) as $nested) {
                    yield $nested;
                }
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) {
            return;
        }

        try {
            $files = iterator_to_array($this->getFiles($source), false);

            foreach ($files as $file) {
                $target = $destination.substr($file, strlen($source));
                $this->copyFile($file, $target, $config);
                $this->delete($file);
            }
        } catch (UnableToReadFile $e) {
            throw new UnableToMoveFile($e->getMessage());
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            foreach ($this->getFiles($source) as $file) {
                $this->copyFile($file, $destination.substr($file, strlen($source)), $config);
            }
        } catch (UnableToReadFile|UnableToWriteFile $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    private function copyFile(string $source, string $destination, Config $config): void
    {
        $this->write($destination, $this->read($source), $config);
    }

    /**
     * All file paths below $source, or $source itself when it is a file.
     *
     * @return iterable<string>
     */
    private function getFiles(string $source): iterable
    {
        $contents = iterator_to_array($this->listContents($source, true), false);

        if ($contents === []) {
            yield $source;

            return;
        }

        foreach ($contents as $entry) {
            if ($entry->isFile()) {
                yield $entry->path();
            }
        }
    }

    public function checksum(string $path, Config $config): string
    {
        // md5 (computed from the stream) stays the default for compatibility; sha256 uses Bunny's stored checksum
        if ($config->get('checksum_algo', 'md5') !== 'sha256') {
            return $this->calculateChecksumFromStream($path, $config);
        }

        try {
            $checksum = $this->getObject($path)->extraMetadata()['checksum'] ?? null;
        } catch (UnableToReadFile $e) {
            throw new UnableToProvideChecksum($e->reason(), $path, $e);
        }

        if (! is_string($checksum) || $checksum === '') {
            throw new UnableToProvideChecksum('Checksum not available.', $path);
        }

        return strtolower($checksum);
    }

    public function detectMimeType(string $path): string
    {
        try {
            $detector = new FinfoMimeTypeDetector();

            return $detector->detectMimeTypeFromPath($path)
                ?? $detector->detectMimeTypeFromBuffer((string) stream_get_contents($this->readStream($path), 80))
                ?? '';
        } catch (Exception) {
            return '';
        }
    }

    /** Laravel compatible public URLs: `Storage::disk('bunnycdn')->url()` calls this when present. */
    public function getUrl(string $path): string
    {
        return $this->publicUrl($path, new Config());
    }

    public function publicUrl(string $path, Config $config): string
    {
        if ($this->pullzone_url === '') {
            throw new RuntimeException('In order to get a visible URL for a BunnyCDN object, you must pass the "pullzone_url" parameter to the BunnyCDNAdapter.');
        }

        return rtrim($this->pullzone_url, '/').'/'.ltrim($this->resolvePath($path), '/');
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        if ($this->token_auth_key === '') {
            throw new UnableToGenerateTemporaryUrl('In order to generate temporary URLs for a BunnyCDN object, you must call the `setTokenAuthKey` method on the BunnyCDNAdapter.', $path);
        }

        $expiration = $expiresAt->getTimestamp();
        $parts = parse_url($path);
        $path = str_starts_with($parts['path'] ?? '', '/') ? $path : '/'.$path;

        // Scope to the configured root, unless a fully qualified URL was passed
        if ($this->root !== '' && ! filter_var($path, FILTER_VALIDATE_URL)) {
            $path = '/'.$this->root.$path;
        }

        parse_str($parts['query'] ?? '', $params);

        if (is_array($queryParams = $config->get('withQueryParams'))) {
            $params = [...$params, ...$queryParams];
        }

        ksort($params);

        return $this->pullzone_url.$path
            .(str_contains($path, '?') ? '&' : '?')
            .'token='.$this->buildSigningKey($path, $expiration, $params)
            .'&expires='.$expiration
            .($params !== [] ? '&'.http_build_query($params) : '');
    }

    /**
     * Laravel compatible temporary URLs: `Storage::disk('bunnycdn')->temporaryUrl()` calls this when present.
     *
     * @param DateTimeInterface|int $expiration A date, or minutes from now
     * @param array<string, mixed> $options Additional query parameters to sign into the URL
     */
    public function getTemporaryUrl(string $path, DateTimeInterface|int $expiration, array $options = []): string
    {
        $expiresAt = $expiration instanceof DateTimeInterface
            ? $expiration
            : new DateTimeImmutable()->modify("+{$expiration} minutes");

        return $this->temporaryUrl($path, $expiresAt, new Config($options === [] ? [] : ['withQueryParams' => $options]));
    }

    /** @param array<string, mixed> $params */
    private function buildSigningKey(string $path, int $expiration, array $params): string
    {
        $query = implode('&', array_map(fn (string|int $key, mixed $value): string => $key.'='.$value, array_keys($params), $params));
        $hash = hash('sha256', $this->token_auth_key.$path.$expiration.$query, true);

        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($hash));
    }

    /** @param array<string, mixed> $item */
    protected function normalizeObject(array $item): StorageAttributes
    {
        $path = Util::normalizePath(
            Util::replaceFirst($item['StorageZoneName'].'/', '/', $item['Path'].$item['ObjectName'])
        );

        if ($this->root !== '' && str_starts_with($path, $this->root.'/')) {
            $path = substr($path, strlen($this->root) + 1);
        }

        if ($item['IsDirectory']) {
            return new DirectoryAttributes($path);
        }

        return new FileAttributes(
            $path,
            $item['Length'],
            Visibility::PUBLIC,
            self::parseTimestamp($item['LastChanged']),
            $item['ContentType'] ?: $this->detectMimeType($item['Path'].$item['ObjectName']),
            $this->extractExtraMetadata($item),
        );
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function extractExtraMetadata(array $item): array
    {
        return [
            'type' => $item['IsDirectory'] ? 'dir' : 'file',
            'dirname' => Util::splitPathIntoDirectoryAndFile($item['Path'])['dir'],
            'guid' => $item['Guid'],
            'object_name' => $item['ObjectName'],
            'timestamp' => self::parseTimestamp($item['LastChanged']),
            'server_id' => $item['ServerId'],
            'user_id' => $item['UserId'],
            'date_created' => $item['DateCreated'],
            'storage_zone_name' => $item['StorageZoneName'],
            'storage_zone_id' => $item['StorageZoneId'],
            'checksum' => $item['Checksum'],
            'replicated_zones' => $item['ReplicatedZones'],
        ];
    }

    protected function getObject(string $path = ''): StorageAttributes
    {
        $matches = new DirectoryListing($this->listContents(pathinfo($path, PATHINFO_DIRNAME), false))
            ->filter(fn (StorageAttributes $item): bool => Util::normalizePath($item->path()) === $path)
            ->toArray();

        return match (count($matches)) {
            1 => $matches[0],
            0 => throw UnableToReadFile::fromLocation($path, 'Error 404:"'.$path.'"'),
            default => throw UnableToReadFile::fromLocation($path, 'More than one file was returned for path:"'.$path.'".'),
        };
    }

    private function exists(string $type, string $path): bool
    {
        $target = Util::normalizePath($path);
        $listing = $this->listContents(Util::splitPathIntoDirectoryAndFile($path)['dir'], false);

        foreach ($listing as $item) {
            if ($item->type() === $type && Util::normalizePath($item->path()) === $target) {
                return true;
            }
        }

        return false;
    }

    private static function parseTimestamp(string $timestamp): int
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $timestamp)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $timestamp);

        return $date ? $date->getTimestamp() : 0;
    }
}
