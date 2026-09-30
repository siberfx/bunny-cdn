<?php

declare(strict_types=1);

/*
 * Edge Storage over HTTP: upload, download, list, delete and bulk operations.
 *
 *   BUNNY_STORAGE_ZONE=my-zone BUNNY_STORAGE_ACCESS_KEY=... [BUNNY_STORAGE_REGION=ny] \
 *   [BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/05-edge-storage-http.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIException;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;

/*
 * Select the zone. The region decides the hostname: de, uk, se, ny, la, sg, syd, br, jh.
 * Without a password the zone password is looked up with the account API key (BUNNY_API_KEY).
 */
$storage = new BunnyAPIStorage(getenv('BUNNY_API_KEY') ?: '')
    ->setStorageZone(
        envVar('BUNNY_STORAGE_ZONE'),
        envVar('BUNNY_STORAGE_ACCESS_KEY', ''),
        envVar('BUNNY_STORAGE_REGION', 'de'),
    );

show('Host', $storage->storageHostname());

/*
 * Listing
 */
show('Root (raw API listing)', array_column($storage->listDirectory(), 'ObjectName'));
show('Folders', array_column($storage->listFolders()['data'], 'name'));
show('Files', $storage->listFiles()['data']);
show('Everything', $storage->listAll()['data']);
show('Root size', $storage->dirSize());

if (! writes()) {
    exit(PHP_EOL.'Set BUNNY_EXAMPLES_WRITE=1 to upload, download and delete files.'.PHP_EOL);
}

/*
 * Uploading. A SHA256 checksum is sent so Bunny rejects corrupted uploads.
 */
$local = tempnam(sys_get_temp_dir(), 'bunny');
file_put_contents($local, str_repeat('Hello Bunny! ', 1_000));

$storage->uploadFileHTTP($local, 'docs-example/hello.txt');                                   // streams the file
$storage->uploadFileHTTP($local, 'docs-example/hello.bin', checksum: false);                  // skip the checksum
$storage->uploadContentHTTP('{"ok":true}', 'docs-example/data/status.json', content_type: 'application/json');
$storage->uploadContentHTTP('<h1>Not found</h1>', 'docs-example/404.html', content_type: 'text/html');

/*
 * Downloading
 */
show('Downloaded', substr($storage->downloadFileHTTP('docs-example/data/status.json'), 0, 100));

$target = sys_get_temp_dir().'/bunny-download/';
is_dir($target) || mkdir($target, recursive: true);
show('Download folder', $storage->downloadAll('docs-example', $target));

/*
 * Inspecting
 */
show('docs-example', $storage->listAll('docs-example'));
show('docs-example size', $storage->dirSize('docs-example'));

/*
 * Deleting
 */
$storage->deleteFileHTTP('docs-example/hello.bin');                   // one file
show('Deleted files in docs-example', $storage->deleteAllFiles('docs-example'));
$storage->deleteFileHTTP('docs-example/');                            // trailing slash: whole folder, recursively

try {
    $storage->deleteFileHTTP('/');                                    // refused on purpose
} catch (BunnyAPIException $e) {
    show('Root delete', $e->getMessage());
}

unlink($local);
show('Done');
