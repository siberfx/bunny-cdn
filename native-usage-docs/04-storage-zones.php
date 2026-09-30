<?php

declare(strict_types=1);

/*
 * Storage zone management (account API key): list, create, update, statistics, passwords, delete.
 *
 *   BUNNY_API_KEY=... [BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/04-storage-zones.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;

$storage = new BunnyAPIStorage(envVar('BUNNY_API_KEY'));

/*
 * Listing
 */
$zones = $storage->listStorageZones();
show('Storage zones', array_map(fn (array $zone): array => [
    'id' => $zone['Id'],
    'name' => $zone['Name'],
    'region' => $zone['Region'],
    'replicas' => $zone['ReplicationRegions'] ?? [],
    'size' => $storage->convertBytes((int) $zone['StorageUsed']).' GB',
], $zones));

show('Search', $storage->listStorageZones(page: 1, per_page: 5, search: 'backup'));
show('Available regions', array_column($storage->getStorageRegions(), 'RegionCode'));
show('Is "docs-example-zone" available?', $storage->checkStorageZoneAvailability('docs-example-zone'));

if ($zones !== []) {
    $first = $zones[0];
    show("Zone {$first['Name']}", $storage->getStorageZone($first['Id']));
    show('Statistics last 30 days', $storage->getStorageZoneStatistics(
        $first['Id'],
        new DateTimeImmutable('-30 days')->format('Y-m-d'),
        new DateTimeImmutable()->format('Y-m-d'),
    ));
}

if (! writes()) {
    exit(PHP_EOL.'Set BUNNY_EXAMPLES_WRITE=1 to create, update and delete a storage zone.'.PHP_EOL);
}

/*
 * Create: primary region Falkenstein, replicated to New York and Singapore
 */
$zone = $storage->addStorageZone(
    'docs-example-zone',
    main_region: 'DE',
    replicated_regions: ['NY', 'SG'],
    zone_tier: BunnyAPIStorage::ZONE_TIER_STANDARD,     // or ZONE_TIER_EDGE (SSD)
);
show('Created', ['id' => $zone['Id'], 'password' => $zone['Password']]);

/*
 * Update: origin fallback and custom 404 page
 */
$storage->updateStorageZone($zone['Id'], [
    'OriginUrl' => 'https://origin.example.com',
    'Custom404FilePath' => '/404.html',
    'Rewrite404To200' => false,
]);

/*
 * Rotate passwords
 */
$storage->resetStorageZonePassword($zone['Id']);
$storage->resetStorageZoneReadOnlyPassword($zone['Id']);

/*
 * Delete. Linked pull zones are kept unless you ask for them to be deleted too.
 */
$storage->deleteStorageZone($zone['Id']);
// $storage->deleteStorageZone($zone['Id'], delete_linked_pull_zones: true);
show('Deleted');
