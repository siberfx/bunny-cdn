<?php

declare(strict_types=1);

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIDNS;
use Siberfx\BunnyCdn\BunnyCore\DnsRecordType;

beforeEach(function () {
    $this->http = fakeHttp();
    $this->bunny = new BunnyAPIDNS('api-key', http: $this->http);
});

test('lists zones with query params', function () {
    $this->bunny->getDNSZones(2, 50, 'example');

    expect($this->http->last()['url'])->toBe('https://api.bunny.net/dnszone?page=2&perPage=50&search=example');
});

test('statistics dates', function () {
    $this->bunny->getDNSZoneStatistics(1, date_to: '2026-09-30');

    expect($this->http->last()['url'])->toBe('https://api.bunny.net/dnszone/1/statistics?dateTo=2026-09-30');
});

test('adding a zone with logging updates the zone afterwards', function () {
    $this->http->push(201, ['Id' => 9, 'Domain' => 'example.com'])->push(200, ['Id' => 9, 'LoggingEnabled' => true]);

    $zone = $this->bunny->addDNSZone('example.com', true);

    expect(json_decode($this->http->requests[0]['body'], true))->toBe(['Domain' => 'example.com'])
        ->and($this->http->last()['url'])->toBe('https://api.bunny.net/dnszone/9')
        ->and($this->http->lastJson())->toBe(['LoggingEnabled' => true, 'LoggingIPAnonymizationEnabled' => true])
        ->and($zone['LoggingEnabled'])->toBeTrue();
});

test('typed record helpers', function () {
    $this->bunny->addDNSRecordMX(1, 'host', 'mail.test', 10);
    expect($this->http->last()['method'])->toBe('PUT')
        ->and($this->http->lastJson())->toBe(['Type' => 4, 'Value' => 'mail.test', 'Priority' => 10, 'Name' => 'host', 'Ttl' => 300, 'Weight' => 100]);

    $this->bunny->addDNSRecordSRV(1, '_sip._tcp', 'sip.test', 5060);
    expect($this->http->lastJson())->toBe(['Type' => 8, 'Value' => 'sip.test', 'Port' => 5060, 'Priority' => 10, 'Name' => '_sip._tcp', 'Ttl' => 300, 'Weight' => 100]);

    $this->bunny->addDNSRecordCAA(1, '', 'issue', 'letsencrypt.org');
    expect($this->http->lastJson()['Type'])->toBe(9);

    $this->bunny->addDNSRecord(1, 'h', 'v', ['Type' => DnsRecordType::HTTPS]);
    expect($this->http->lastJson()['Type'])->toBe(14);
});

test('updates and toggles records', function () {
    $this->bunny->updateDNSRecordA(1, 7, 'host', '1.2.3.4');
    expect($this->http->last())->method->toBe('POST')->url->toBe('https://api.bunny.net/dnszone/1/records/7')
        ->and($this->http->lastJson())->toBe(['Id' => 7, 'Type' => 0, 'Value' => '1.2.3.4', 'Name' => 'host']);

    $this->bunny->disableDNSRecord(1, 7);
    expect($this->http->lastJson())->toBe(['Id' => 7, 'Disabled' => true]);
});

test('dnssec, export and record listing', function () {
    $this->bunny->enableDNSSEC(1);
    expect($this->http->last())->method->toBe('POST')->url->toBe('https://api.bunny.net/dnszone/1/dnssec');

    $this->bunny->disableDNSSEC(1);
    expect($this->http->last()['method'])->toBe('DELETE');

    $this->http->push(200, "\$ORIGIN example.com.\n");
    expect($this->bunny->exportDNSZone(1))->toBe("\$ORIGIN example.com.\n");

    $this->bunny->listDNSRecords(1, type: DnsRecordType::TXT);
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/dnszone/1/records?page=1&perPage=1000&type=3');

    $this->bunny->checkDNSZoneAvailability('example.com');
    expect($this->http->lastJson())->toBe(['Name' => 'example.com']);
});

test('record type enum values match the api', function () {
    expect(array_map(fn (DnsRecordType $type) => $type->value, DnsRecordType::cases()))->toBe(range(0, 15));
});
