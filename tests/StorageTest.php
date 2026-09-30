<?php

use Siberfx\BunnyCdn\BunnyAPIException;
use Siberfx\BunnyCdn\BunnyAPIStorage;

const STORAGE_LISTING = [
    ['ObjectName' => 'a.jpg', 'IsDirectory' => false, 'Length' => 2048, 'DateCreated' => '2026-09-01T10:00:00', 'LastChanged' => '2026-09-02T10:00:00', 'Guid' => 'g1'],
    ['ObjectName' => 'sub', 'IsDirectory' => true, 'Length' => 0, 'DateCreated' => '2026-09-01T10:00:00', 'LastChanged' => '2026-09-02T10:00:00', 'Guid' => 'g2'],
];

beforeEach(function () {
    $this->http = fakeHttp();
    $this->bunny = new BunnyAPIStorage('api-key', http: $this->http);
});

test('uses the region host and the access key header', function () {
    $this->bunny->setStorageZone('my-zone', 'zone-pass', 'NY');
    $this->http->push(200, STORAGE_LISTING);

    $this->bunny->listFiles('pets');

    expect($this->http->last())
        ->url->toBe('https://ny.storage.bunnycdn.com/my-zone/pets/')
        ->url->not->toContain('AccessKey=')
        ->and($this->http->last()['headers']['AccessKey'])->toBe('zone-pass');
});

test('regions map to storage hostnames', function (string $region, string $host) {
    expect($this->bunny->setStorageZone('z', 'k', $region)->storageHostname())->toBe($host);
})->with([
    ['de', 'storage.bunnycdn.com'],
    ['uk', 'uk.storage.bunnycdn.com'],
    ['se', 'se.storage.bunnycdn.com'],
    ['ny', 'ny.storage.bunnycdn.com'],
    ['la', 'la.storage.bunnycdn.com'],
    ['sg', 'sg.storage.bunnycdn.com'],
    ['syd', 'syd.storage.bunnycdn.com'],
    ['br', 'br.storage.bunnycdn.com'],
    ['jh', 'jh.storage.bunnycdn.com'],
]);

test('an unknown region throws', function () {
    $this->bunny->setStorageZone('z', 'k', 'mars');
})->throws(BunnyAPIException::class, "Unknown storage region 'mars'");

test('looks up the zone password when omitted', function () {
    $this->http->push(200, [['Name' => 'other', 'Password' => 'x'], ['Name' => 'my-zone', 'Password' => 'found']]);

    $this->bunny->setStorageZone('my-zone');
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/storagezone?page=0&perPage=1000&includeDeleted=false&search=my-zone');

    $this->bunny->downloadFileHTTP('a.txt');
    expect($this->http->last()['headers']['AccessKey'])->toBe('found');
});

test('uploads stream the file with a sha256 checksum', function () {
    $file = tempnam(sys_get_temp_dir(), 'bny');
    file_put_contents($file, 'hello');
    $this->bunny->setStorageZone('my-zone', 'zone-pass');
    $this->http->push(201, ['HttpCode' => 201, 'Message' => 'File uploaded.']);

    $result = $this->bunny->uploadFileHTTP($file, '/folder/my file.txt');
    unlink($file);

    expect($result['HttpCode'])->toBe(201)
        ->and($this->http->last())
        ->method->toBe('PUT')
        ->url->toBe('https://storage.bunnycdn.com/my-zone/folder/my%20file.txt')
        ->body->toBe('hello')
        ->and($this->http->last()['headers']['Checksum'])->toBe(strtoupper(hash('sha256', 'hello')));
});

test('downloads return the raw contents', function () {
    $this->bunny->setStorageZone('my-zone', 'zone-pass');
    $this->http->push(200, 'raw-bytes');

    expect($this->bunny->downloadFileHTTP('a.bin'))->toBe('raw-bytes');
});

test('deleting the zone root is refused', function () {
    $this->bunny->setStorageZone('my-zone', 'zone-pass');
    $this->bunny->deleteFileHTTP('/');
})->throws(BunnyAPIException::class, 'Refusing to delete the storage zone root');

test('listing helpers', function () {
    $this->bunny->setStorageZone('my-zone', 'zone-pass');
    foreach (range(1, 4) as $ignored) {
        $this->http->push(200, STORAGE_LISTING);
    }

    expect($this->bunny->listFiles()['data'])->toBe([[
        'name' => 'a.jpg', 'file_type' => 'jpg', 'size' => 2, 'created' => '2026-09-01 10:00:00',
        'last_changed' => '2026-09-02 10:00:00', 'guid' => 'g1',
    ]])
        ->and($this->bunny->listFolders()['data'][0]['name'])->toBe('sub')
        ->and($this->bunny->listAll()['data'])->toHaveCount(2)
        ->and($this->bunny->dirSize()['files'])->toBe(1)
        ->and($this->http->last()['url'])->toBe('https://storage.bunnycdn.com/my-zone/');
});

test('deleteAllFiles uses the http api', function () {
    $this->bunny->setStorageZone('my-zone', 'zone-pass');
    $this->http->push(200, STORAGE_LISTING);

    expect($this->bunny->deleteAllFiles('pets/')['files_deleted'])->toBe(1)
        ->and($this->http->last())->method->toBe('DELETE')->url->toBe('https://storage.bunnycdn.com/my-zone/pets/a.jpg');
});

test('storage zone management', function () {
    $this->bunny->addStorageZone('zone', 'de', ['ny', 'sg'], BunnyAPIStorage::ZONE_TIER_EDGE);
    expect($this->http->lastJson())->toBe(['Name' => 'zone', 'Region' => 'DE', 'ReplicationRegions' => ['NY', 'SG'], 'ZoneTier' => 1]);

    $this->bunny->deleteStorageZone(3);
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/storagezone/3?deleteLinkedPullZones=false');

    $this->bunny->resetStorageZoneReadOnlyPassword(3);
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/storagezone/resetReadOnlyPassword?id=3');

    $this->bunny->updateStorageZone(3, ['OriginUrl' => 'https://o.test']);
    expect($this->http->last())->method->toBe('POST')->url->toBe('https://api.bunny.net/storagezone/3');
});

test('ftp methods require a connection', function () {
    $this->bunny->currentDir();
})->throws(BunnyAPIException::class, 'No FTP connection');

test('http methods require a storage zone', function () {
    $this->bunny->listFiles();
})->throws(BunnyAPIException::class, 'You must select a storage zone first');
