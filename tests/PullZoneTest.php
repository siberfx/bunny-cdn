<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Siberfx\BunnyCdn\BunnyAPIPull;

final class PullZoneTest extends TestCase
{
    private FakeHttpClient $http;
    private BunnyAPIPull $bunny;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->bunny = new BunnyAPIPull('api-key', http: $this->http);
    }

    public function testListPullZones(): void
    {
        $this->bunny->listPullZones(search: 'my zone');
        self::assertSame('https://api.bunny.net/pullzone?page=0&perPage=1000&search=my%20zone&includeCertificate=false', $this->http->last()['url']);
    }

    public function testCreatePullZone(): void
    {
        $this->bunny->createPullZone('zone', 'https://origin.test', ['Type' => 1]);
        self::assertSame('POST', $this->http->last()['method']);
        self::assertSame(['Name' => 'zone', 'OriginUrl' => 'https://origin.test', 'Type' => 1], $this->http->lastJson());
        $this->bunny->createPullZone('zone', args: ['StorageZoneId' => 5]);
        self::assertSame(['Name' => 'zone', 'StorageZoneId' => 5], $this->http->lastJson());
    }

    public function testPurgeWithAndWithoutCacheTag(): void
    {
        $this->bunny->purgePullZone(1);
        self::assertNull($this->http->last()['body']);
        $this->bunny->purgePullZone(1, 'images');
        self::assertSame('https://api.bunny.net/pullzone/1/purgeCache', $this->http->last()['url']);
        self::assertSame(['CacheTag' => 'images'], $this->http->lastJson());
    }

    public function testHostnameAndCertificateEndpoints(): void
    {
        $this->bunny->removeHostnamePullZone(1, 'cdn.test');
        self::assertSame('DELETE', $this->http->last()['method']);
        self::assertSame(['Hostname' => 'cdn.test'], $this->http->lastJson());

        $this->bunny->addCertificate(1, 'cdn.test', 'CERT', 'KEY');
        self::assertSame('https://api.bunny.net/pullzone/1/addCertificate', $this->http->last()['url']);
        self::assertSame(['Hostname' => 'cdn.test', 'Certificate' => base64_encode('CERT'), 'CertificateKey' => base64_encode('KEY')], $this->http->lastJson());

        $this->bunny->removeCertificate(1, 'cdn.test');
        self::assertSame(['DELETE', 'https://api.bunny.net/pullzone/1/removeCertificate'], [$this->http->last()['method'], $this->http->last()['url']]);

        $this->bunny->addFreeSSLCertificate('cdn.test');
        self::assertSame('https://api.bunny.net/pullzone/loadFreeCertificate?hostname=cdn.test&useOnlyHttp01=false', $this->http->last()['url']);

        $this->bunny->forceSSLPullZone(1, 'cdn.test');
        self::assertSame(['Hostname' => 'cdn.test', 'ForceSSL' => true], $this->http->lastJson());
    }

    public function testEdgeRules(): void
    {
        $this->bunny->addOrUpdateEdgeRule(1, ['ActionType' => 1, 'Enabled' => true]);
        self::assertSame('https://api.bunny.net/pullzone/1/edgerules/addOrUpdate', $this->http->last()['url']);

        $this->bunny->setEdgeRuleEnabled(1, 'guid-1', false);
        self::assertSame('https://api.bunny.net/pullzone/1/edgerules/guid-1/setEdgeRuleEnabled', $this->http->last()['url']);
        self::assertSame(['Id' => 1, 'Value' => false], $this->http->lastJson());

        $this->bunny->deleteEdgeRule(1, 'guid-1');
        self::assertSame(['DELETE', 'https://api.bunny.net/pullzone/1/edgerules/guid-1'], [$this->http->last()['method'], $this->http->last()['url']]);
    }

    public function testResetSecurityKeyAndAvailability(): void
    {
        $this->bunny->resetTokenKey(1);
        self::assertNull($this->http->last()['body']);
        $this->bunny->checkPullZoneAvailability('name');
        self::assertSame(['Name' => 'name'], $this->http->lastJson());
    }

    public function testHostnamesAndBlockedIpsHelpers(): void
    {
        $this->http->push(200, ['Hostnames' => [['Id' => 1, 'Value' => 'a.test', 'ForceSSL' => true]]]);
        self::assertSame(['hostname_count' => 1, 'hostnames' => [['id' => 1, 'hostname' => 'a.test', 'force_ssl' => true]]], $this->bunny->pullZoneHostnames(1));
        self::assertCount(1, $this->http->requests, 'pull zone data should be fetched once');

        $this->http->push(200, ['BlockedIps' => ['1.1.1.1']]);
        self::assertSame(['blocked_ip_count' => 1, 'ips' => ['1.1.1.1']], $this->bunny->listBlockedIpPullZone(1));
    }

    public function testLegacyLogsAreParsed(): void
    {
        $this->http->push(200, "HIT|200|1759219200000|1024|7|1.2.3.4|-|https://cdn.test/a.jpg|DE|curl/8|req-1|DE\n\n");
        $logs = $this->bunny->pullZoneLogs(7, new DateTimeImmutable('2026-09-30'));
        self::assertSame('https://logging.bunnycdn.com/09-30-26/7.log', $this->http->last()['url']);
        self::assertCount(1, $logs);
        self::assertSame(200, $logs[0]['status']);
        self::assertSame(7, $logs[0]['zone_id']);
        self::assertSame(date('Y-m-d H:i:s', 1759219200), $logs[0]['datetime']);
    }

    public function testLogsV2(): void
    {
        $this->bunny->pullZoneLogsV2(7, new DateTimeImmutable('2026-09-29T00:00:00+00:00'), new DateTimeImmutable('2026-09-30T00:00:00+00:00'), ['status' => '5xx']);
        self::assertSame('https://logging.bunnycdn.com/v2/pullzones/7/logs?from=2026-09-29T00%3A00%3A00%2B00%3A00&to=2026-09-30T00%3A00%3A00%2B00%3A00&status=5xx', $this->http->last()['url']);
    }
}
