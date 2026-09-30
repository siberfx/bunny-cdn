# Changelog

All notable changes to `siberfx/bunny-cdn` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-09-30

### Added
- `NATIVE_USAGE.md`: a detailed plain-PHP guide to every feature. Each section links to the matching runnable example.
- `native-usage-docs/`: ten runnable, framework-free examples covering the account API, pull zones, storage zones,
  Edge Storage (HTTP and FTP), Stream, DNS, the Flysystem adapter and custom HTTP clients. They are read-only unless
  `BUNNY_EXAMPLES_WRITE=1` is set. PHPStan checks them in CI so they stay in sync with the API.
- GitHub Actions workflow. It runs Pest on PHP 8.4 and 8.5 with both lowest and stable dependencies, and on PHP 8.6
  (allowed to fail while it is a release candidate). It also runs PHPStan.
- `.gitattributes` so Composer installs only ship `src/` and the documentation files.
- The Flysystem tests can run against a real storage zone through the `BUNNY_TEST_STORAGE_*` environment variables.

### Changed
- The API client lives in `src/BunnyCore` under the `Siberfx\BunnyCdn\BunnyCore` namespace; only the Laravel service
  provider stays at the package root.
- The test suite is written in Pest 3. League's Flysystem adapter conformance suite is a PHPUnit test case, so it runs
  through `AdapterConformanceTest`, `PrefixConformanceTest` and `RootConformanceTest`.
- The Flysystem adapter code uses PHP 8.4 syntax throughout:
  - strict types, typed signatures and typed class constants;
  - `final readonly` value objects, `#[\Override]` and `#[\Deprecated]`;
  - `new` without parentheses, `str_starts_with` / `str_ends_with`, and `array_find`.
- `BunnyCDNClient` gets its region hostnames from `BunnyAPIStorage::REGION_HOSTNAMES`, so the list is defined in one place.
- Minimum dependency versions are the first releases that support PHP 8.4: `guzzlehttp/guzzle ^7.9`,
  `league/flysystem ^3.29` and `league/mime-type-detection ^1.16`.

### Deprecated
- `BunnyCDNRegion::LOS_ANGELAS`. Use `BunnyCDNRegion::LOS_ANGELES`.

### Removed
- `example.php` and `dns_example.php`, replaced by `native-usage-docs/`.
- The `fakerphp/faker` dev dependency.
- The global-variable `tests/Flysystem/ClientDI.php` hook for live tests, replaced by environment variables.

## [1.0.0] - 2026-09-30

First release of `siberfx/bunny-cdn`.

### Added
- bunny.net API client for PHP 8.4, 8.5 and 8.6: account, statistics, billing, pull zones, storage zones, Edge Storage
  (HTTP with regional hosts and SHA256 checksums, plus FTP), Stream and DNS. Endpoints match the current bunny.net API,
  including edge rules, certificates, the v2 logging API, DNSSEC, zone export, and Stream transcription and repackaging.
- `DnsRecordType` enum covering every DNS record type, including SVCB, HTTPS and TLSA.
- A swappable HTTP layer (`Http\HttpClient`, `CurlHttpClient`, `HttpResponse`) and `BunnyAPIException`, which carries the
  HTTP status and response body.
- Flysystem v3 adapter (`Siberfx\BunnyCdn\Flysystem`). It supports:
  - streams, copy and move, directories and deep listing;
  - MD5/SHA256 checksums and public URLs;
  - signed temporary URLs, a root path prefix, and concurrent batch uploads.
- Laravel integration with an auto-discovered service provider, a publishable `config/bunny-cdn.php`, clients registered as
  singletons, and a `bunnycdn` filesystem driver.

## Migrating

### From `corbpie/bunny-cdn-api` (1.9.x)
- Require `siberfx/bunny-cdn` and import the clients from `Siberfx\BunnyCdn\BunnyCore` (`BunnyAPIPull`,
  `BunnyAPIStorage`, `BunnyAPIStream`, `BunnyAPIDNS`, ...).
- Pass keys to the constructor (`new BunnyAPIPull($apiKey, $streamLibraryKey)`) instead of editing class constants.
- Errors are thrown as `BunnyAPIException` (`getStatusCode()`, `getResponseBody()`) instead of being echoed.
- Storage over HTTP: call `setStorageZone($zone, $password, $region)` first; use `zoneConnect()` only for FTP (which
  needs `ext-ftp`). `downloadFileHTTP()` returns the file contents.
- `deleteStorageZone()` keeps linked pull zones unless `$delete_linked_pull_zones` is `true`.
- Removed endpoints: `applyCoupon()` (no longer in the API) and the support ticket methods.

### From `platformcommunity/flysystem-bunnycdn`
- Replace the namespace `PlatformCommunity\Flysystem\BunnyCDN` with `Siberfx\BunnyCdn\Flysystem`. Class names and
  constructor arguments are unchanged.
- In Laravel, remove your `Storage::extend('bunnycdn', ...)` code; the service provider registers the driver.

[Unreleased]: https://github.com/siberfx/bunny-cdn/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/siberfx/bunny-cdn/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/siberfx/bunny-cdn/releases/tag/v1.0.0
