<?php

use Faker\Factory;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNAdapter;
use Siberfx\BunnyCdn\Flysystem\BunnyCDNClient;
use Siberfx\BunnyCdn\Flysystem\WriteBatchFile;
use Siberfx\BunnyCdn\Tests\Flysystem\BunnyAdapterTestCase;

// The Flysystem conformance suite for this adapter runs in AdapterConformanceTest

beforeEach(function () {
    $this->adapter = BunnyAdapterTestCase::createFilesystemAdapter();
    $this->public = new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]);
});

afterEach(function () {
    clearAdapter($this->adapter);
});

test('deleting a directory path with delete() throws', function () {
    $this->adapter->write('test/text.txt', 'contents', $this->public);

    expect(fn () => $this->adapter->delete('test/'))->toThrow(UnableToDeleteFile::class);
});

test('delete with an empty path throws', function () {
    $this->adapter->write('test/text.txt', 'contents', $this->public);

    expect(fn () => $this->adapter->delete(''))->toThrow(UnableToDeleteFile::class);
});

test('moving a folder', function () {
    $this->adapter->write('test/text.txt', 'contents to be copied', $this->public);
    $this->adapter->write('test/2/text.txt', 'contents to be copied', $this->public);

    $this->adapter->move('test', 'destination', new Config);

    expect($this->adapter->fileExists('test/text.txt'))->toBeFalse()
        ->and($this->adapter->fileExists('test/2/text.txt'))->toBeFalse()
        ->and($this->adapter->fileExists('destination/text.txt'))->toBeTrue()
        ->and($this->adapter->fileExists('destination/2/text.txt'))->toBeTrue()
        ->and($this->adapter->read('destination/text.txt'))->toBe('contents to be copied')
        ->and($this->adapter->read('destination/2/text.txt'))->toBe('contents to be copied');
});

test('moving a folder that does not exist throws', function () {
    expect(fn () => $this->adapter->move('not_existing_file', 'destination', new Config))
        ->toThrow(UnableToMoveFile::class);
});

test('copying a folder', function () {
    $this->adapter->write('test/text.txt', 'contents to be copied', $this->public);
    $this->adapter->write('test/2/text.txt', 'contents to be copied', $this->public);

    $this->adapter->copy('test', 'destination', new Config);

    expect($this->adapter->fileExists('test/text.txt'))->toBeTrue()
        ->and($this->adapter->fileExists('test/2/text.txt'))->toBeTrue()
        ->and($this->adapter->fileExists('destination/text.txt'))->toBeTrue()
        ->and($this->adapter->fileExists('destination/2/text.txt'))->toBeTrue()
        ->and($this->adapter->read('destination/text.txt'))->toBe('contents to be copied')
        ->and($this->adapter->read('destination/2/text.txt'))->toBe('contents to be copied');
});

test('copying a folder that does not exist throws', function () {
    expect(fn () => $this->adapter->copy('not_existing_file', 'destination', new Config))
        ->toThrow(UnableToCopyFile::class);
});

test('public url requires a pull zone url', function () {
    $adapter = new BunnyCDNAdapter(BunnyAdapterTestCase::bunnyCDNClient());

    expect(fn () => $adapter->publicUrl('/path.txt', new Config))
        ->toThrow(RuntimeException::class, 'In order to get a visible URL for a BunnyCDN object, you must pass the "pullzone_url" parameter to the BunnyCDNAdapter.');
});

test('writing a file using a stream', function () {
    $stream = tmpfile();
    fwrite($stream, 'contents');
    rewind($stream);

    $this->adapter->writeStream('path.txt', $stream, new Config);
    fclose($stream);

    expect($this->adapter->fileExists('path.txt'))->toBeTrue()
        ->and($this->adapter->read('path.txt'))->toBe('contents');
});

test('moving a file onto itself keeps it', function () {
    $this->adapter->write('source.txt', 'contents to be copied', $this->public);

    $this->adapter->move('source.txt', 'source.txt', new Config);

    expect($this->adapter->fileExists('source.txt'))->toBeTrue();
});

test('sha256 checksum of a missing file throws', function () {
    expect(fn () => $this->adapter->checksum('path.txt', new Config(['checksum_algo' => 'sha256'])))
        ->toThrow(UnableToProvideChecksum::class);
});

