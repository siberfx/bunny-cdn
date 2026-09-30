# Bunny CDN API for PHP

A PHP client for the [bunny.net](https://bunny.net) API: pull zones, Edge Storage (HTTP + FTP), Stream (video) and DNS,
plus a Flysystem v3 storage adapter and Laravel integration (service provider + `bunnycdn` disk).

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)]()
[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5%20%7C%208.6-purple.svg)]()

> Upgrading from 1.x? See [CHANGELOG.md](CHANGELOG.md#upgrading-from-1x). Keys are now passed to the constructor and
> errors are thrown as `BunnyAPIException`.

## Requirements

- PHP 8.4, 8.5 or 8.6
- `ext-curl`, `ext-json`
- `guzzlehttp/guzzle` 7, `league/flysystem` 3 *(installed automatically)*
- `ext-ftp` *(optional, only for the FTP storage methods)*
- Laravel 12 or 13 *(optional, for the service provider and `bunnycdn` disk)*

## Installation

```bash
composer require siberfx/bunny-cdn
```

## Usage

Every class extends `BunnyAPI` and takes the same constructor:

```php
use Siberfx\BunnyCdn\BunnyAPIPull;

$bunny = new BunnyAPIPull(
    api_key: 'account-api-key',                 // Dashboard -> Account settings -> API
    stream_library_access_key: 'library-key',   // only needed for BunnyAPIStream
);

// Keys can also be set / changed later
$bunny->apiKey('account-api-key')->streamLibraryAccessKey('library-key');
```

| Class             | Covers                                                        |
|-------------------|---------------------------------------------------------------|
| `BunnyAPI`        | Account: purge URL, statistics, billing, countries, regions   |
| `BunnyAPIPull`    | Pull zones, hostnames, certificates, edge rules, logs         |
| `BunnyAPIStorage` | Storage zone management, Edge Storage HTTP API, FTP           |
| `BunnyAPIStream`  | Stream collections and videos                                 |
| `BunnyAPIDNS`     | DNS zones, records, DNSSEC                                    |

### Errors

Any non-2xx response throws `Siberfx\BunnyCdn\BunnyAPIException`:

```php
use Siberfx\BunnyCdn\BunnyAPIException;

try {
    $bunny->getPullZone(1337);
} catch (BunnyAPIException $e) {
    $e->getStatusCode();    // e.g. 404
    $e->getResponseBody();  // decoded JSON body (or raw string)
    $e->getMessage();
}
```

Successful calls return the decoded JSON as an array. Empty responses (e.g. HTTP 204) return
`['http_code' => 204, 'response' => null]`. `$bunny->lastResponse()` gives the raw `HttpResponse` of the last request.

### Custom HTTP client

All requests go through `Siberfx\BunnyCdn\Http\HttpClient`. The default is `CurlHttpClient`; pass your own
implementation (e.g. for testing or a proxy) as the third constructor argument or via `setHttpClient()`.

```php
use Siberfx\BunnyCdn\Http\CurlHttpClient;

$bunny = new BunnyAPIPull('api-key', http: new CurlHttpClient(timeout: 30, connect_timeout: 5));
```

## Laravel

The service provider is auto-discovered. Publish the config and add your keys to `.env`:

```bash
php artisan vendor:publish --tag=bunny-cdn-config
```

```dotenv
BUNNY_API_KEY=
BUNNY_STORAGE_ZONE=
BUNNY_STORAGE_ACCESS_KEY=
BUNNY_STORAGE_REGION=de
BUNNY_STREAM_LIBRARY_ID=
BUNNY_STREAM_ACCESS_KEY=
```

All classes are registered as singletons and can be injected:

```php
public function upload(\Siberfx\BunnyCdn\BunnyAPIStorage $storage)
{
    $storage->uploadFileHTTP($path, 'avatars/1.jpg');
}
```

### `bunnycdn` storage disk

The provider also registers a `bunnycdn` filesystem driver (no `Storage::extend()` needed). Add a disk to
`config/filesystems.php`; any option you leave out falls back to the `bunny-cdn.storage` config above.

```php
'bunnycdn' => [
    'driver' => 'bunnycdn',
    'storage_zone' => env('BUNNY_STORAGE_ZONE'),
    'api_key' => env('BUNNY_STORAGE_ACCESS_KEY'),          // storage zone password
    'region' => env('BUNNY_STORAGE_REGION', 'de'),
    'pull_zone' => env('BUNNY_PULL_ZONE'),                  // e.g. https://my-zone.b-cdn.net (for url())
    'token_auth_key' => env('BUNNY_TOKEN_AUTH_KEY', ''),    // optional, for temporaryUrl()
    'root' => env('BUNNY_STORAGE_ROOT', ''),                // optional path prefix
],
```

```php
Storage::disk('bunnycdn')->put('index.html', '<html>Hello World</html>');
Storage::disk('bunnycdn')->url('index.html');
Storage::disk('bunnycdn')->temporaryUrl('file.pdf', now()->addHour());
Storage::disk('bunnycdn')->temporaryUrl('file.pdf', 60, ['download' => 'file.pdf']);  // minutes + signed params
```

## Account (`BunnyAPI`)

```php
$bunny->purgeCache('https://cdn.example.com/css/app.css', async: true, exact_path: false);
$bunny->getStatistics(pullzone_id: 1337, date_from: '2026-09-01', date_to: '2026-09-30', options: ['loadErrors' => true]);
$bunny->getBilling();
$bunny->getBillingSummary();
$bunny->balance();                // float
$bunny->monthCharges();           // float
$bunny->monthChargeBreakdown();   // per region
$bunny->totalBillingAmount();
$bunny->getAffiliate();
$bunny->claimAffiliate();
$bunny->getCountries();
$bunny->getRegions();
$bunny->getAbuseCases();
$bunny->getAbuseCase(12);
$bunny->checkAbuseCase(12);
$bunny->convertBytes(5368709120, 'GB');   // "5.00"
$bunny->costCalculator(5368709120);
```

## Pull zones (`BunnyAPIPull`)

```php
$pull = new BunnyAPIPull('api-key');

$pull->listPullZones(search: 'assets');
$pull->getPullZone(1337);
$pull->createPullZone('my-zone', 'https://origin.example.com', ['Type' => 0]);
$pull->updatePullZone(1337, ['CacheControlMaxAgeOverride' => 3600]);
$pull->checkPullZoneAvailability('my-zone');
$pull->purgePullZone(1337);                 // everything
$pull->purgePullZone(1337, 'images');       // by cache tag
$pull->deletePullZone(1337);

// Hostnames & SSL
$pull->pullZoneHostnames(1337);
$pull->addHostnamePullZone(1337, 'cdn.example.com');
$pull->removeHostnamePullZone(1337, 'cdn.example.com');
$pull->addFreeSSLCertificate('cdn.example.com');
$pull->addCertificate(1337, 'cdn.example.com', $pemCertificate, $pemKey);
$pull->removeCertificate(1337, 'cdn.example.com');
$pull->forceSSLPullZone(1337, 'cdn.example.com', true);

// Security
$pull->listBlockedIpPullZone(1337);
$pull->addBlockedIpPullZone(1337, '203.0.113.7');
$pull->unBlockedIpPullZone(1337, '203.0.113.7');
$pull->addAllowedReferrer(1337, 'example.com');
$pull->removeAllowedReferrer(1337, 'example.com');
$pull->addBlockedReferrer(1337, 'spam.example');
$pull->removeBlockedReferrer(1337, 'spam.example');
$pull->resetTokenKey(1337);

// Edge rules
$pull->addOrUpdateEdgeRule(1337, ['ActionType' => 1, 'Triggers' => [...], 'Enabled' => true]);
$pull->setEdgeRuleEnabled(1337, $edgeRuleGuid, false);
$pull->deleteEdgeRule(1337, $edgeRuleGuid);

// Logs
$pull->pullZoneLogs(1337, new DateTimeImmutable('yesterday'));   // legacy v1, parsed lines
$pull->pullZoneLogsV2(1337, new DateTimeImmutable('-1 day'), new DateTimeImmutable(), ['status' => '5xx']);
```

## Storage (`BunnyAPIStorage`)

### Storage zone management (account API key)

```php
$storage = new BunnyAPIStorage('api-key');

$storage->listStorageZones(search: 'backups');
$storage->getStorageZone(12);
$storage->addStorageZone('backups', 'DE', ['NY', 'SG'], BunnyAPIStorage::ZONE_TIER_STANDARD);
$storage->updateStorageZone(12, ['OriginUrl' => 'https://example.com', 'Rewrite404To200' => true]);
$storage->deleteStorageZone(12);                      // keeps linked pull zones
$storage->deleteStorageZone(12, delete_linked_pull_zones: true);
$storage->getStorageZoneStatistics(12, '2026-09-01', '2026-09-30');
$storage->resetStorageZonePassword(12);
$storage->resetStorageZoneReadOnlyPassword(12);
$storage->checkStorageZoneAvailability('backups');
$storage->getStorageRegions();
```

### Edge Storage HTTP API

Select the zone first. The primary region decides the hostname (`de`, `uk`, `se`, `ny`, `la`, `sg`, `syd`, `br`, `jh`).
If the zone password is omitted it is looked up with the account API key.

```php
$storage->setStorageZone('backups', 'zone-password', 'ny');

$storage->uploadFileHTTP('/local/cat.jpg', 'pets/cat.jpg');     // sends a SHA256 Checksum header
$storage->uploadContentHTTP('{"a":1}', 'data/a.json', content_type: 'application/json');
$storage->downloadFileHTTP('pets/cat.jpg');                     // file contents as string
$storage->deleteFileHTTP('pets/cat.jpg');
$storage->deleteFileHTTP('pets/');                              // deletes the folder recursively

$storage->listDirectory('pets');   // raw API listing
$storage->listFiles('pets');       // formatted files
$storage->listFolders('pets');     // formatted folders
$storage->listAll('pets');         // formatted files + folders
$storage->dirSize('pets');
$storage->deleteAllFiles('pets');
$storage->downloadAll('pets', '/local/pets/');
```

### FTP (requires `ext-ftp`)

```php
$storage->zoneConnect('backups', 'zone-password', 'ny');

$storage->uploadFile('/local/cat.jpg', 'pets/cat.jpg');
$storage->uploadFileWithProgress('/local/big.zip', 'big.zip', 'UPLOAD_PERCENT.txt');
$storage->uploadAllFiles('/local/pets', 'pets/');
$storage->downloadFile('/local/cat.jpg', 'pets/cat.jpg');
$storage->downloadFileWithProgress('/local/big.zip', 'big.zip');
$storage->fileExists('pets/cat.jpg');
$storage->folderExists('pets');
$storage->getFileSize('pets/cat.jpg');
$storage->createFolder('pets');
$storage->deleteFolder('pets');
$storage->deleteFile('pets/cat.jpg');
$storage->renameFile('pets/', 'cat.jpg', 'kitten.jpg');
$storage->moveFile('pets/', 'kitten.jpg', 'pets/young/');
$storage->currentDir();
$storage->changeDir('pets');
$storage->moveUpOne();
$storage->closeConnection();
```

## Flysystem adapter

A [Flysystem v3](https://flysystem.thephpleague.com) adapter for Edge Storage, based on
[platformcommunity/flysystem-bunnycdn](https://github.com/PlatformCommunity/flysystem-bunnycdn) and maintained here.

```php
use League\Flysystem\Filesystem;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNRegion;

$adapter = new BunnyCDNAdapter(
    new BunnyCDNClient('storage-zone', 'storage-zone-password', BunnyCDNRegion::FALKENSTEIN),
    'https://my-zone.b-cdn.net',   // pull zone URL, optional (enables publicUrl())
    'assets',                      // root path, optional
);
$adapter->setTokenAuthKey('token-auth-key');   // optional (enables temporaryUrl())

$filesystem = new Filesystem($adapter);
$filesystem->write('hello.txt', 'Hello');
$filesystem->publicUrl('hello.txt');
$filesystem->temporaryUrl('hello.txt', new DateTimeImmutable('+1 hour'));
$filesystem->checksum('hello.txt', ['checksum_algo' => 'sha256']);   // uses Bunny's stored checksum
```

Supports read/write (strings and streams), copy, move, delete, directories, deep listing, file size, mime type,
last modified, MD5/SHA256 checksums, public and signed temporary URLs, and concurrent batch uploads:

```php
use League\Flysystem\Config;
use Siberfx\BunnyCdn\Flysystem\WriteBatchFile;

$adapter->writeBatch([
    new WriteBatchFile('/local/a.jpg', 'images/a.jpg'),
    new WriteBatchFile('/local/b.jpg', 'images/b.jpg'),
], new Config(['concurrency' => 20]));
```

Regions: `FALKENSTEIN` (de), `STOCKHOLM` (se), `UNITED_KINGDOM` (uk), `NEW_YORK` (ny), `LOS_ANGELES` (la), `SINGAPORE` (sg),
`SYDNEY` (syd), `BRAZIL` (br), `JOHANNESBURG` (jh).

**Migrating from `platformcommunity/flysystem-bunnycdn`:** replace the namespace
`PlatformCommunity\Flysystem\BunnyCDN` with `Siberfx\BunnyCdn\Flysystem`; class names and constructor arguments are
unchanged. In Laravel you can drop your `Storage::extend('bunnycdn', ...)` code – the driver is registered for you.

## Stream (`BunnyAPIStream`)

```php
$stream = new BunnyAPIStream(stream_library_access_key: 'library-key');
$stream->setStreamLibraryId(1234);

// Collections
$stream->getStreamCollections(search: 'trailers');
$stream->createCollection('Trailers');
$stream->setStreamCollectionGuid('886gce58-...');
$stream->getStreamForCollection();
$stream->getStreamCollectionSize();
$stream->updateCollection('Movie trailers');
$stream->deleteCollection();

// Videos
$stream->listVideos(search: 'cat');
$stream->listVideosForCollectionId();
$video = $stream->createVideo('My video');               // or createVideoForCollection()
$stream->uploadVideo($video['guid'], '/local/video.mp4', ['enabledResolutions' => '720p,1080p']);
$stream->fetchVideo('https://example.com/video.mp4', title: 'Fetched');
$stream->getVideo($guid);
$stream->updateVideo($guid, ['title' => 'New title', 'metaTags' => [...]]);
$stream->deleteVideo($guid);
$stream->setThumbnail($guid, 'https://example.com/thumb.jpg');
$stream->addCaptions($guid, 'en', 'English', '/local/en.vtt');
$stream->deleteCaptions($guid, 'en');
$stream->reEncodeVideo($guid);
$stream->repackageVideo($guid);
$stream->transcribeVideo($guid, ['en', 'de'], 'en');
$stream->getVideoResolutions($guid);
$stream->cleanupVideoResolutions($guid, ['resolutionsToDelete' => '240p', 'dryRun' => true]);
$stream->getVideoHeatmap($guid);
$stream->getVideoPlayData($guid);
$stream->getVideoStatistics(date_from: '2026-09-01');
$stream->videoResolutionsArray($guid);
$stream->videoSize($guid, 'MB');
```

## DNS (`BunnyAPIDNS`)

```php
use Siberfx\BunnyCdn\DnsRecordType;

$dns = new BunnyAPIDNS('api-key');

$dns->getDNSZones(search: 'example.com');
$dns->getDNSZone(1234);
$dns->checkDNSZoneAvailability('example.com');
$dns->addDNSZone('example.com', logging: true);
$dns->addDNSZoneFull(['Domain' => 'example.com', 'Records' => [...]]);
$dns->updateDNSZone(1234, ['SoaEmail' => 'admin@example.com']);
$dns->updateDNSZoneNameservers(1234, true, 'ns1.example.com', 'ns2.example.com');
$dns->updateDNSZoneLogging(1234, true, 0, true);
$dns->updateDNSZoneSoaEmail(1234, 'admin@example.com');
$dns->getDNSZoneStatistics(1234, '2026-09-01', '2026-09-30');
$dns->exportDNSZone(1234);          // BIND zone file
$dns->enableDNSSEC(1234);
$dns->disableDNSSEC(1234);
$dns->deleteDNSZone(1234);

// Records
$dns->listDNSRecords(1234, type: DnsRecordType::TXT);
$dns->addDNSRecord(1234, 'www', '203.0.113.7', ['Type' => DnsRecordType::A, 'Ttl' => 120]);
$dns->addDNSRecordA(1234, 'www', '203.0.113.7');
$dns->addDNSRecordAAAA(1234, 'www', '2001:db8::7');
$dns->addDNSRecordCNAME(1234, 'cdn', 'example.b-cdn.net');
$dns->addDNSRecordFlatten(1234, '', 'example.b-cdn.net');
$dns->addDNSRecordMX(1234, '', 'mail.example.com', 10);
$dns->addDNSRecordTXT(1234, '', 'v=spf1 -all');
$dns->addDNSRecordNS(1234, 'sub', 'ns1.example.net');
$dns->addDNSRecordSRV(1234, '_sip._tcp', 'sip.example.com', 5060);
$dns->addDNSRecordCAA(1234, '', 'issue', 'letsencrypt.org');
$dns->addDNSRecordRedirect(1234, 'old', 'https://example.com');
$dns->addDNSRecordPullZone(1234, 'cdn', 1337);
$dns->addDNSRecordScript(1234, 'api', 55);
$dns->updateDNSRecord(1234, 9876, ['Value' => '203.0.113.8']);
$dns->updateDNSRecordA(1234, 9876, 'www', '203.0.113.8');   // also AAAA, CNAME, MX, TXT, NS
$dns->disableDNSRecord(1234, 9876);
$dns->enableDNSRecord(1234, 9876);
$dns->deleteDNSRecord(1234, 9876);
$dns->recheckDNSRecord(1234);
$dns->dismissDNSConfigNotice(1234);
```

## Testing

```bash
composer test      # PHPUnit, incl. the Flysystem adapter conformance suite
composer analyse   # PHPStan
```

## License

MIT. Includes code from [cp6/BunnyCDN-API](https://github.com/cp6/BunnyCDN-API) and
[PlatformCommunity/flysystem-bunnycdn](https://github.com/PlatformCommunity/flysystem-bunnycdn) (both MIT), see [LICENSE](LICENSE).
