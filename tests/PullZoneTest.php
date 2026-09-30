<?php

use Siberfx\BunnyCdn\BunnyAPIPull;

beforeEach(function () {
    $this->http = fakeHttp();
    $this->bunny = new BunnyAPIPull('api-key', http: $this->http);
});

test('lists pull zones', function () {
    $this->bunny->listPullZones(search: 'my zone');

    expect($this->http->last()['url'])
        ->toBe('https://api.bunny.net/pullzone?page=0&perPage=1000&search=my%20zone&includeCertificate=false');
});

test('creates a pull zone', function () {
    $this->bunny->createPullZone('zone', 'https://origin.test', ['Type' => 1]);
    expect($this->http->last()['method'])->toBe('POST')
        ->and($this->http->lastJson())->toBe(['Name' => 'zone', 'OriginUrl' => 'https://origin.test', 'Type' => 1]);

    $this->bunny->createPullZone('zone', args: ['StorageZoneId' => 5]);
    expect($this->http->lastJson())->toBe(['Name' => 'zone', 'StorageZoneId' => 5]);
});

test('purges with and without a cache tag', function () {
    $this->bunny->purgePullZone(1);
    expect($this->http->last()['body'])->toBeNull();

    $this->bunny->purgePullZone(1, 'images');
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/pullzone/1/purgeCache')
        ->and($this->http->lastJson())->toBe(['CacheTag' => 'images']);
});

test('hostname and certificate endpoints', function () {
    $this->bunny->removeHostnamePullZone(1, 'cdn.test');
    expect($this->http->last()['method'])->toBe('DELETE')
        ->and($this->http->lastJson())->toBe(['Hostname' => 'cdn.test']);

    $this->bunny->addCertificate(1, 'cdn.test', 'CERT', 'KEY');
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/pullzone/1/addCertificate')
        ->and($this->http->lastJson())->toBe(['Hostname' => 'cdn.test', 'Certificate' => base64_encode('CERT'), 'CertificateKey' => base64_encode('KEY')]);

    $this->bunny->removeCertificate(1, 'cdn.test');
    expect($this->http->last())->method->toBe('DELETE')->url->toBe('https://api.bunny.net/pullzone/1/removeCertificate');

    $this->bunny->addFreeSSLCertificate('cdn.test');
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/pullzone/loadFreeCertificate?hostname=cdn.test&useOnlyHttp01=false');

    $this->bunny->forceSSLPullZone(1, 'cdn.test');
    expect($this->http->lastJson())->toBe(['Hostname' => 'cdn.test', 'ForceSSL' => true]);
});

test('edge rules', function () {
    $this->bunny->addOrUpdateEdgeRule(1, ['ActionType' => 1, 'Enabled' => true]);
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/pullzone/1/edgerules/addOrUpdate');

    $this->bunny->setEdgeRuleEnabled(1, 'guid-1', false);
    expect($this->http->last()['url'])->toBe('https://api.bunny.net/pullzone/1/edgerules/guid-1/setEdgeRuleEnabled')
        ->and($this->http->lastJson())->toBe(['Id' => 1, 'Value' => false]);

    $this->bunny->deleteEdgeRule(1, 'guid-1');
    expect($this->http->last())->method->toBe('DELETE')->url->toBe('https://api.bunny.net/pullzone/1/edgerules/guid-1');
});

test('reset security key and availability check', function () {
    $this->bunny->resetTokenKey(1);
    expect($this->http->last()['body'])->toBeNull();

    $this->bunny->checkPullZoneAvailability('name');
    expect($this->http->lastJson())->toBe(['Name' => 'name']);
});

test('hostname and blocked ip helpers', function () {
    $this->http->push(200, ['Hostnames' => [['Id' => 1, 'Value' => 'a.test', 'ForceSSL' => true]]]);
    expect($this->bunny->pullZoneHostnames(1))
        ->toBe(['hostname_count' => 1, 'hostnames' => [['id' => 1, 'hostname' => 'a.test', 'force_ssl' => true]]])
        ->and($this->http->requests)->toHaveCount(1);

    $this->http->push(200, ['BlockedIps' => ['1.1.1.1']]);
    expect($this->bunny->listBlockedIpPullZone(1))->toBe(['blocked_ip_count' => 1, 'ips' => ['1.1.1.1']]);
});

test('parses legacy logs', function () {
    $this->http->push(200, "HIT|200|1759219200000|1024|7|1.2.3.4|-|https://cdn.test/a.jpg|DE|curl/8|req-1|DE\n\n");

    $logs = $this->bunny->pullZoneLogs(7, new DateTimeImmutable('2026-09-30'));

    expect($this->http->last()['url'])->toBe('https://logging.bunnycdn.com/09-30-26/7.log')
        ->and($logs)->toHaveCount(1)
        ->and($logs[0])->toMatchArray([
            'status' => 200,
            'zone_id' => 7,
            'datetime' => date('Y-m-d H:i:s', 1759219200),
        ]);
});

test('logging api v2', function () {
    $this->bunny->pullZoneLogsV2(7, new DateTimeImmutable('2026-09-29T00:00:00+00:00'), new DateTimeImmutable('2026-09-30T00:00:00+00:00'), ['status' => '5xx']);

    expect($this->http->last()['url'])
        ->toBe('https://logging.bunnycdn.com/v2/pullzones/7/logs?from=2026-09-29T00%3A00%3A00%2B00%3A00&to=2026-09-30T00%3A00%3A00%2B00%3A00&status=5xx');
});
