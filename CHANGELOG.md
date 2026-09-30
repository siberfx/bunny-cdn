# Changelog

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [2.2.0] - 2026-09-30

### Changed
- **Breaking:** the API client classes moved to `src/BunnyCore` and the `Siberfx\BunnyCdn\BunnyCore` namespace. That covers `BunnyAPI`,
  `BunnyAPIPull`, `BunnyAPIStorage`, `BunnyAPIStream`, `BunnyAPIDNS`, `BunnyAPIException`, `DnsRecordType` and `Http\*`. Only
  `BunnyCdnServiceProvider` stays in `Siberfx\BunnyCdn`. The Flysystem adapter (`Siberfx\BunnyCdn\Flysystem`) is unchanged.
- Tests mirror the source layout (`tests/BunnyCore`, `tests/Flysystem`).

### Upgrading
Replace `Siberfx\BunnyCdn\BunnyAPI…`, `Siberfx\BunnyCdn\DnsRecordType` and `Siberfx\BunnyCdn\Http\…` imports with
`Siberfx\BunnyCdn\BunnyCore\…`. Laravel users who resolve the clients from the container or the `bunnycdn` disk need no changes, apart
from updating the imports in their own code.

## [2.1.1] - 2026-09-30

### Added
- GitHub Actions workflow (`.github/workflows/tests.yml`). It runs Pest on PHP 8.4 and 8.5 with both lowest and stable dependencies,
  and on PHP 8.6 (release candidate, allowed to fail). It also runs PHPStan.

