<?php

declare(strict_types=1);

/*
 * DNS: zones, every record type, DNSSEC, export and statistics.
 *
 *   BUNNY_API_KEY=... [BUNNY_DNS_ZONE_ID=123] [BUNNY_EXAMPLES_WRITE=1] php native-usage-docs/08-dns.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIDNS;
use Siberfx\BunnyCdn\BunnyCore\DnsRecordType;

$dns = new BunnyAPIDNS(envVar('BUNNY_API_KEY'));

/*
 * Zones
 */
$zones = $dns->getDNSZones(per_page: 100)['Items'] ?? [];
show('DNS zones', array_column($zones, 'Domain', 'Id'));

$zoneId = (int) envVar('BUNNY_DNS_ZONE_ID', (string) ($zones[0]['Id'] ?? 0));

if ($zoneId > 0) {
    $zone = $dns->getDNSZone($zoneId);
    show("Zone {$zone['Domain']}", [
        'nameservers' => [$zone['Nameserver1'], $zone['Nameserver2']],
        'records' => count($zone['Records']),
        'dnssec' => $zone['DnsSecEnabled'] ?? null,
    ]);

    // Records of one type, with the enum
    show('TXT records', array_column($dns->listDNSRecords($zoneId, type: DnsRecordType::TXT)['Items'] ?? [], 'Value', 'Name'));

    // Record type names for display
    show('Records', array_map(
        fn (array $record): string => sprintf('%-6s %s -> %s', DnsRecordType::from($record['Type'])->name, $record['Name'] ?: '@', $record['Value']),
        $zone['Records'],
    ));

    show('Queries last 7 days', $dns->getDNSZoneStatistics($zoneId, new DateTimeImmutable('-7 days')->format('Y-m-d')));
    show('BIND export', $dns->exportDNSZone($zoneId));
}

show('Is example-docs.com available?', $dns->checkDNSZoneAvailability('example-docs.com'));

if (! writes() || $zoneId === 0) {
    exit(PHP_EOL.'Set BUNNY_EXAMPLES_WRITE=1 and BUNNY_DNS_ZONE_ID to add and change records.'.PHP_EOL);
}

/*
 * Records: one helper per common type
 */
$dns->addDNSRecordA($zoneId, 'docs', '203.0.113.10', ttl: 300);
$dns->addDNSRecordAAAA($zoneId, 'docs', '2001:db8::10');
$dns->addDNSRecordCNAME($zoneId, 'docs-cdn', 'docs.b-cdn.net');
$dns->addDNSRecordTXT($zoneId, 'docs', 'v=spf1 include:_spf.example.com -all');
$dns->addDNSRecordMX($zoneId, 'docs', 'mail.example.com', priority: 10);
$dns->addDNSRecordSRV($zoneId, '_sip._tcp.docs', 'sip.example.com', port: 5060, priority: 10);
$dns->addDNSRecordCAA($zoneId, 'docs', 'issue', 'letsencrypt.org');
$dns->addDNSRecordNS($zoneId, 'delegated.docs', 'ns1.example.net');
$dns->addDNSRecordRedirect($zoneId, 'old-docs', 'https://example.com/docs');
// $dns->addDNSRecordFlatten($zoneId, '', 'docs.b-cdn.net');          // CNAME flattening on the apex
// $dns->addDNSRecordPullZone($zoneId, 'assets', pullzone_id: 12345);  // link a pull zone
// $dns->addDNSRecordScript($zoneId, 'api', script_id: 678);           // edge script

// Any other type or field via the generic method (DnsRecordType covers SVCB, HTTPS, TLSA, PTR, ...)
$dns->addDNSRecord($zoneId, 'geo', '203.0.113.20', [
    'Type' => DnsRecordType::A,
    'Ttl' => 60,
    'Weight' => 50,
    'Accelerated' => true,
    'Comment' => 'Added by the docs example',
]);

/*
 * Updating, toggling and deleting
 */
$records = $dns->getDNSZone($zoneId)['Records'];
$aRecord = array_find($records, fn (array $r): bool => $r['Name'] === 'docs' && $r['Type'] === DnsRecordType::A->value);

if ($aRecord !== null) {
    $dns->updateDNSRecordA($zoneId, $aRecord['Id'], 'docs', '203.0.113.11');
    $dns->updateDNSRecord($zoneId, $aRecord['Id'], ['Ttl' => 3600, 'Comment' => 'updated']);
    $dns->disableDNSRecord($zoneId, $aRecord['Id']);
    $dns->enableDNSRecord($zoneId, $aRecord['Id']);
}

foreach ($dns->getDNSZone($zoneId)['Records'] as $record) {
    if (str_contains($record['Name'], 'docs') || $record['Name'] === 'geo') {
        $dns->deleteDNSRecord($zoneId, $record['Id']);
    }
}

/*
 * Zone settings
 */
$dns->updateDNSZoneSoaEmail($zoneId, 'hostmaster@example.com');
$dns->updateDNSZoneLogging($zoneId, enable_logging: true, log_anon_type: 0, use_log_anon: true);
// $dns->updateDNSZoneNameservers($zoneId, true, 'ns1.example.com', 'ns2.example.com');
// $dns->enableDNSSEC($zoneId);   // publish the returned DS record at your registrar
// $dns->disableDNSSEC($zoneId);
$dns->recheckDNSRecord($zoneId);

/*
 * Creating and deleting a whole zone
 */
// $new = $dns->addDNSZone('example-docs.com', logging: true);
// $dns->deleteDNSZone($new['Id']);
show('Done');
