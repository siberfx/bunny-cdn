# Native usage guide

How to use `siberfx/bunny-cdn` in plain PHP (no framework). Every section below is backed by a runnable script in
[`native-usage-docs/`](native-usage-docs/README.md). The snippets here are trimmed; open the linked file for the full
example. Laravel usage is covered in the [README](README.md#laravel).

## Contents

- [Setup](#setup)
  - [Installation](#installation)
  - [Running the examples](#running-the-examples)
  - [Environment variables](#environment-variables)
- [1. Getting started](#1-getting-started): clients, keys, responses, errors
- [2. Account & billing](#2-account--billing): statistics, billing, purge, reference data
- [3. Pull zones](#3-pull-zones): CRUD, hostnames & SSL, security, edge rules, logs
- [4. Storage zones](#4-storage-zones): create, replicate, update, statistics, passwords
- [5. Edge Storage over HTTP](#5-edge-storage-over-http): upload, download, list, delete
- [6. Edge Storage over FTP](#6-edge-storage-over-ftp): folders, progress, rename, move
- [7. Stream (video)](#7-stream-video): collections, uploads, captions, transcription
- [8. DNS](#8-dns): zones, every record type, DNSSEC, export
- [9. Flysystem adapter](#9-flysystem-adapter): Flysystem v3 on Edge Storage
- [10. Custom HTTP clients](#10-custom-http-clients): logging, retries, fakes for tests
- [Which key goes where](#which-key-goes-where)

---

## Setup

### Installation

```bash
composer require siberfx/bunny-cdn
```

Requires PHP 8.4+ with `ext-curl` and `ext-json`. The FTP methods additionally need `ext-ftp`.

All API clients live in the `Siberfx\BunnyCdn\BunnyCore` namespace, the Flysystem adapter in `Siberfx\BunnyCdn\Flysystem`.

### Running the examples

From the package root (after `composer install`):

```bash
BUNNY_API_KEY=your-account-key php native-usage-docs/02-account-and-billing.php
```

The examples are **read-only by default**. Anything that creates, updates, uploads or deletes only runs when you add
`BUNNY_EXAMPLES_WRITE=1`, and those examples clean up after themselves. Use a test zone when you enable it.

All examples share [`native-usage-docs/bootstrap.php`](native-usage-docs/bootstrap.php), which provides three helpers:

| Helper | Purpose |
|--------|---------|
| `envVar('NAME', 'default')` | Read an environment variable, or fail with a clear message when it is required and missing |
| `writes()` | `true` when `BUNNY_EXAMPLES_WRITE=1` |
| `show('Title', $value)` | Print a titled section (arrays as pretty JSON) |

### Environment variables

| Variable | Used by |
|----------|---------|
| `BUNNY_API_KEY` | Account API key: examples 1–4, 8, 10 (and 5, to look up a zone password) |
| `BUNNY_PULL_ZONE_ID`, `BUNNY_CDN_HOSTNAME` | 2, 3 |
| `BUNNY_STORAGE_ZONE`, `BUNNY_STORAGE_ACCESS_KEY`, `BUNNY_STORAGE_REGION` | 5, 6, 9 |
| `BUNNY_PULL_ZONE_URL`, `BUNNY_TOKEN_AUTH_KEY` | 9 (public and signed URLs) |
| `BUNNY_STREAM_LIBRARY_ID`, `BUNNY_STREAM_ACCESS_KEY` | 7 |
| `BUNNY_DNS_ZONE_ID` | 8 |
| `BUNNY_EXAMPLES_WRITE=1` | Enables the calls that change your account |

---

## 1. Getting started

📄 [`native-usage-docs/01-getting-started.php`](native-usage-docs/01-getting-started.php)

```bash
BUNNY_API_KEY=... php native-usage-docs/01-getting-started.php
```

### Creating clients

Every client takes the same constructor: `(api_key, stream_library_access_key, http)`. `BunnyAPIPull`, `BunnyAPIStorage`,
`BunnyAPIStream` and `BunnyAPIDNS` all extend `BunnyAPI`, so the account methods are available on each of them.

```php
use Siberfx\BunnyCdn\BunnyCore\BunnyAPI;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIPull;

$bunny = new BunnyAPI(api_key: 'account-api-key');

// Keys can be set or swapped later; setters are chainable
$pull = new BunnyAPIPull()
    ->apiKey('account-api-key')
    ->streamLibraryAccessKey('stream-library-key');
```

### Responses

Successful calls return the decoded JSON as an array. Empty responses (e.g. HTTP 204) return
`['http_code' => 204, 'response' => null]`. The raw response of the last request is always available:

```php
$regions = $bunny->getRegions();

$last = $bunny->lastResponse();   // Siberfx\BunnyCdn\BunnyCore\Http\HttpResponse
$last->status;                    // 200
$last->body;                      // raw JSON string
```

### Errors

Every non-2xx response throws `BunnyAPIException`. Missing keys are reported before any request is sent.

```php
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIException;

try {
    $pull->getPullZone(0);
} catch (BunnyAPIException $e) {
    $e->getStatusCode();     // e.g. 404
    $e->getResponseBody();   // decoded JSON body, or the raw string
    $e->getMessage();        // "Bunny API GET https://api.bunny.net/pullzone/0 failed with HTTP 404: ..."
}
```

### Timeouts

```php
use Siberfx\BunnyCdn\BunnyCore\Http\CurlHttpClient;

$bunny = new BunnyAPI('account-api-key', http: new CurlHttpClient(timeout: 60, connect_timeout: 5));
```

### Helpers without an API call

```php
$bunny->convertBytes(5_368_709_120);               // "5.00"  (GB, formatted)
$bunny->convertBytes(5_368_709_120, 'MB', false);  // 5120    (raw number)
$bunny->costCalculator(1_099_511_627_776);         // estimated traffic & storage cost for 1 TB
```

---

## 2. Account & billing

📄 [`native-usage-docs/02-account-and-billing.php`](native-usage-docs/02-account-and-billing.php)

```bash
BUNNY_API_KEY=... [BUNNY_PULL_ZONE_ID=123] php native-usage-docs/02-account-and-billing.php
```

### Statistics

```php
$stats = $bunny->getStatistics(date_from: '2026-09-01', date_to: '2026-09-30');
$stats['TotalBandwidthUsed'];
$stats['TotalRequestsServed'];
$stats['CacheHitRate'];

// Hourly, for one pull zone, with extra chart data (any "load*" flag of the API)
$bunny->getStatistics(
    pullzone_id: 1337,
    hourly: true,
    date_from: '2026-09-24',
    date_to: '2026-09-30',
    options: ['loadErrors' => true, 'loadOriginTraffic' => true],
);
```

### Billing

```php
$bunny->balance();                         // float
$bunny->monthCharges();                    // float, charges this month
$bunny->monthChargeBreakdown();            // ['storage' => …, 'EU' => …, 'US' => …, 'ASIA' => …, 'SA' => …, 'AF' => …]
$bunny->totalBillingAmount(format: true);  // ['amount' => 123.45, 'since' => '2025-01-01 00:00:00']
$bunny->getBilling();                      // raw billing data
$bunny->getBillingSummary();
$bunny->getAffiliate();
$bunny->claimAffiliate();                  // moves the affiliate balance to your account (writes)
```

### Purging URLs

```php
$bunny->purgeCache('https://cdn.example.com/css/app.css');                  // one URL, waits for completion
$bunny->purgeCache('https://cdn.example.com/images/*', async: true);        // wildcard, in the background
$bunny->purgeCache('https://cdn.example.com/feed.xml?page=2', exact_path: true);  // exactly this path + query
```

### Reference data & abuse cases

```php
$bunny->getCountries();
$bunny->getRegions();
$bunny->getAbuseCases();
$bunny->getAbuseCase(12);
$bunny->checkAbuseCase(12);
```

---

## 3. Pull zones

📄 [`native-usage-docs/03-pull-zones.php`](native-usage-docs/03-pull-zones.php)

```bash
BUNNY_API_KEY=... [BUNNY_PULL_ZONE_ID=123] [BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/03-pull-zones.php
```

```php
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIPull;

$pull = new BunnyAPIPull('account-api-key');
```

### Listing & reading

```php
$zones = $pull->listPullZones();                                        // all zones (plain array)
$page  = $pull->listPullZones(page: 1, per_page: 10, search: 'assets');  // ['Items' => [...], 'HasMoreItems' => ...]

$zone = $pull->getPullZone(1337, include_cert: false);
$pull->pullZoneHostnames(1337);       // ['hostname_count' => 2, 'hostnames' => [['id', 'hostname', 'force_ssl'], ...]]
$pull->listBlockedIpPullZone(1337);   // ['blocked_ip_count' => 1, 'ips' => [...]]
$pull->checkPullZoneAvailability('my-new-zone');
```

### Creating, updating, deleting

Any field from the [pull zone API](https://docs.bunny.net/reference/pullzonepublic_add) can be passed.

```php
$created = $pull->createPullZone('my-zone', 'https://origin.example.com', [
    'Type' => 0,                    // 0 = premium, 1 = volume
    'EnableGeoZoneUS' => true,
    'EnableGeoZoneASIA' => false,
]);

$pull->updatePullZone($created['Id'], [
    'CacheControlMaxAgeOverride' => 86_400,
    'EnableQueryStringOrdering' => true,
    'OriginShieldZoneCode' => 'FR',
]);

$pull->deletePullZone($created['Id']);
```

### Hostnames & SSL

```php
$pull->addHostnamePullZone(1337, 'cdn.example.com');
$pull->addFreeSSLCertificate('cdn.example.com');              // Let's Encrypt
$pull->forceSSLPullZone(1337, 'cdn.example.com', true);

// Bring your own certificate (PEM strings, base64 encoded for you)
$pull->addCertificate(1337, 'cdn.example.com', file_get_contents('cert.pem'), file_get_contents('key.pem'));
$pull->removeCertificate(1337, 'cdn.example.com');

$pull->removeHostnamePullZone(1337, 'cdn.example.com');
```

### Security

```php
$pull->addBlockedIpPullZone(1337, '203.0.113.7');
$pull->unBlockedIpPullZone(1337, '203.0.113.7');
$pull->addAllowedReferrer(1337, 'example.com');
$pull->removeAllowedReferrer(1337, 'example.com');
$pull->addBlockedReferrer(1337, 'spam.example');
$pull->removeBlockedReferrer(1337, 'spam.example');
$pull->resetTokenKey(1337);                                   // rotate the token authentication key
```

### Edge rules

```php
// Redirect http(s)://old.example.com/* to https://example.com
$pull->addOrUpdateEdgeRule(1337, [
    'ActionType' => 1,                                        // 1 = redirect
    'ActionParameter1' => 'https://example.com{{path}}',
    'ActionParameter2' => '301',
    'TriggerMatchingType' => 0,                               // 0 = match any
    'Triggers' => [[
        'Type' => 0,                                          // 0 = URL
        'PatternMatches' => ['*://old.example.com/*'],
        'PatternMatchingType' => 0,
    ]],
    'Description' => 'Redirect old domain',
    'Enabled' => true,
]);

$rule = array_find($pull->getPullZone(1337)['EdgeRules'], fn (array $r): bool => $r['Description'] === 'Redirect old domain');
$pull->setEdgeRuleEnabled(1337, $rule['Guid'], false);
$pull->deleteEdgeRule(1337, $rule['Guid']);
```

Pass an existing `Guid` in the rule array to update a rule instead of adding one.

### Purging

```php
$pull->purgePullZone(1337);                              // everything
$pull->purgePullZone(1337, cache_tag: 'product-42');     // only responses tagged "product-42"
```

### Logs

```php
// Legacy log (v1), one day, parsed into arrays
$lines = $pull->pullZoneLogs(1337, new DateTimeImmutable('yesterday'));
// [['cache_result' => 'HIT', 'status' => 200, 'datetime' => '…', 'bytes' => 1024, 'ip' => '…',
//   'referer' => '…', 'file_url' => '…', 'user_agent' => '…', 'request_id' => '…', 'cdn_dc' => 'DE',
//   'zone_id' => 1337, 'country_code' => 'DE'], ...]

// Logging API v2: JSON, filtered, max. 3 day range
$pull->pullZoneLogsV2(
    1337,
    from: new DateTimeImmutable('-1 day'),
    to: new DateTimeImmutable(),
    filters: ['status' => '4xx,5xx', 'country' => 'DE', 'limit' => 20],
);
```

---

## 4. Storage zones

📄 [`native-usage-docs/04-storage-zones.php`](native-usage-docs/04-storage-zones.php)

```bash
BUNNY_API_KEY=... [BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/04-storage-zones.php
```

Storage zone management uses the **account** API key.

```php
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;

$storage = new BunnyAPIStorage('account-api-key');
```

### Listing & inspecting

```php
$storage->listStorageZones();                                        // all zones
$storage->listStorageZones(page: 1, per_page: 5, search: 'backup');  // paged
$storage->getStorageZone(12);
$storage->getStorageRegions();
$storage->checkStorageZoneAvailability('my-new-zone');
$storage->getStorageZoneStatistics(12, '2026-09-01', '2026-09-30');
```

### Creating

```php
$zone = $storage->addStorageZone(
    'my-new-zone',
    main_region: 'DE',
    replicated_regions: ['NY', 'SG'],
    zone_tier: BunnyAPIStorage::ZONE_TIER_STANDARD,   // or ZONE_TIER_EDGE (SSD)
);
$zone['Password'];   // the zone password, used by the Edge Storage API and FTP
```

### Updating, rotating passwords, deleting

```php
$storage->updateStorageZone($zone['Id'], [
    'OriginUrl' => 'https://origin.example.com',
    'Custom404FilePath' => '/404.html',
    'Rewrite404To200' => false,
    'ReplicationZones' => ['NY', 'SG', 'LA'],
]);

$storage->resetStorageZonePassword($zone['Id']);
$storage->resetStorageZoneReadOnlyPassword($zone['Id']);

$storage->deleteStorageZone($zone['Id']);                                   // keeps linked pull zones
$storage->deleteStorageZone($zone['Id'], delete_linked_pull_zones: true);   // deletes them too
```

---

## 5. Edge Storage over HTTP

📄 [`native-usage-docs/05-edge-storage-http.php`](native-usage-docs/05-edge-storage-http.php)

```bash
BUNNY_STORAGE_ZONE=my-zone BUNNY_STORAGE_ACCESS_KEY=... [BUNNY_STORAGE_REGION=ny] \
[BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/05-edge-storage-http.php
```

### Selecting a zone and region

The zone's **primary region** decides the hostname: `de` (Falkenstein, default), `uk`, `se`, `ny`, `la`, `sg`, `syd`,
`br`, `jh`. Without a password, the zone password is looked up with the account API key.

```php
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;

$storage = new BunnyAPIStorage()->setStorageZone('my-zone', 'zone-password', 'ny');
$storage->storageHostname();   // "ny.storage.bunnycdn.com"

// Password lookup with the account key
$storage = new BunnyAPIStorage('account-api-key')->setStorageZone('my-zone', region: 'ny');
```

### Listing

```php
$storage->listDirectory('pets');   // raw API listing
$storage->listFiles('pets');       // ['storage_name', 'current_dir', 'data' => [['name', 'file_type', 'size' (KB), 'created', 'last_changed', 'guid'], ...]]
$storage->listFolders('pets');     // folders only
$storage->listAll('pets');         // files and folders, with 'is_dir'
$storage->dirSize('pets');         // ['files' => 12, 'size_b' => …, 'size_kb' => …, 'size_mb' => …, 'size_gb' => …]
```

### Uploading

A SHA256 `Checksum` header is sent by default, so Bunny rejects corrupted uploads.

```php
$storage->uploadFileHTTP('/local/cat.jpg', 'pets/cat.jpg');                     // streams the file
$storage->uploadFileHTTP('/local/cat.jpg', 'pets/cat.jpg', checksum: false);    // skip the checksum
$storage->uploadContentHTTP('{"ok":true}', 'data/status.json', content_type: 'application/json');
```

### Downloading

```php
$contents = $storage->downloadFileHTTP('pets/cat.jpg');   // file contents as a string
$storage->downloadAll('pets', '/local/pets/');            // every file of a folder (not recursive)
```

### Deleting

```php
$storage->deleteFileHTTP('pets/cat.jpg');   // one file
$storage->deleteAllFiles('pets');           // every file in the folder (not sub folders)
$storage->deleteFileHTTP('pets/');          // trailing slash: the folder, recursively
$storage->deleteFileHTTP('/');              // throws: deleting the zone root is refused
```

---

## 6. Edge Storage over FTP

📄 [`native-usage-docs/06-edge-storage-ftp.php`](native-usage-docs/06-edge-storage-ftp.php)

```bash
BUNNY_STORAGE_ZONE=my-zone BUNNY_STORAGE_ACCESS_KEY=... [BUNNY_STORAGE_REGION=ny] \
BUNNY_EXAMPLES_WRITE=1 php native-usage-docs/06-edge-storage-ftp.php
```

Requires `ext-ftp`. Prefer the HTTP API unless you need FTP style operations (navigation, progress files, rename).
FTP methods throw `BunnyAPIException` when `zoneConnect()` was not called.

```php
$storage = new BunnyAPIStorage()->zoneConnect('my-zone', 'zone-password', 'ny');
```

### Folders & navigation

```php
$storage->createFolder('pets');     // ['response' => 'success', 'action' => 'createFolder', 'value' => 'pets']
$storage->folderExists('pets');     // true
$storage->changeDir('pets');
$storage->currentDir();             // current FTP working directory
$storage->moveUpOne();
$storage->deleteFolder('pets');     // folder must be empty
```

### Uploading & downloading

```php
$storage->uploadFile('/local/a.txt', 'pets/a.txt');
$storage->uploadAllFiles('/local/pets', 'pets/');                   // every file of a local folder
$storage->uploadFileWithProgress('/local/big.zip', 'big.zip', '/tmp/UPLOAD_PERCENT.txt');

$storage->downloadFile('/local/a-copy.txt', 'pets/a.txt');
$storage->downloadFileWithProgress('/local/big.zip', 'big.zip', '/tmp/DOWNLOAD_PERCENT.txt');
```

The `*WithProgress` methods write the percentage (0–100) to the given file, so another process can poll it.

### Inspecting, renaming, moving, deleting

```php
$storage->fileExists('pets/a.txt');
$storage->getFileSize('pets/a.txt');                          // bytes
$storage->convertBytes($storage->getFileSize('big.zip'), 'MB');

// Bunny FTP has no rename: the file is copied through a temp file, then deleted
$storage->renameFile('pets/', 'a.txt', 'renamed.txt');       // pets/a.txt -> pets/renamed.txt
$storage->moveFile('pets/', 'renamed.txt', 'pets/archive/'); // -> pets/archive/renamed.txt

$storage->deleteFile('pets/archive/renamed.txt');
$storage->closeConnection();
```

---

## 7. Stream (video)

📄 [`native-usage-docs/07-stream.php`](native-usage-docs/07-stream.php)

```bash
BUNNY_STREAM_LIBRARY_ID=1234 BUNNY_STREAM_ACCESS_KEY=... [BUNNY_EXAMPLES_WRITE=1] \
php native-usage-docs/07-stream.php [/path/to/video.mp4]
```

Stream calls use the **library API key** (Stream → library → API), not the account key.

```php
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStream;

$stream = new BunnyAPIStream(stream_library_access_key: 'library-key')->setStreamLibraryId(1234);
```

### Videos

```php
$stream->listVideos(items_pp: 10, order_by: 'date');       // ['items' => [...], 'totalItems' => …]
$stream->listVideos(search: 'cat', collection: $collectionGuid);
$stream->getVideo($guid);
$stream->videoResolutionsArray($guid);                     // ['360p', '720p', ...]
$stream->getVideoResolutions($guid);                       // stored resolutions / outputs
$stream->videoSize($guid, 'MB', format: true);
$stream->getVideoHeatmap($guid);
$stream->getVideoPlayData($guid);
$stream->getVideoStatistics(date_from: '2026-09-01', hourly: false, video_guid: $guid);
```

`setStreamVideoGuid($guid)` lets you omit the guid on `getVideo()`, `deleteVideo()`, `getVideoHeatmap()` and
`getVideoPlayData()`.

### Collections

```php
$collection = $stream->createCollection('Trailers');
$stream->setStreamCollectionGuid($collection['guid']);
$stream->updateCollection('Movie trailers');
$stream->getStreamForCollection(include_thumbnails: true);
$stream->getStreamCollectionSize();
$stream->getStreamCollections(search: 'trailers');
$stream->listVideosForCollectionId();
$stream->deleteCollection();
```

### Uploading & fetching

```php
// 1. create the video object, 2. upload the file (streamed)
$video = $stream->createVideo('My video', collection_id: $collectionGuid, thumbnail_time: 5_000);
// or $stream->createVideoForCollection('My video') after setStreamCollectionGuid()

$stream->uploadVideo($video['guid'], '/local/video.mp4', [
    'enabledResolutions' => '360p,720p,1080p',
    'transcribeEnabled' => true,
]);

// Or let Bunny download it from a URL (optionally with request headers)
$stream->fetchVideo(
    'https://example.com/videos/intro.mp4',
    collection_id: $collectionGuid,
    title: 'Intro',
    headers: ['Authorization' => 'Bearer secret'],
);
```

### Editing a video

```php
$stream->updateVideo($guid, [
    'title' => 'New title',
    'chapters' => [['title' => 'Intro', 'start' => 0, 'end' => 30]],
    'metaTags' => [['property' => 'description', 'value' => 'My description']],
]);

$stream->setThumbnail($guid, 'https://example.com/thumbnail.jpg');
$stream->addCaptions($guid, 'en', 'English', '/local/en.vtt');   // a file path or the caption contents
$stream->deleteCaptions($guid, 'en');

$stream->transcribeVideo($guid, target_languages: ['en', 'de'], source_language: 'en');
$stream->reEncodeVideo($guid);
$stream->repackageVideo($guid, keep_original_files: true);
$stream->cleanupVideoResolutions($guid, ['resolutionsToDelete' => '240p', 'dryRun' => true]);

$stream->deleteVideo($guid);
```

---

## 8. DNS

📄 [`native-usage-docs/08-dns.php`](native-usage-docs/08-dns.php)

```bash
BUNNY_API_KEY=... [BUNNY_DNS_ZONE_ID=123] [BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/08-dns.php
```

```php
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIDNS;
use Siberfx\BunnyCdn\BunnyCore\DnsRecordType;

$dns = new BunnyAPIDNS('account-api-key');
```

### Zones

```php
$dns->getDNSZones(per_page: 100, search: 'example.com');   // ['Items' => [...]]
$dns->getDNSZone(1234);                                     // includes 'Records'
$dns->checkDNSZoneAvailability('example.com');
$dns->getDNSZoneStatistics(1234, '2026-09-01', '2026-09-30');
$dns->exportDNSZone(1234);                                  // BIND zone file (string)

$zone = $dns->addDNSZone('example.com', logging: true);
$dns->addDNSZoneFull(['Domain' => 'example.com', 'Records' => [...]]);
$dns->deleteDNSZone($zone['Id']);
```

### Records

One helper per common type (TTL defaults to 300, weight to 100):

```php
$dns->addDNSRecordA(1234, 'www', '203.0.113.10', ttl: 300);
$dns->addDNSRecordAAAA(1234, 'www', '2001:db8::10');
$dns->addDNSRecordCNAME(1234, 'cdn', 'example.b-cdn.net');
$dns->addDNSRecordFlatten(1234, '', 'example.b-cdn.net');           // CNAME flattening on the apex
$dns->addDNSRecordTXT(1234, '', 'v=spf1 include:_spf.example.com -all');
$dns->addDNSRecordMX(1234, '', 'mail.example.com', priority: 10);
$dns->addDNSRecordSRV(1234, '_sip._tcp', 'sip.example.com', port: 5060, priority: 10);
$dns->addDNSRecordCAA(1234, '', 'issue', 'letsencrypt.org');
$dns->addDNSRecordNS(1234, 'sub', 'ns1.example.net');
$dns->addDNSRecordRedirect(1234, 'old', 'https://example.com');
$dns->addDNSRecordPullZone(1234, 'assets', pullzone_id: 1337);       // link a pull zone
$dns->addDNSRecordScript(1234, 'api', script_id: 678);               // link an edge script
```

Any other type or field goes through the generic method. `DnsRecordType` covers every type, including SVCB, HTTPS,
TLSA and PTR:

```php
$dns->addDNSRecord(1234, 'geo', '203.0.113.20', [
    'Type' => DnsRecordType::A,
    'Ttl' => 60,
    'Weight' => 50,
    'Accelerated' => true,
    'Comment' => 'Weighted A record',
]);

$dns->listDNSRecords(1234, type: DnsRecordType::TXT, search: 'spf');
DnsRecordType::from($record['Type'])->name;   // "A", "CNAME", ...
```

### Updating, toggling, deleting

```php
$dns->updateDNSRecordA(1234, $recordId, 'www', '203.0.113.11');   // also AAAA, CNAME, MX, TXT, NS
$dns->updateDNSRecord(1234, $recordId, ['Ttl' => 3600, 'Comment' => 'updated']);
$dns->disableDNSRecord(1234, $recordId);
$dns->enableDNSRecord(1234, $recordId);
$dns->deleteDNSRecord(1234, $recordId);
```

### Zone settings & DNSSEC

```php
$dns->updateDNSZone(1234, ['SoaEmail' => 'hostmaster@example.com']);   // any zone field
$dns->updateDNSZoneSoaEmail(1234, 'hostmaster@example.com');
$dns->updateDNSZoneLogging(1234, enable_logging: true, log_anon_type: 0, use_log_anon: true);
$dns->updateDNSZoneNameservers(1234, true, 'ns1.example.com', 'ns2.example.com');

$dns->enableDNSSEC(1234);     // publish the returned DS record at your registrar
$dns->disableDNSSEC(1234);

$dns->recheckDNSRecord(1234);
$dns->dismissDNSConfigNotice(1234);
```

---

## 9. Flysystem adapter

📄 [`native-usage-docs/09-flysystem.php`](native-usage-docs/09-flysystem.php)

```bash
BUNNY_STORAGE_ZONE=my-zone BUNNY_STORAGE_ACCESS_KEY=... [BUNNY_STORAGE_REGION=ny] \
[BUNNY_PULL_ZONE_URL=https://my-zone.b-cdn.net] [BUNNY_TOKEN_AUTH_KEY=...] \
BUNNY_EXAMPLES_WRITE=1 php native-usage-docs/09-flysystem.php
```

### Setup

```php
use League\Flysystem\Filesystem;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNRegion;

$client = new BunnyCDNClient('my-zone', 'zone-password', BunnyCDNRegion::NEW_YORK);

$adapter = new BunnyCDNAdapter(
    $client,
    'https://my-zone.b-cdn.net',   // pull zone URL: enables publicUrl()
    root: 'uploads',               // optional: scope everything to a folder
)->setTokenAuthKey('token-auth-key');   // optional: enables temporaryUrl()

$filesystem = new Filesystem($adapter);
```

Regions: `FALKENSTEIN` (de), `STOCKHOLM` (se), `UNITED_KINGDOM` (uk), `NEW_YORK` (ny), `LOS_ANGELES` (la),
`SINGAPORE` (sg), `SYDNEY` (syd), `BRAZIL` (br), `JOHANNESBURG` (jh).

### Reading & writing

```php
$filesystem->write('hello.txt', 'Hello');
$filesystem->writeStream('big.bin', fopen('/local/big.bin', 'rb'));
$filesystem->read('hello.txt');
$filesystem->readStream('big.bin');
$filesystem->copy('hello.txt', 'copies/hello.txt');
$filesystem->move('copies/hello.txt', 'moved/hello.txt');
$filesystem->delete('moved/hello.txt');
$filesystem->createDirectory('empty-folder');
$filesystem->deleteDirectory('empty-folder');
```

### Metadata & listing

```php
$filesystem->fileExists('hello.txt');
$filesystem->directoryExists('nested');
$filesystem->fileSize('hello.txt');
$filesystem->mimeType('data.json');
$filesystem->lastModified('hello.txt');
$filesystem->checksum('hello.txt');                                     // md5, computed from the stream
$filesystem->checksum('hello.txt', ['checksum_algo' => 'sha256']);      // Bunny's stored checksum, no download

$files = $filesystem->listContents('', deep: true)
    ->filter(fn ($item) => $item->isFile())
    ->map(fn ($item) => $item->path())
    ->toArray();
```

### URLs

```php
$filesystem->publicUrl('hello.txt');   // https://my-zone.b-cdn.net/uploads/hello.txt

$filesystem->temporaryUrl('hello.txt', new DateTimeImmutable('+1 hour'));   // signed with the token auth key
$filesystem->temporaryUrl('hello.txt', new DateTimeImmutable('+10 minutes'), [
    'withQueryParams' => ['download' => 'hello.txt'],   // extra params are signed too
]);
```

### Batch uploads

```php
use League\Flysystem\Config;
use Siberfx\BunnyCdn\Flysystem\WriteBatchFile;

$adapter->writeBatch([
    new WriteBatchFile('/local/a.jpg', 'images/a.jpg'),
    new WriteBatchFile('/local/b.jpg', 'images/b.jpg'),
], new Config(['concurrency' => 20]));
```

### Errors & mounting

Failures are regular Flysystem exceptions (`UnableToReadFile`, `UnableToWriteFile`, ...). Combine with other
filesystems through the `MountManager`:

```php
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\MountManager;

$mounts = new MountManager([
    'local' => new Filesystem(new LocalFilesystemAdapter('/var/www/uploads')),
    'bunny' => $filesystem,
]);
$mounts->copy('local://avatar.jpg', 'bunny://avatars/avatar.jpg');
```

---

## 10. Custom HTTP clients

📄 [`native-usage-docs/10-custom-http-client.php`](native-usage-docs/10-custom-http-client.php)

```bash
BUNNY_API_KEY=... php native-usage-docs/10-custom-http-client.php
```

Every `BunnyCore` client sends its requests through `Siberfx\BunnyCdn\BunnyCore\Http\HttpClient`:

```php
interface HttpClient
{
    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse;
}
```

### Logging and retries (decorator)

```php
use Siberfx\BunnyCdn\BunnyCore\Http\CurlHttpClient;
use Siberfx\BunnyCdn\BunnyCore\Http\HttpClient;
use Siberfx\BunnyCdn\BunnyCore\Http\HttpResponse;

final readonly class RetryingLoggingClient implements HttpClient
{
    public function __construct(
        private HttpClient $inner = new CurlHttpClient(),
        private int $retries = 3,
    ) {}

    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->inner->send($method, $url, $headers, $body);
            error_log("$method $url -> {$response->status}");

            if (($response->status !== 429 && $response->status < 500) || $attempt > $this->retries || is_resource($body)) {
                return $response;
            }
            usleep(200_000 * 2 ** $attempt);   // exponential backoff
        }
    }
}

$pull = new BunnyAPIPull('account-api-key', http: new RetryingLoggingClient());
// or later: $pull->setHttpClient(new RetryingLoggingClient());
```

### A fake client for your own tests

```php
final class FakeBunny implements HttpClient
{
    public array $sent = [];

    public function __construct(private readonly array $routes) {}

    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse
    {
        $this->sent[] = [$method, $url];
        $path = parse_url($url, PHP_URL_PATH);

        return isset($this->routes["$method $path"])
            ? new HttpResponse(200, json_encode($this->routes["$method $path"]))
            : new HttpResponse(404, '{"Message":"Not found"}');
    }
}

$fake = new FakeBunny([
    'GET /pullzone/42' => ['Id' => 42, 'Hostnames' => [['Id' => 1, 'Value' => 'fake.b-cdn.net', 'ForceSSL' => true]]],
]);
$pull = new BunnyAPIPull('any-key', http: $fake);

$pull->pullZoneHostnames(42);   // served by the fake, no network
$fake->sent;                    // [['GET', 'https://api.bunny.net/pullzone/42']]
```

---

## Which key goes where

| Key | Where to find it | Used for |
|-----|------------------|----------|
| Account API key | Dashboard → Account settings → API | `BunnyAPI`, `BunnyAPIPull`, `BunnyAPIDNS`, storage **zone management**, pull zone logs |
| Storage zone password | Storage → zone → FTP & API access | Edge Storage HTTP (`setStorageZone`), FTP (`zoneConnect`), `BunnyCDNClient` |
| Stream library key | Stream → library → API | `BunnyAPIStream` |
| Token authentication key | CDN → pull zone → Security → Token authentication | Signed URLs (`setTokenAuthKey`) |
