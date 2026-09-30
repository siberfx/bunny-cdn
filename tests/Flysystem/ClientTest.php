<?php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNRegion;
use Siberfx\BunnyCdn\Flysystem\Exceptions\NotFoundException;
use Siberfx\BunnyCdn\Tests\Flysystem\LiveCredentials;
use Siberfx\BunnyCdn\Tests\Flysystem\MockClient;

function storageClient(): BunnyCDNClient
{
    LiveCredentials::load();
    global $storage_zone, $api_key, $region;

    if ($storage_zone !== null && $api_key !== null) {
        return new BunnyCDNClient($storage_zone, $api_key, $region ?? BunnyCDNRegion::DEFAULT);
    }

    return new MockClient('test_storage_zone', '123');
}

function clearStorage(BunnyCDNClient $client): void
{
    foreach ($client->list('/') as $item) {
        try {
            $client->delete($item['IsDirectory'] ? $item['ObjectName'].'/' : $item['ObjectName']);
        } catch (Exception) {
            // best effort
        }
    }
}

function clientReturning(string $body, ?string &$uri = null): BunnyCDNClient
{
    $client = new BunnyCDNClient('test_storage_zone', 'api-key');
    $client->guzzleClient = new Client([
        'handler' => function ($request) use ($body, &$uri) {
            $uri = (string) $request->getUri();

            return new Response(200, [], $body);
        },
    ]);

    return $client;
}

describe('storage client', function () {
    beforeEach(function () {
        $this->client = storageClient();
        clearStorage($this->client);
        expect($this->client->list('/'))->toBeEmpty('Storage was not emptied before the test');
    });

    afterEach(fn () => clearStorage($this->client));

    test('lists a directory', function () {
        $this->client->make_directory('subfolder');
        $this->client->upload('example_image.png', 'test');

        expect($this->client->list('/'))->toBeArray()->toHaveCount(2);
    });

    test('lists a subdirectory', function () {
        $this->client->upload('/subfolder/example_image.png', 'test');

        expect($this->client->list('/subfolder'))->toBeArray()->toHaveCount(1);
    });

    test('downloads a file', function () {
        $this->client->upload('/test.png', 'test');

        expect($this->client->download('/test.png'))->toBeString();
    });

    test('streams a file', function () {
        $this->client->upload('/test.png', str_repeat('example_image_contents', 1024));

        $stream = $this->client->stream('/test.png');
        expect($stream)->toBeResource();

        do {
            $line = stream_get_line($stream, 512);
            expect($line)->toContain('example_image_contents')->and(strlen($line))->toBe(512);
        } while ($line && strlen($line) > 512);
    });

    test('uploads a string', function () {
        expect($this->client->upload('/test_contents.txt', 'testing_contents'))
            ->toBe(['HttpCode' => 201, 'Message' => 'File uploaded.']);
    });

    test('uploads a stream', function () {
        $stream = tmpfile();
        fwrite($stream, 'testing upload');
        rewind($stream);

        expect($this->client->upload('/test_upload_stream.txt', $stream))->toBe(['HttpCode' => 201, 'Message' => 'File uploaded.'])
            ->and($this->client->download('/test_upload_stream.txt'))->toBe('testing upload');

        fclose($stream);
    });

    test('creates a directory', function () {
        expect($this->client->make_directory('/test_dir/'))->toBe(['HttpCode' => 201, 'Message' => 'Directory created.']);
    });

    test('deletes a file', function () {
        $this->client->upload('test_file.txt', '123');

        expect($this->client->delete('/test_file.txt'))->toBe(['HttpCode' => 200, 'Message' => 'File deleted successfully.']);
    });

    test('deleting a missing file throws NotFoundException', function () {
        expect(fn () => $this->client->delete('file_not_found.txt'))->toThrow(NotFoundException::class);
    });
});

describe('response handling', function () {
    test('download returns the raw body', function (string $body) {
        expect(clientReturning($body)->download('/file.txt'))->toBe($body);
    })->with([
        'numeric' => '123',
        'boolean' => 'true',
        'json object' => '{"key":"value"}',
    ]);

    test('list decodes a json array', function () {
        expect(clientReturning('[{"ObjectName":"file.txt"}]')->list('/'))->toBe([['ObjectName' => 'file.txt']]);
    });

    test('listing the root has no double slash', function () {
        $client = clientReturning('[]', $uri);

        $client->list('');
        expect($uri)->toBe('https://storage.bunnycdn.com/test_storage_zone/');

        $client->list('folder');
        expect($uri)->toBe('https://storage.bunnycdn.com/test_storage_zone/folder/');
    });

    test('regions map to their storage hostnames', function (string $region, string $host) {
        $client = new BunnyCDNClient('zone', 'key', $region);

        expect((string) $client->createRequest('file.txt')->getUri())->toBe("https://$host/zone/file.txt");
    })->with([
        [BunnyCDNRegion::FALKENSTEIN, 'storage.bunnycdn.com'],
        [BunnyCDNRegion::STOCKHOLM, 'se.storage.bunnycdn.com'],
        [BunnyCDNRegion::UNITED_KINGDOM, 'uk.storage.bunnycdn.com'],
        [BunnyCDNRegion::NEW_YORK, 'ny.storage.bunnycdn.com'],
        [BunnyCDNRegion::LOS_ANGELES, 'la.storage.bunnycdn.com'],
        [BunnyCDNRegion::SINGAPORE, 'sg.storage.bunnycdn.com'],
        [BunnyCDNRegion::SYDNEY, 'syd.storage.bunnycdn.com'],
        [BunnyCDNRegion::BRAZIL, 'br.storage.bunnycdn.com'],
        [BunnyCDNRegion::JOHANNESBURG, 'jh.storage.bunnycdn.com'],
    ]);
});