### Changed
- The test suite is now written in [Pest](https://pestphp.com) 3 (`composer test`). League's Flysystem adapter conformance suite is a
  PHPUnit test case that Pest cannot inherit, so it still runs through three small classes: `AdapterConformanceTest`,
  `PrefixConformanceTest` and `RootConformanceTest`.
- Raised minimum dependency versions to the first releases that support PHP 8.4: `guzzlehttp/guzzle ^7.9`, `league/flysystem ^3.29`,
  `league/mime-type-detection ^1.16`. Dev tools were raised as well (Faker, the Flysystem test utilities, memory and path-prefixing
  adapters, phpdotenv), so the lowest-dependency CI run is clean.

## [2.1.0] - 2026-09-30

Brings the Flysystem adapter from [platformcommunity/flysystem-bunnycdn](https://github.com/PlatformCommunity/flysystem-bunnycdn)
(MIT) into this package so it can be maintained here.

### Added
- Flysystem v3 adapter under `Siberfx\BunnyCdn\Flysystem`: `BunnyCDNAdapter`, `BunnyCDNClient`, `BunnyCDNRegion`, `WriteBatchFile`,
  `Util`, `Exceptions\BunnyCDNException`, `Exceptions\NotFoundException`. It supports read/write (strings and streams), copy, move and
  delete, directories and deep listing, metadata, MD5/SHA256 checksums, public URLs, signed temporary URLs (token auth), a root path
  prefix, and concurrent `writeBatch()` uploads.
- Laravel `bunnycdn` filesystem driver, registered by `BunnyCdnServiceProvider` (no `Storage::extend()` needed). Missing disk options
  fall back to the `bunny-cdn.storage` config.
- Dependencies matching the upstream adapter: `guzzlehttp/guzzle ^7.4`, `league/flysystem ^3.16`, `league/mime-type-detection ^1.11`.
- Dev tooling: the Flysystem adapter conformance suite (`league/flysystem-adapter-test-utilities`), PHPStan (level 5), Pint,
  and a service provider test against `illuminate/*` 12/13.
- `LICENSE` file with attribution for the incorporated MIT code.

### Changed
- Dev dependency `phpunit/phpunit` is now `^11.5`. The Flysystem conformance suite still uses `@test` annotations, which PHPUnit 12
  ignores.
- `BunnyCDNClient` uses `BunnyCDNRegion::LOS_ANGELES` internally (`LOS_ANGELAS` is still available but deprecated).

### Migrating from `platformcommunity/flysystem-bunnycdn`
Replace `PlatformCommunity\Flysystem\BunnyCDN` with `Siberfx\BunnyCdn\Flysystem`. Class names and constructor arguments are unchanged.

## [2.0.0] - 2026-09-30

Modernised for PHP 8.4, 8.5 and 8.6 and aligned with the current bunny.net API (core, Edge Storage, Stream, Logging).

### Added
- PHP 8.4 / 8.5 / 8.6 support (`php: ^8.4`); strict types, typed class constants and full parameter/return types.
- Pluggable HTTP layer: `Http\HttpClient` interface, `Http\CurlHttpClient` (default) and `Http\HttpResponse`; `setHttpClient()` and `lastResponse()`.
- `BunnyAPIException::getStatusCode()` and `getResponseBody()`.
- Working Laravel service provider (auto-discovery), publishable `config/bunny-cdn.php` driven by `BUNNY_*` env vars.
- Account: `getBillingSummary()`, `getAbuseCase()`, statistics date range and extra `load*` options, `exactPath` for `purgeCache()`.
- Pull zones: `search` for `listPullZones()`, `checkPullZoneAvailability()`, purge by cache tag, `addCertificate()`, `removeCertificate()`,
  `addOrUpdateEdgeRule()`, `setEdgeRuleEnabled()`, `deleteEdgeRule()`, Logging API v2 `pullZoneLogsV2()`.
- Storage: region support for Edge Storage HTTP and FTP hosts (`de`, `uk`, `se`, `ny`, `la`, `sg`, `syd`, `br`, `jh`), `setStorageZone()`,
  SHA256 `Checksum` on uploads, `uploadContentHTTP()`, `listDirectory()`, `getStorageZone()`, `updateStorageZone()`,
  `getStorageZoneStatistics()`, `resetStorageZonePassword()`, `resetStorageZoneReadOnlyPassword()`, `checkStorageZoneAvailability()`,
  `getStorageRegions()`, `ZoneTier` on `addStorageZone()`.
- Stream: `updateVideo()`, `getVideoPlayData()`, `getVideoResolutions()`, `cleanupVideoResolutions()`, `transcribeVideo()`,
  `repackageVideo()`, search/collection filters, upload options, fetch title/headers/thumbnail time, statistics filters.
- DNS: `DnsRecordType` enum (incl. SVCB, HTTPS, TLSA), `search` for zones, `listDNSRecords()`, `updateDNSZone()`, `updateDNSRecord()`,
  `checkDNSZoneAvailability()`, `exportDNSZone()`, `enableDNSSEC()` / `disableDNSSEC()`, SRV, CAA and Flatten record helpers.
- PHPUnit test suite covering every endpoint with a fake HTTP client.

### Changed
- **Breaking:** API keys are passed to the constructor (`new BunnyAPIPull($apiKey, $streamLibraryKey)`) instead of class constants.
- **Breaking:** errors throw `BunnyAPIException` instead of being echoed; nothing calls `exit` anymore.
- **Breaking:** `downloadFileHTTP()` returns the file contents (string) instead of an array.
- **Breaking:** `deleteStorageZone()` no longer deletes linked pull zones unless `$delete_linked_pull_zones` is `true`.
- **Breaking:** `addStorageZone()` signature is now `(name, region, replication_regions, zone_tier, args)`; `OriginUrl` moved to `updateStorageZone()`.
- **Breaking:** `addDNSZone()` creates the zone first and then applies logging settings (the add endpoint only accepts `Domain`/`Records`).
- **Breaking:** `ext-ftp` is optional; FTP methods throw `BunnyAPIException` if `zoneConnect()` was not called.
- Storage listings, `dirSize()`, `deleteAllFiles()` and `downloadAll()` use the HTTP API with the `AccessKey` header (no key in the URL, no FTP needed).
- Stream uploads are streamed with `Content-Type: application/octet-stream`; `setThumbnail()` uses `thumbnailUrl`; `addCaptions()` sends a
  base64 JSON body and accepts a file path or raw captions.
- `removeHostnamePullZone()` / `removeCertificate()` use `DELETE` with a JSON body; query strings are properly URL encoded.
- `renameFile()` / `moveFile()` use a system temp file instead of `TEMPFILE.*` in the working directory.
- FTP progress methods use non-blocking transfers instead of `ftp://` stream wrappers with credentials in the URL.
- Setters return `static` for chaining.

### Fixed
- SSL peer verification was disabled; it is now enabled.
- Implicitly nullable parameter in `fetchVideo()` (deprecated in PHP 8.4).
- `curl_close()` calls (deprecated in PHP 8.5).
- Float passed to `date()` in `pullZoneLogs()`.
- `videoSize()` called the API with an invalid argument list.
- `pullZoneHostnames()` fetched the pull zone twice; `totalBillingAmount()` failed with no billing records.
- `zoneConnect()` ignored FTP login failures.
- Broken `BunnyCdnServiceProvider` (missing imports and methods).

### Removed
- `applyCoupon()` – the endpoint no longer exists in the bunny.net API.
- Support ticket methods (`getSupportTickets()`, `getSupportTicketDetails()`, `closeSupportTicket()`, `createSupportTicket()`) – not part of the public API.
- `debug_request` property – use `lastResponse()` or a custom `HttpClient`.
- Hard-coded `API_KEY` / `STREAM_LIBRARY_ACCESS_KEY` constants and `constApiKeySet()`.

### Upgrading from 1.x

```php
// 1.x: key edited into BunnyAPI::API_KEY
$bunny = new BunnyAPIPull();

// 2.0
$bunny = new BunnyAPIPull('api-key', 'stream-library-key');

// Storage over HTTP: select the zone (and region) first
$storage->setStorageZone('zone-name', 'zone-password', 'ny');   // or zoneConnect() for FTP

// Errors
try {
    $bunny->getPullZone(1);
} catch (\Siberfx\BunnyCdn\BunnyAPIException $e) {
    $e->getStatusCode();
}
```
