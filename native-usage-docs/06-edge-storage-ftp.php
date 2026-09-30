<?php

declare(strict_types=1);

/*
 * Edge Storage over FTP (requires ext-ftp): folders, uploads with progress, rename/move and downloads.
 * Prefer the HTTP API (05-edge-storage-http.php) unless you need these FTP style operations.
 *
 *   BUNNY_STORAGE_ZONE=my-zone BUNNY_STORAGE_ACCESS_KEY=... [BUNNY_STORAGE_REGION=ny] \
 *   BUNNY_EXAMPLES_WRITE=1 php native-usage-docs/06-edge-storage-ftp.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;

if (! extension_loaded('ftp')) {
    exit('This example needs the ftp extension.'.PHP_EOL);
}

if (! writes()) {
    exit('This example writes to your storage zone. Set BUNNY_EXAMPLES_WRITE=1 to run it.'.PHP_EOL);
}

$storage = new BunnyAPIStorage()->zoneConnect(
    envVar('BUNNY_STORAGE_ZONE'),
    envVar('BUNNY_STORAGE_ACCESS_KEY'),
    envVar('BUNNY_STORAGE_REGION', 'de'),
);

/*
 * Folders & navigation
 */
show('Create folder', $storage->createFolder('docs-ftp'));
show('Folder exists', $storage->folderExists('docs-ftp'));
show('cd', $storage->changeDir('docs-ftp'));
show('pwd', $storage->currentDir());
show('cd ..', $storage->moveUpOne());

/*
 * Uploads
 */
$local = sys_get_temp_dir().'/bunny-ftp';
is_dir($local) || mkdir($local);
file_put_contents("$local/a.txt", 'A');
file_put_contents("$local/b.txt", 'B');
file_put_contents("$local/big.bin", random_bytes(512 * 1024));

show('Upload one', $storage->uploadFile("$local/a.txt", 'docs-ftp/a.txt'));
show('Upload a folder', $storage->uploadAllFiles($local, 'docs-ftp/'));

// Writes the progress percentage (0-100) to a file you can poll from elsewhere
$storage->uploadFileWithProgress("$local/big.bin", 'docs-ftp/big.bin', "$local/UPLOAD_PERCENT.txt");
show('Upload progress', file_get_contents("$local/UPLOAD_PERCENT.txt").'%');

/*
 * Inspecting
 */
show('File exists', $storage->fileExists('docs-ftp/a.txt'));
show('File size', $storage->convertBytes($storage->getFileSize('docs-ftp/big.bin'), 'KB'));

/*
 * Rename & move (Bunny FTP has no rename: copied through a temp file, then deleted)
 */
show('Rename', $storage->renameFile('docs-ftp/', 'a.txt', 'renamed.txt'));
$storage->createFolder('docs-ftp/archive');
show('Move', $storage->moveFile('docs-ftp/', 'renamed.txt', 'docs-ftp/archive/'));

/*
 * Downloads
 */
show('Download', $storage->downloadFile("$local/b-copy.txt", 'docs-ftp/b.txt'));
$storage->downloadFileWithProgress("$local/big-copy.bin", 'docs-ftp/big.bin', "$local/DOWNLOAD_PERCENT.txt");
show('Download progress', file_get_contents("$local/DOWNLOAD_PERCENT.txt").'%');

/*
 * Cleanup (folders must be empty before deleteFolder)
 */
foreach (['docs-ftp/archive/renamed.txt', 'docs-ftp/b.txt', 'docs-ftp/big.bin', 'docs-ftp/a.txt'] as $file) {
    $storage->deleteFile($file);
}
$storage->deleteFolder('docs-ftp/archive');
$storage->deleteFolder('docs-ftp');
show('Close', $storage->closeConnection());
