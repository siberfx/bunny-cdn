<?php

declare(strict_types=1);

/*
 * Flysystem v3 adapter: use Edge Storage like any other Flysystem filesystem (without Laravel).
 *
 *   BUNNY_STORAGE_ZONE=my-zone BUNNY_STORAGE_ACCESS_KEY=... [BUNNY_STORAGE_REGION=ny] [BUNNY_PULL_ZONE_URL=https://my-zone.b-cdn.net] \
 *   [BUNNY_TOKEN_AUTH_KEY=...] BUNNY_EXAMPLES_WRITE=1 php native-usage-docs/09-flysystem.php
 */

require __DIR__.'/bootstrap.php';

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\MountManager;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNRegion;
use Siberfx\BunnyCdn\Flysystem\WriteBatchFile;

if (! writes()) {
    exit('This example writes to your storage zone. Set BUNNY_EXAMPLES_WRITE=1 to run it.'.PHP_EOL);
}

/*
 * Setup: client (zone, zone password, region) + adapter (pull zone URL for public URLs, optional root folder)
 */
$client = new BunnyCDNClient(
    envVar('BUNNY_STORAGE_ZONE'),
    envVar('BUNNY_STORAGE_ACCESS_KEY'),
    envVar('BUNNY_STORAGE_REGION', BunnyCDNRegion::FALKENSTEIN),
);

$adapter = new BunnyCDNAdapter($client, envVar('BUNNY_PULL_ZONE_URL', 'https://example.b-cdn.net'), root: 'docs-flysystem')
    ->setTokenAuthKey(envVar('BUNNY_TOKEN_AUTH_KEY', ''));   // enables temporaryUrl()

$filesystem = new Filesystem($adapter);

/*
 * Writing & reading
 */
$filesystem->write('hello.txt', 'Hello from Flysystem');
$filesystem->write('nested/deeper/data.json', json_encode(['ok' => true]));

$stream = fopen('php://temp', 'w+b');
fwrite($stream, str_repeat('x', 1024));
rewind($stream);
$filesystem->writeStream('stream.bin', $stream);

show('read()', $filesystem->read('hello.txt'));
show('readStream()', strlen((string) stream_get_contents($filesystem->readStream('stream.bin'))).' bytes');

/*
 * Metadata
 */
show('Metadata', [
    'exists' => $filesystem->fileExists('hello.txt'),
    'directory exists' => $filesystem->directoryExists('nested'),
    'size' => $filesystem->fileSize('hello.txt'),
    'mime type' => $filesystem->mimeType('nested/deeper/data.json'),
    'last modified' => date('c', $filesystem->lastModified('hello.txt')),
    'md5' => $filesystem->checksum('hello.txt'),
    'sha256' => $filesystem->checksum('hello.txt', ['checksum_algo' => 'sha256']),  // Bunny's stored checksum, no download
]);

/*
 * Listing
 */
$all = $filesystem->listContents('', deep: true)
    ->filter(fn (StorageAttributes $item): bool => $item->isFile())
    ->map(fn (StorageAttributes $item): string => $item->path())
    ->toArray();
show('All files', $all);

/*
 * Copy, move, directories
 */
$filesystem->copy('hello.txt', 'copies/hello.txt');
$filesystem->move('copies/hello.txt', 'moved/hello.txt');
$filesystem->createDirectory('empty-folder');

/*
 * URLs
 */
show('Public URL', $filesystem->publicUrl('hello.txt'));
if (envVar('BUNNY_TOKEN_AUTH_KEY', '') !== '') {
    show('Signed URL (1 hour)', $filesystem->temporaryUrl('hello.txt', new DateTimeImmutable('+1 hour')));
    show('Signed download URL', $filesystem->temporaryUrl('hello.txt', new DateTimeImmutable('+10 minutes'), [
        'withQueryParams' => ['download' => 'hello.txt'],
    ]));
}

/*
 * Batch upload many local files concurrently (adapter specific)
 */
$dir = sys_get_temp_dir().'/bunny-batch';
is_dir($dir) || mkdir($dir);
$batch = [];
foreach (range(1, 5) as $i) {
    file_put_contents("$dir/file-$i.txt", "file $i");
    $batch[] = new WriteBatchFile("$dir/file-$i.txt", "batch/file-$i.txt");
}
$adapter->writeBatch($batch, new Config(['concurrency' => 5]));
show('Batch uploaded', count($batch).' files');

/*
 * Errors are Flysystem exceptions
 */
try {
    $filesystem->read('does-not-exist.txt');
} catch (UnableToReadFile $e) {
    show('Missing file', $e->getMessage());
}

/*
 * Copy between filesystems, e.g. local disk -> Bunny
 */
$mounts = new MountManager([
    'local' => new Filesystem(new LocalFilesystemAdapter($dir)),
    'bunny' => $filesystem,
]);
$mounts->copy('local://file-1.txt', 'bunny://from-local/file-1.txt');

/*
 * Cleanup
 */
try {
    foreach (['nested', 'copies', 'moved', 'batch', 'empty-folder', 'from-local'] as $folder) {
        $filesystem->deleteDirectory($folder);
    }
    $filesystem->delete('hello.txt');
    $filesystem->delete('stream.bin');
} catch (FilesystemException $e) {
    show('Cleanup', $e->getMessage());
}
show('Done');
