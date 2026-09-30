<?php

declare(strict_types=1);

/*
 * Pull zones: CRUD, hostnames & SSL, security, edge rules, purging and logs.
 *
 *   BUNNY_API_KEY=... [BUNNY_PULL_ZONE_ID=123] [BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/03-pull-zones.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIPull;

$pull = new BunnyAPIPull(envVar('BUNNY_API_KEY'));

/*
 * Listing & reading
 */
$zones = $pull->listPullZones();                         // all zones (plain array)
$page = $pull->listPullZones(page: 1, per_page: 10, search: 'assets'); // paged: ['Items' => [...], 'HasMoreItems' => ...]

show('Pull zones', array_map(
    fn (array $zone): array => ['id' => $zone['Id'], 'name' => $zone['Name'], 'origin' => $zone['OriginUrl']],
    $zones,
));

$zoneId = (int) envVar('BUNNY_PULL_ZONE_ID', (string) ($zones[0]['Id'] ?? 0));

if ($zoneId > 0) {
    $zone = $pull->getPullZone($zoneId, include_cert: false);
    show("Zone $zoneId", ['name' => $zone['Name'], 'cache expiry' => $zone['CacheControlMaxAgeOverride'] ?? null]);
    show('Hostnames', $pull->pullZoneHostnames($zoneId));
    show('Blocked IPs', $pull->listBlockedIpPullZone($zoneId));
    show('Edge rules', array_column($zone['EdgeRules'] ?? [], 'Description', 'Guid'));

    // Access logs of yesterday (legacy v1 log, parsed into arrays)
    $logs = $pull->pullZoneLogs($zoneId, new DateTimeImmutable('yesterday'));
    show('Log lines yesterday', count($logs));

    // Logging API v2: JSON, filtered, max 3 days
    $errors = $pull->pullZoneLogsV2(
        $zoneId,
        from: new DateTimeImmutable('-1 day'),
        to: new DateTimeImmutable(),
        filters: ['status' => '4xx,5xx', 'limit' => 20],
    );
    show('Recent 4xx/5xx', $errors);
}

show('Is "my-new-zone" available?', $pull->checkPullZoneAvailability('my-new-zone'));

if (! writes() || $zoneId === 0) {
    exit(PHP_EOL.'Set BUNNY_EXAMPLES_WRITE=1 (and BUNNY_PULL_ZONE_ID) to run the examples that change pull zones.'.PHP_EOL);
}

/*
 * Creating & updating (any field from https://docs.bunny.net/reference/pullzonepublic_add)
 */
$created = $pull->createPullZone('docs-example-zone', 'https://origin.example.com', [
    'Type' => 0,                    // 0 = premium, 1 = volume
    'EnableGeoZoneUS' => true,
    'EnableGeoZoneASIA' => false,
]);
show('Created', ['id' => $created['Id'], 'hostname' => $created['Hostnames'][0]['Value'] ?? null]);

$pull->updatePullZone($created['Id'], [
    'CacheControlMaxAgeOverride' => 86_400,
    'EnableQueryStringOrdering' => true,
    'OriginShieldZoneCode' => 'FR',
]);

/*
 * Hostnames & SSL
 */
$pull->addHostnamePullZone($zoneId, 'cdn.example.com');
$pull->addFreeSSLCertificate('cdn.example.com');           // Let's Encrypt
$pull->forceSSLPullZone($zoneId, 'cdn.example.com', true);

// Or bring your own certificate (PEM strings, encoded for you)
// $pull->addCertificate($zoneId, 'cdn.example.com', file_get_contents('cert.pem'), file_get_contents('key.pem'));
// $pull->removeCertificate($zoneId, 'cdn.example.com');

/*
 * Security
 */
$pull->addBlockedIpPullZone($zoneId, '203.0.113.7');
$pull->unBlockedIpPullZone($zoneId, '203.0.113.7');
$pull->addAllowedReferrer($zoneId, 'example.com');
$pull->addBlockedReferrer($zoneId, 'spam.example');
$pull->removeBlockedReferrer($zoneId, 'spam.example');
// $pull->resetTokenKey($zoneId);                           // rotates the token authentication key

/*
 * Edge rules: redirect http://old.example.com/* to https://example.com
 */
$pull->addOrUpdateEdgeRule($zoneId, [
    'ActionType' => 1,                                      // 1 = redirect
    'ActionParameter1' => 'https://example.com{{path}}',
    'ActionParameter2' => '301',
    'TriggerMatchingType' => 0,                             // 0 = match any
    'Triggers' => [[
        'Type' => 0,                                        // 0 = URL
        'PatternMatches' => ['*://old.example.com/*'],
        'PatternMatchingType' => 0,
    ]],
    'Description' => 'Redirect old domain',
    'Enabled' => true,
]);

$rule = array_find(
    $pull->getPullZone($zoneId)['EdgeRules'] ?? [],
    fn (array $rule): bool => $rule['Description'] === 'Redirect old domain',
);
if ($rule !== null) {
    $pull->setEdgeRuleEnabled($zoneId, $rule['Guid'], false);
    $pull->deleteEdgeRule($zoneId, $rule['Guid']);
}

/*
 * Purging
 */
$pull->purgePullZone($zoneId);                               // everything
$pull->purgePullZone($zoneId, cache_tag: 'product-42');      // only responses tagged "product-42"

/*
 * Cleanup
 */
$pull->removeHostnamePullZone($zoneId, 'cdn.example.com');
$pull->deletePullZone($created['Id']);
show('Done');
