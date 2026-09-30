<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests;

use PHPUnit\Framework\TestCase;
use Siberfx\BunnyCdn\BunnyAPIDNS;
use Siberfx\BunnyCdn\DnsRecordType;

final class DnsTest extends TestCase
{
    private FakeHttpClient $http;
    private BunnyAPIDNS $bunny;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->bunny = new BunnyAPIDNS('api-key', http: $this->http);
    }

    public function testListZonesUsesQueryParams(): void
    {
        $this->bunny->getDNSZones(2, 50, 'example');
        self::assertSame('https://api.bunny.net/dnszone?page=2&perPage=50&search=example', $this->http->last()['url']);
    }

    public function testStatisticsDates(): void
    {
        $this->bunny->getDNSZoneStatistics(1, date_to: '2026-09-30');
        self::assertSame('https://api.bunny.net/dnszone/1/statistics?dateTo=2026-09-30', $this->http->last()['url']);
    }

    public function testAddZoneWithLoggingUpdatesZone(): void
    {
        $this->http->push(201, ['Id' => 9, 'Domain' => 'example.com'])->push(200, ['Id' => 9, 'LoggingEnabled' => true]);
        $zone = $this->bunny->addDNSZone('example.com', true);
        self::assertSame(['Domain' => 'example.com'], json_decode($this->http->requests[0]['body'], true));
        self::assertSame('https://api.bunny.net/dnszone/9', $this->http->last()['url']);
        self::assertSame(['LoggingEnabled' => true, 'LoggingIPAnonymizationEnabled' => true], $this->http->lastJson());
        self::assertTrue($zone['LoggingEnabled']);
    }

    public function testTypedRecords(): void
    {
        $this->bunny->addDNSRecordMX(1, 'host', 'mail.test', 10);
        self::assertSame('PUT', $this->http->last()['method']);
        self::assertSame(['Type' => 4, 'Value' => 'mail.test', 'Priority' => 10, 'Name' => 'host', 'Ttl' => 300, 'Weight' => 100], $this->http->lastJson());

        $this->bunny->addDNSRecordSRV(1, '_sip._tcp', 'sip.test', 5060);
        self::assertSame(['Type' => 8, 'Value' => 'sip.test', 'Port' => 5060, 'Priority' => 10, 'Name' => '_sip._tcp', 'Ttl' => 300, 'Weight' => 100], $this->http->lastJson());

        $this->bunny->addDNSRecordCAA(1, '', 'issue', 'letsencrypt.org');
        self::assertSame(9, $this->http->lastJson()['Type']);

        $this->bunny->addDNSRecord(1, 'h', 'v', ['Type' => DnsRecordType::HTTPS]);
        self::assertSame(14, $this->http->lastJson()['Type']);
    }

    public function testUpdateAndToggleRecord(): void
    {
        $this->bunny->updateDNSRecordA(1, 7, 'host', '1.2.3.4');
        self::assertSame(['POST', 'https://api.bunny.net/dnszone/1/records/7'], [$this->http->last()['method'], $this->http->last()['url']]);
        self::assertSame(['Id' => 7, 'Type' => 0, 'Value' => '1.2.3.4', 'Name' => 'host'], $this->http->lastJson());

        $this->bunny->disableDNSRecord(1, 7);
        self::assertSame(['Id' => 7, 'Disabled' => true], $this->http->lastJson());
    }

    public function testDnssecExportAndRecords(): void
    {
        $this->bunny->enableDNSSEC(1);
        self::assertSame(['POST', 'https://api.bunny.net/dnszone/1/dnssec'], [$this->http->last()['method'], $this->http->last()['url']]);
        $this->bunny->disableDNSSEC(1);
        self::assertSame('DELETE', $this->http->last()['method']);

        $this->http->push(200, "\$ORIGIN example.com.\n");
        self::assertSame("\$ORIGIN example.com.\n", $this->bunny->exportDNSZone(1));

        $this->bunny->listDNSRecords(1, type: DnsRecordType::TXT);
        self::assertSame('https://api.bunny.net/dnszone/1/records?page=1&perPage=1000&type=3', $this->http->last()['url']);

        $this->bunny->checkDNSZoneAvailability('example.com');
        self::assertSame(['Name' => 'example.com'], $this->http->lastJson());
    }
}
