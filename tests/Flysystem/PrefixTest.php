<?php

declare(strict_types=1);

use League\Flysystem\Config;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\Visibility;
use Siberfx\BunnyCdn\Tests\Flysystem\PrefixedAdapterTestCase as Prefixed;

// The Flysystem conformance suite for the prefixed adapter runs in PrefixConformanceTest

beforeEach(function () {
    $this->client = Prefixed::bunnyCDNClient();
    $this->regular = Prefixed::bunnyCDNAdapter($this->client);
    $this->prefixed = new PathPrefixedAdapter($this->regular, Prefixed::PREFIX_PATH);
    $this->public = new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]);
});

afterEach(function () {
    clearAdapter($this->regular);
});

test('the root constructor argument scopes operations', function () {
    $adapter = Prefixed::bunnyCDNAdapter($this->client, Prefixed::PREFIX_PATH);

    $adapter->write('source.file.svg', 'root-scoped contents', new Config);

    expect($adapter->fileExists('source.file.svg'))->toBeTrue()
        ->and($this->regular->fileExists('source.file.svg'))->toBeFalse()
        ->and($this->regular->fileExists(Prefixed::PREFIX_PATH.'/source.file.svg'))->toBeTrue()
        ->and($this->regular->read(Prefixed::PREFIX_PATH.'/source.file.svg'))->toBe('root-scoped contents');

    $adapter->delete('source.file.svg');

    expect($adapter->fileExists('source.file.svg'))->toBeFalse()
        ->and($this->regular->fileExists(Prefixed::PREFIX_PATH.'/source.file.svg'))->toBeFalse();
});

test('prefixed and regular adapters see the same files', function () {
    $content = 'this is test';
    $inPrefix = Prefixed::PREFIX_PATH.'/source.file.svg';

    $this->prefixed->write('source.file.svg', $content, $this->public);

    expect($this->prefixed->fileExists('source.file.svg'))->toBeTrue()
        ->and($this->regular->directoryExists(Prefixed::PREFIX_PATH))->toBeTrue()
        ->and($this->regular->fileExists($inPrefix))->toBeTrue();

    $this->prefixed->copy('source.file.svg', 'source.copy.file.svg', $this->public);

    expect($this->regular->fileExists(Prefixed::PREFIX_PATH.'/source.copy.file.svg'))->toBeTrue()
        ->and($this->prefixed->fileExists('source.copy.file.svg'))->toBeTrue();

    $this->prefixed->delete('source.copy.file.svg');

    expect($this->prefixed->read('source.file.svg'))->toBe($content)
        ->and($this->regular->read($inPrefix))->toBe($content)
        ->and(stream_get_contents($this->prefixed->readStream('source.file.svg')))->toBe($content)
        ->and(stream_get_contents($this->regular->readStream($inPrefix)))->toBe($content)
        ->and($this->prefixed->mimeType('source.file.svg')->mimeType())->toBe('image/svg+xml')
        ->and($this->regular->mimeType($inPrefix)->mimeType())->toBe('image/svg+xml')
        ->and($this->prefixed->fileSize('source.file.svg')->fileSize())
            ->toBeGreaterThan(0)
            ->toBe($this->regular->fileSize($inPrefix)->fileSize())
        ->and($this->prefixed->lastModified('source.file.svg')->lastModified())
            ->toBeGreaterThan(time() - 30)
            ->toBe($this->regular->lastModified($inPrefix)->lastModified());

    $this->prefixed->delete('source.file.svg');
    expect($this->prefixed->fileExists('source.file.svg'))->toBeFalse();

    $this->prefixed->write('subfolder/subfolder2/source.file.svg', $content, $this->public);
    expect($this->regular->fileExists(Prefixed::PREFIX_PATH.'/subfolder/subfolder2/source.file.svg'))->toBeTrue();

    $this->prefixed->move('subfolder', 'newsubfolder', $this->public);

    expect($this->regular->fileExists(Prefixed::PREFIX_PATH.'/subfolder/subfolder2/source.file.svg'))->toBeFalse()
        ->and($this->prefixed->fileExists('newsubfolder/subfolder2/source.file.svg'))->toBeTrue();
});

// https://github.com/PlatformCommunity/flysystem-bunnycdn/pull/36
test('the prefix is not part of listed paths', function () {
    $this->prefixed->write('source.file.svg', '----', $this->public);

    $contents = iterator_to_array($this->prefixed->listContents('/', false));

    expect($contents)->toHaveCount(1)
        ->and($contents[0]['path'])->toBe('source.file.svg');
});
