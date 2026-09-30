<?php

use League\Flysystem\Config;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;

function signingAdapter(string $root = ''): BunnyCDNAdapter
{
    return (new BunnyCDNAdapter(new BunnyCDNClient('test', 'test'), 'https://pz-url.co.uk', $root))
        ->setTokenAuthKey('test-auth-key');
}

beforeEach(function () {
    $this->expiresAt = new DateTimeImmutable('+1 hour');
});

test('requires a token auth key', function () {
    $adapter = new BunnyCDNAdapter(new BunnyCDNClient('test', 'test'), 'pz-key');

    expect(fn () => $adapter->temporaryUrl('testing.text', $this->expiresAt, new Config))
        ->toThrow(UnableToGenerateTemporaryUrl::class, 'you must call the `setTokenAuthKey`');
});

test('generates a signed url', function () {
    expect(signingAdapter()->temporaryUrl('testing.txt', $this->expiresAt, new Config))
        ->toContain('https://pz-url.co.uk/testing.txt?token=')
        ->toContain('expires='.$this->expiresAt->getTimestamp());
});

test('signs additional query params', function () {
    $url = signingAdapter()->temporaryUrl('testing.txt', $this->expiresAt, new Config([
        'withQueryParams' => ['testParam' => 'testValue'],
    ]));

    expect($url)
        ->toContain('https://pz-url.co.uk/testing.txt?token=')
        ->toContain('expires='.$this->expiresAt->getTimestamp())
        ->toContain('testParam=testValue');
});

test('the laravel compatible method matches temporaryUrl()', function () {
    $adapter = signingAdapter();

    expect($adapter->getTemporaryUrl('testing.txt', $this->expiresAt, []))
        ->toBe($adapter->temporaryUrl('testing.txt', $this->expiresAt, new Config));
});

test('accepts the expiration in minutes', function () {
    expect(signingAdapter()->getTemporaryUrl('testing.txt', 60, []))
        ->toContain('https://pz-url.co.uk/testing.txt?token=')
        ->toContain('expires='.(time() + 3600));
});

test('passes laravel options as signed query params', function () {
    expect(signingAdapter()->getTemporaryUrl('testing.txt', $this->expiresAt, ['testParam' => 'testValue']))
        ->toContain('https://pz-url.co.uk/testing.txt?token=')
        ->toContain('expires='.$this->expiresAt->getTimestamp())
        ->toContain('testParam=testValue');
});

test('the root scopes the signed path', function () {
    expect(signingAdapter('assets')->temporaryUrl('testing.txt', $this->expiresAt, new Config))
        ->toContain('https://pz-url.co.uk/assets/testing.txt?token=')
        ->toContain('expires='.$this->expiresAt->getTimestamp());
});

test('a full url is signed without the root', function () {
    expect(signingAdapter('assets')->temporaryUrl('https://cdn.example.org/path/file.txt?download=file.txt', $this->expiresAt, new Config))
        ->toContain('https://cdn.example.org/path/file.txt')
        ->toContain('token=')
        ->not->toContain('/assets')
        ->toContain('expires='.$this->expiresAt->getTimestamp());
});
