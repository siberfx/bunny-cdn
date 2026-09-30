<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests;

use PHPUnit\Framework\TestCase;
use Siberfx\BunnyCdn\BunnyAPIException;
use Siberfx\BunnyCdn\BunnyAPIStorage;

final class StorageTest extends TestCase
{
    private FakeHttpClient $http;
    private BunnyAPIStorage $bunny;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->bunny = new BunnyAPIStorage('api-key', http: $this->http);
    }

    private const array LISTING = [
        ['ObjectName' => 'a.jpg', 'IsDirectory' => false, 'Length' => 2048, 'DateCreated' => '2026-09-01T10:00:00', 'LastChanged' => '2026-09-02T10:00:00', 'Guid' => 'g1'],
        ['ObjectName' => 'sub', 'IsDirectory' => true, 'Length' => 0, 'DateCreated' => '2026-09-01T10:00:00', 'LastChanged' => '2026-09-02T10:00:00', 'Guid' => 'g2'],
    ];

    public function testRegionHostAndAccessKeyHeader(): void
    {
        $this->bunny->setStorageZone('my-zone', 'zone-pass', 'NY');
        $this->http->push(200, self::LISTING);
        $this->bunny->listFiles('pets');
        $request = $this->http->last();
        self::assertSame('https://ny.storage.bunnycdn.com/my-zone/pets/', $request['url']);
        self::assertSame('zone-pass', $request['headers']['AccessKey']);
        self::assertStringNotContainsString('AccessKey=', $request['url']);
    }

    public function testUnknownRegionThrows(): void
    {
        $this->expectException(BunnyAPIException::class);
        $this->bunny->setStorageZone('z', 'k', 'mars');
    }

    public function testAccessKeyLookup(): void
    {
        $this->http->push(200, [['Name' => 'other', 'Password' => 'x'], ['Name' => 'my-zone', 'Password' => 'found']]);
        $this->bunny->setStorageZone('my-zone');
        self::assertSame('https://api.bunny.net/storagezone?page=0&perPage=1000&includeDeleted=false&search=my-zone', $this->http->last()['url']);
        $this->bunny->downloadFileHTTP('a.txt');
        self::assertSame('found', $this->http->last()['headers']['AccessKey']);
    }

    public function testUploadSendsChecksumAndStream(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bny');
        file_put_contents($file, 'hello');
        try {
            $this->bunny->setStorageZone('my-zone', 'zone-pass');
            $this->http->push(201, ['HttpCode' => 201, 'Message' => 'File uploaded.']);
            $result = $this->bunny->uploadFileHTTP($file, '/folder/my file.txt');
            $request = $this->http->last();
            self::assertSame('PUT', $request['method']);
            self::assertSame('https://storage.bunnycdn.com/my-zone/folder/my%20file.txt', $request['url']);
            self::assertSame(strtoupper(hash('sha256', 'hello')), $request['headers']['Checksum']);
            self::assertSame('hello', $request['body']);
            self::assertSame(201, $result['HttpCode']);
        } finally {
            unlink($file);
        }
    }

    public function testDownloadReturnsRawContents(): void
    {
        $this->bunny->setStorageZone('my-zone', 'zone-pass');
        $this->http->push(200, 'raw-bytes');
        self::assertSame('raw-bytes', $this->bunny->downloadFileHTTP('a.bin'));
    }

    public function testDeleteRootIsRefused(): void
    {
        $this->bunny->setStorageZone('my-zone', 'zone-pass');
        $this->expectException(BunnyAPIException::class);
        $this->bunny->deleteFileHTTP('/');
    }

    public function testListingHelpers(): void
    {
        $this->bunny->setStorageZone('my-zone', 'zone-pass');
        $this->http->push(200, self::LISTING)->push(200, self::LISTING)->push(200, self::LISTING)->push(200, self::LISTING);
        self::assertSame([['name' => 'a.jpg', 'file_type' => 'jpg', 'size' => 2, 'created' => '2026-09-01 10:00:00', 'last_changed' => '2026-09-02 10:00:00', 'guid' => 'g1']], $this->bunny->listFiles()['data']);
        self::assertSame('sub', $this->bunny->listFolders()['data'][0]['name']);
        self::assertCount(2, $this->bunny->listAll()['data']);
        self::assertSame(1, $this->bunny->dirSize()['files']);
        self::assertSame('https://storage.bunnycdn.com/my-zone/', $this->http->last()['url']);
    }

    public function testDeleteAllFilesUsesHttp(): void
    {
        $this->bunny->setStorageZone('my-zone', 'zone-pass');
        $this->http->push(200, self::LISTING);
        self::assertSame(1, $this->bunny->deleteAllFiles('pets/')['files_deleted']);
        self::assertSame(['DELETE', 'https://storage.bunnycdn.com/my-zone/pets/a.jpg'], [$this->http->last()['method'], $this->http->last()['url']]);
    }

    public function testStorageZoneManagement(): void
    {
        $this->bunny->addStorageZone('zone', 'de', ['ny', 'sg'], BunnyAPIStorage::ZONE_TIER_EDGE);
        self::assertSame(['Name' => 'zone', 'Region' => 'DE', 'ReplicationRegions' => ['NY', 'SG'], 'ZoneTier' => 1], $this->http->lastJson());

        $this->bunny->deleteStorageZone(3);
        self::assertSame('https://api.bunny.net/storagezone/3?deleteLinkedPullZones=false', $this->http->last()['url']);

        $this->bunny->resetStorageZoneReadOnlyPassword(3);
        self::assertSame('https://api.bunny.net/storagezone/resetReadOnlyPassword?id=3', $this->http->last()['url']);

        $this->bunny->updateStorageZone(3, ['OriginUrl' => 'https://o.test']);
        self::assertSame(['POST', 'https://api.bunny.net/storagezone/3'], [$this->http->last()['method'], $this->http->last()['url']]);
    }

    public function testFtpMethodsRequireConnection(): void
    {
        $this->expectException(BunnyAPIException::class);
        $this->bunny->currentDir();
    }

    public function testHttpMethodsRequireZone(): void
    {
        $this->expectException(BunnyAPIException::class);
        $this->bunny->listFiles();
    }
}
