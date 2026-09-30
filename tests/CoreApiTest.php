<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Tests;

use PHPUnit\Framework\TestCase;
use Siberfx\BunnyCdn\BunnyAPI;
use Siberfx\BunnyCdn\BunnyAPIException;

final class CoreApiTest extends TestCase
{
    private FakeHttpClient $http;
    private BunnyAPI $bunny;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->bunny = new BunnyAPI('api-key', 'stream-key', $this->http);
    }

    public function testSendsAccessKeyAndJsonHeaders(): void
    {
        $this->bunny->getBilling();
        $request = $this->http->last();
        self::assertSame('GET', $request['method']);
        self::assertSame('https://api.bunny.net/billing', $request['url']);
        self::assertSame('api-key', $request['headers']['AccessKey']);
        self::assertSame('application/json', $request['headers']['Accept']);
        self::assertNull($request['body']);
    }

    public function testMissingApiKeyThrows(): void
    {
        $this->expectException(BunnyAPIException::class);
        new BunnyAPI(http: $this->http)->getBilling();
    }

    public function testEmptyKeySetterThrows(): void
    {
        $this->expectException(BunnyAPIException::class);
        $this->bunny->apiKey('  ');
    }

    public function testErrorResponseThrowsWithStatusAndBody(): void
    {
        $this->http->push(401, ['Message' => 'Unauthorized']);
        try {
            $this->bunny->getBilling();
            self::fail('Expected exception');
        } catch (BunnyAPIException $e) {
            self::assertSame(401, $e->getStatusCode());
            self::assertSame(['Message' => 'Unauthorized'], $e->getResponseBody());
            self::assertStringContainsString('Unauthorized', $e->getMessage());
        }
    }

    public function testEmptyResponseIsWrapped(): void
    {
        $this->http->push(204, '');
        self::assertSame(['http_code' => 204, 'response' => null], $this->bunny->claimAffiliate());
        self::assertSame(204, $this->bunny->lastResponse()?->status);
    }

    public function testPurgeCacheEncodesUrlAndFlags(): void
    {
        $this->bunny->purgeCache('https://cdn.example.com/a b.css?v=1', true, true);
        $request = $this->http->last();
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.bunny.net/purge?url=https%3A%2F%2Fcdn.example.com%2Fa%20b.css%3Fv%3D1&async=true&exactPath=true', $request['url']);
    }

    public function testStatisticsSkipsNullParams(): void
    {
        $this->bunny->getStatistics(pullzone_id: 5, date_from: '2026-09-01', options: ['loadErrors' => true]);
        self::assertSame('https://api.bunny.net/statistics?dateFrom=2026-09-01&pullZone=5&hourly=false&loadErrors=true', $this->http->last()['url']);
    }

    public function testBillingHelpers(): void
    {
        $billing = ['Balance' => 12.5, 'ThisMonthCharges' => 3, 'BillingRecords' => [
            ['Amount' => 1.25, 'Timestamp' => '2026-09-01T10:00:00'],
            ['Amount' => 2.5, 'Timestamp' => '2026-08-01T10:00:00'],
        ]];
        $this->http->push(200, $billing)->push(200, $billing)->push(200, $billing);
        self::assertSame(12.5, $this->bunny->balance());
        self::assertSame(3.0, $this->bunny->monthCharges());
        self::assertSame(['amount' => 3.75, 'since' => '2026-08-01 10:00:00'], $this->bunny->totalBillingAmount(true));
    }

    public function testAccountEndpoints(): void
    {
        $this->bunny->getBillingSummary();
        self::assertSame('https://api.bunny.net/billing/summary', $this->http->last()['url']);
        $this->bunny->getAbuseCase(9);
        self::assertSame('https://api.bunny.net/abusecase/9', $this->http->last()['url']);
        $this->bunny->checkAbuseCase(9);
        self::assertSame(['POST', 'https://api.bunny.net/abusecase/9/check'], [$this->http->last()['method'], $this->http->last()['url']]);
    }

    public function testConvertBytes(): void
    {
        self::assertSame('1.00', $this->bunny->convertBytes(1073741824));
        self::assertSame(1, $this->bunny->convertBytes(1048576, 'MB', false));
    }
}