test('sha256 checksum throws when the client has no checksum', function () {
    $faker = Factory::create();
    $client = $this->createMock(BunnyCDNClient::class);
    $client->expects($this->once())->method('list')->willReturn([
        bunnyListingItem('file.txt', $faker->word(), ['Length' => $faker->numberBetween(0, 10240), 'Checksum' => null]),
    ]);

    expect(fn () => (new BunnyCDNAdapter($client))->checksum('file.txt', new Config(['checksum_algo' => 'sha256'])))
        ->toThrow(UnableToProvideChecksum::class, 'Unable to get checksum for file.txt: Checksum not available.');
});

test('mime type of an svg is detected from the file name', function () {
    $this->adapter->write(
        'source.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>',
        new Config
    );

    expect($this->adapter->detectMimeType('source.svg'))->toBe('image/svg+xml');
});

// https://github.com/PlatformCommunity/flysystem-bunnycdn/pull/20
test('regression: reading a downloaded image as a stream', function () {
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z/C/HgAGgwJ/lK3Q6wAAAABJRU5ErkJggg==');
    $this->adapter->write('path.png', $image, new Config);

    $stream = $this->adapter->readStream('path.png');

    expect($stream)->toBeResource()
        ->and(stream_get_contents($stream))->toBe($image);
});

// https://github.com/PlatformCommunity/flysystem-bunnycdn/issues/28
test('regression: metadata of a directory throws UnableToRetrieveMetadata', function () {
    $client = BunnyAdapterTestCase::bunnyCDNClient();
    $client->make_directory('/example_folder');
    $filesystem = new Filesystem(new BunnyCDNAdapter($client));

    expect(fn () => $filesystem->fileSize('/example_folder'))->toThrow(UnableToRetrieveMetadata::class)
        ->and(fn () => $filesystem->mimeType('/example_folder'))->toThrow(UnableToRetrieveMetadata::class)
        ->and(fn () => $filesystem->lastModified('/example_folder'))->toThrow(UnableToRetrieveMetadata::class);

    $client->delete('example_folder/');
});

// https://github.com/PlatformCommunity/flysystem-bunnycdn/issues/39
test('regression: reading a file containing json returns a string', function () {
    $this->adapter->write('test.json', json_encode(['test' => 123]), new Config);

    expect($this->adapter->read('/test.json'))->toBeString();
});

test('write batch uploads every file', function () {
    $first = tmpfile();
    fwrite($first, 'text');
    $second = tmpfile();
    fwrite($second, 'text2');

    $this->adapter->writeBatch([
        new WriteBatchFile(stream_get_meta_data($first)['uri'], 'destination.txt'),
        new WriteBatchFile(stream_get_meta_data($second)['uri'], 'destination2.txt'),
    ], new Config);

    fclose($first);
    fclose($second);

    expect($this->adapter->read('destination.txt'))->toBe('text')
        ->and($this->adapter->read('destination2.txt'))->toBe('text2');
});

test('a failing write batch throws UnableToWriteFile', function () {
    $file = tmpfile();
    fwrite($file, 'text');
    $path = stream_get_meta_data($file)['uri'];

    expect(fn () => $this->adapter->writeBatch([
        new WriteBatchFile($path, 'failing.txt'),
        new WriteBatchFile($path, 'failing2.txt'),
    ], new Config))->toThrow(UnableToWriteFile::class);

    fclose($file);
})->skip(fn () => BunnyAdapterTestCase::isLive(), 'Not applicable in live mode');

test('deleting the root directory is refused', function (string $root) {
    expect(fn () => $this->adapter->deleteDirectory($root))->toThrow(UnableToDeleteDirectory::class);
})->with(['empty' => '', 'slash' => '/']);

test('last modified with a malformed timestamp does not crash', function () {
    $client = $this->createMock(BunnyCDNClient::class);
    $client->expects($this->once())->method('list')->willReturn([
        bunnyListingItem('file.txt', 'test_storage_zone', ['LastChanged' => 'not-a-valid-timestamp', 'DateCreated' => 'not-a-valid-timestamp']),
    ]);

    expect((new BunnyCDNAdapter($client))->lastModified('file.txt')->lastModified())->toBe(0);
});
