<?php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\Config;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\WriteBatchFile;
use Siberfx\BunnyCdn\Tests\Flysystem\MockClient;
use Siberfx\BunnyCdn\Tests\Flysystem\RootedAdapterTestCase as Rooted;

// The Flysystem conformance suite for the root-scoped adapter runs in RootConformanceTest

beforeEach(function () {
    $this->client = Rooted::bunnyCDNClient();
    $this->adapter = Rooted::bunnyCDNAdapter($this->client);
    $this->unscoped = Rooted::bunnyCDNAdapter($this->client, '');
});

afterEach(function () {
    clearAdapter($this->unscoped);
});

test('files written through a root scoped adapter stay inside the root', function () {
    $this->adapter->write('path.txt', 'root-scoped contents', new Config);

    expect($this->adapter->fileExists('path.txt'))->toBeTrue()
        ->and($this->unscoped->fileExists('path.txt'))->toBeFalse()
        ->and($this->unscoped->fileExists(Rooted::ROOT_PATH.'/path.txt'))->toBeTrue()
        ->and($this->unscoped->read(Rooted::ROOT_PATH.'/path.txt'))->toBe('root-scoped contents');

    $this->adapter->delete('path.txt');

    expect($this->unscoped->fileExists(Rooted::ROOT_PATH.'/path.txt'))->toBeFalse();
});

test('listed paths do not contain the root', function () {
    $this->adapter->write('folder/file.txt', 'contents', new Config);

    $root = iterator_to_array($this->adapter->listContents('', false));
    $folder = iterator_to_array($this->adapter->listContents('folder', false));

    expect($root)->toHaveCount(1)
        ->and($root[0]['path'])->toBe('folder')
        ->and($folder)->toHaveCount(1)
        ->and($folder[0]['path'])->toBe('folder/file.txt');
});

test('a root with a trailing slash is normalized', function () {
    $adapter = new BunnyCDNAdapter($this->client, Rooted::PULL_ZONE, Rooted::ROOT_PATH.'/');

    $adapter->write('folder/path.txt', 'contents', new Config);
    $listing = iterator_to_array($adapter->listContents('folder', false));

    expect($adapter->fileExists('folder/path.txt'))->toBeTrue()
        ->and($listing)->toHaveCount(1)
        ->and($listing[0]['path'])->toBe('folder/path.txt')
        ->and($adapter->publicUrl('folder/path.txt', new Config))->toBe(Rooted::PULL_ZONE.Rooted::ROOT_PATH.'/folder/path.txt');

    $adapter->setTokenAuthKey('test-token-auth-key');

    expect($adapter->temporaryUrl('folder/path.txt', new DateTimeImmutable('+1 hour'), new Config))
        ->toContain(Rooted::ROOT_PATH.'/folder/path.txt?token=');
});

test('write batch uploads to root scoped paths', function () {
    $client = new MockClient('test_storage_zone', '123');
    $client->guzzleClient = new Client([
        'handler' => function (Request $request) use ($client) {
            if ($request->getMethod() !== 'PUT') {
                throw new RuntimeException('Unexpected request: '.$request->getMethod().' '.$request->getUri());
            }
            $path = ltrim(str_replace('/test_storage_zone', '', $request->getUri()->getPath()), '/');
            $client->filesystem->write($path, (string) $request->getBody());

            return new Response(200);
        },
    ]);
    $adapter = new BunnyCDNAdapter($client, Rooted::PULL_ZONE, Rooted::ROOT_PATH);

    $first = tmpfile();
    fwrite($first, 'text');
    $second = tmpfile();
    fwrite($second, 'text2');

    $adapter->writeBatch([
        new WriteBatchFile(stream_get_meta_data($first)['uri'], 'destination.txt'),
        new WriteBatchFile(stream_get_meta_data($second)['uri'], 'destination2.txt'),
    ], new Config);

    fclose($first);
    fclose($second);

    expect($adapter->fileExists('destination.txt'))->toBeTrue()
        ->and($adapter->read('destination.txt'))->toBe('text')
        ->and($adapter->read('destination2.txt'))->toBe('text2')
        ->and($client->filesystem->fileExists(Rooted::ROOT_PATH.'/destination.txt'))->toBeTrue();
});

test('a root can be combined with path prefixing', function () {
    $prefixed = new PathPrefixedAdapter($this->adapter, 'additional_prefix');

    $prefixed->write('path.txt', 'contents', new Config);

    expect($this->adapter->fileExists('additional_prefix/path.txt'))->toBeTrue()
        ->and($this->adapter->fileExists('path.txt'))->toBeFalse()
        ->and($prefixed->read('path.txt'))->toBe('contents');
});
