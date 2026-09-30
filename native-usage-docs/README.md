# Native usage docs

Runnable, framework-free examples for every part of the package. Laravel usage is covered in the main
[README](../README.md#laravel).

| Example | Covers |
|---------|--------|
| [01-getting-started.php](01-getting-started.php) | Creating clients, keys, responses, errors, timeouts, helpers |
| [02-account-and-billing.php](02-account-and-billing.php) | Statistics, billing, purging URLs, countries/regions, abuse cases |
| [03-pull-zones.php](03-pull-zones.php) | Pull zone CRUD, hostnames & SSL, security, edge rules, purge, logs (v1 + v2) |
| [04-storage-zones.php](04-storage-zones.php) | Storage zone management: create, replicate, update, statistics, passwords, delete |
| [05-edge-storage-http.php](05-edge-storage-http.php) | Edge Storage HTTP API: upload (checksums), download, list, delete, bulk operations |
| [06-edge-storage-ftp.php](06-edge-storage-ftp.php) | Edge Storage FTP: folders, progress uploads/downloads, rename, move |
| [07-stream.php](07-stream.php) | Stream: collections, uploads, fetch from URL, captions, thumbnails, transcription, statistics |
| [08-dns.php](08-dns.php) | DNS zones, every record type, `DnsRecordType`, DNSSEC, export, statistics |
| [09-flysystem.php](09-flysystem.php) | Flysystem adapter: read/write, metadata, listing, URLs, signed URLs, batch uploads, mount manager |
| [10-custom-http-client.php](10-custom-http-client.php) | Custom `HttpClient`: logging + retries decorator, fake client for your own tests |

## Running

Install the package dependencies (`composer install` in the package root), then pass your keys as environment variables:

```bash
BUNNY_API_KEY=your-account-key php native-usage-docs/02-account-and-billing.php
```

The examples are **read-only by default**. Anything that creates, changes, uploads or deletes something only runs with
`BUNNY_EXAMPLES_WRITE=1`, and cleans up after itself. Use a test zone when you enable it.

| Variable | Used by |
|----------|---------|
| `BUNNY_API_KEY` | Account API key: 01–04, 08, 10 (and 05 to look up a zone password) |
| `BUNNY_PULL_ZONE_ID`, `BUNNY_CDN_HOSTNAME` | 02, 03 |
| `BUNNY_STORAGE_ZONE`, `BUNNY_STORAGE_ACCESS_KEY`, `BUNNY_STORAGE_REGION` | 05, 06, 09 |
| `BUNNY_PULL_ZONE_URL`, `BUNNY_TOKEN_AUTH_KEY` | 09 (public and signed URLs) |
| `BUNNY_STREAM_LIBRARY_ID`, `BUNNY_STREAM_ACCESS_KEY` | 07 |
| `BUNNY_DNS_ZONE_ID` | 08 |
| `BUNNY_EXAMPLES_WRITE=1` | Enables the calls that change your account |
