<?php

declare(strict_types=1);

/*
 * Account: statistics, billing, purging, reference data and abuse cases.
 *
 *   BUNNY_API_KEY=... php native-usage-docs/02-account-and-billing.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPI;

$bunny = new BunnyAPI(envVar('BUNNY_API_KEY'));

/*
 * Statistics
 */
$lastWeek = new DateTimeImmutable('-7 days')->format('Y-m-d');
$today = new DateTimeImmutable()->format('Y-m-d');

$stats = $bunny->getStatistics(date_from: $lastWeek, date_to: $today);
show('Traffic last 7 days', [
    'bandwidth' => $bunny->convertBytes((int) ($stats['TotalBandwidthUsed'] ?? 0)).' GB',
    'requests' => $stats['TotalRequestsServed'] ?? null,
    'cache hit rate' => $stats['CacheHitRate'] ?? null,
]);

// Hourly statistics for one pull zone, with extra chart data
$pullZoneId = (int) envVar('BUNNY_PULL_ZONE_ID', '0');
if ($pullZoneId > 0) {
    $hourly = $bunny->getStatistics(
        pullzone_id: $pullZoneId,
        hourly: true,
        date_from: $lastWeek,
        date_to: $today,
        options: ['loadErrors' => true, 'loadOriginTraffic' => true],
    );
    show('Pull zone hourly statistics keys', array_keys($hourly));
}

/*
 * Billing
 */
show('Balance', $bunny->balance());
show('This month', $bunny->monthCharges());
show('This month per region', $bunny->monthChargeBreakdown());
show('Total of billing records', $bunny->totalBillingAmount(format: true));
show('Billing summary (first entry)', $bunny->getBillingSummary()[0] ?? []);
show('Affiliate', $bunny->getAffiliate());

/*
 * Reference data
 */
show('Countries', count($bunny->getCountries()));
show('Regions', array_column($bunny->getRegions(), 'Name'));

/*
 * Abuse cases
 */
$cases = $bunny->getAbuseCases();
show('Abuse cases', $cases['Items'] ?? $cases);

/*
 * Purging (changes state)
 */
if (writes()) {
    // Purge a single URL, wait for completion
    $bunny->purgeCache('https://'.envVar('BUNNY_CDN_HOSTNAME', 'example.b-cdn.net').'/css/app.css');

    // Purge everything under a path asynchronously (wildcard), or exactly one path including the query string
    $bunny->purgeCache('https://'.envVar('BUNNY_CDN_HOSTNAME', 'example.b-cdn.net').'/images/*', async: true);
    $bunny->purgeCache('https://'.envVar('BUNNY_CDN_HOSTNAME', 'example.b-cdn.net').'/feed.xml?page=2', exact_path: true);

    show('Purged', 'ok');

    // $bunny->claimAffiliate();   // moves the affiliate balance to your account balance
}
