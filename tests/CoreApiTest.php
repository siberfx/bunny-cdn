<?php

use Siberfx\BunnyCdn\BunnyAPI;
use Siberfx\BunnyCdn\BunnyAPIException;

beforeEach(function () {
    $this->http = fakeHttp();
    $this->bunny = new BunnyAPI('api-key', 'stream-key', $this->http);
});

test('sends the access key and json headers', function () {
    $this->bunny->getBilling();

    expect($this->http->last())
        ->method->toBe('GET')
        ->url->toBe('https://api.bunny.net/billing')
        ->body->toBeNull()
        ->and($this->http->last()['headers'])
        ->toMatchArray(['AccessKey' => 'api-key', 'Accept' => 'application/json']);
});

test('a missing api key throws', function () {
    (new BunnyAPI(http: $this->http))->getBilling();
})->throws(BunnyAPIException::class, 'You must provide a API key');

test('an empty api key cannot be set', function () {
    $this->bunny->apiKey('  ');
})->throws(BunnyAPIException::class);

test('error responses throw with status and body', function () {
    $this->http->push(401, ['Message' => 'Unauthorized']);

    try {
        $this->bunny->getBilling();
        $this->fail('Expected exception');
    } catch (BunnyAPIException $e) {
        expect($e->getStatusCode())->toBe(401)
            ->and($e->getResponseBody())->toBe(['Message' => 'Unauthorized'])
            ->and($e->getMessage())->toContain('Unauthorized');
    }
});

test('empty responses are wrapped', function () {
    $this->http->push(204, '');

    expect($this->bunny->claimAffiliate())->toBe(['http_code' => 204, 'response' => null])
        ->and($this->bunny->lastResponse()?->status)->toBe(204);
});

test('purgeCache encodes the url and flags', function () {
    $this->bunny->purgeCache('https://cdn.example.com/a b.css?v=1', true, true);

    expect($this->http->last())
        ->method->toBe('POST')
        ->url->toBe('https://api.bunny.net/purge?url=https%3A%2F%2Fcdn.example.com%2Fa%20b.css%3Fv%3D1&async=true&exactPath=true');
});

test('statistics skips null params', function () {
    $this->bunny->getStatistics(pullzone_id: 5, date_from: '2026-09-01', options: ['loadErrors' => true]);

    expect($this->http->last()['url'])
        ->toBe('https://api.bunny.net/statistics?dateFrom=2026-09-01&pullZone=5&hourly=false&loadErrors=true');
});

test('billing helpers', function () {
    $billing = ['Balance' => 12.5, 'ThisMonthCharges' => 3, 'BillingRecords' => [
        ['Amount' => 1.25, 'Timestamp' => '2026-09-01T10:00:00'],
        ['Amount' => 2.5, 'Timestamp' => '2026-08-01T10:00:00'],
    ]];
    $this->http->push(200, $billing)->push(200, $billing)->push(200, $billing);

    expect($this->bunny->balance())->toBe(12.5)
        ->and($this->bunny->monthCharges())->toBe(3.0)
        ->and($this->bunny->totalBillingAmount(true))->toBe(['amount' => 3.75, 'since' => '2026-08-01 10:00:00']);
});

test('account endpoints', function (string $method, string $call, string $url) {
    $this->bunny->{$call}(...($call === 'getBillingSummary' ? [] : [9]));

    expect($this->http->last())->method->toBe($method)->url->toBe($url);
})->with([
    ['GET', 'getBillingSummary', 'https://api.bunny.net/billing/summary'],
    ['GET', 'getAbuseCase', 'https://api.bunny.net/abusecase/9'],
    ['POST', 'checkAbuseCase', 'https://api.bunny.net/abusecase/9/check'],
]);

test('convertBytes', function () {
    expect($this->bunny->convertBytes(1073741824))->toBe('1.00')
        ->and($this->bunny->convertBytes(1048576, 'MB', false))->toEqual(1);
});
