<?php

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIException;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStream;

const STREAM = 'https://video.bunnycdn.com/library/42';

beforeEach(function () {
    $this->http = fakeHttp();
    $this->bunny = (new BunnyAPIStream('api-key', 'stream-key', $this->http))->setStreamLibraryId(42);
});

test('uses the stream host and the library key', function () {
    $this->bunny->getVideo('vid');

    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid')
        ->and($this->http->last()['headers']['AccessKey'])->toBe('stream-key');
});

test('the library id is required', function () {
    (new BunnyAPIStream('a', 'b', $this->http))->listVideos();
})->throws(BunnyAPIException::class, 'You must set the stream library id first');

test('collections', function () {
    $this->bunny->getStreamCollections(search: 'cats');
    expect($this->http->last()['url'])->toBe(STREAM.'/collections?page=1&itemsPerPage=100&search=cats&orderBy=date&includeThumbnails=false');

    $this->bunny->setStreamCollectionGuid('col')->updateCollection('renamed');
    expect($this->http->lastJson())->toBe(['name' => 'renamed']);

    $this->bunny->listVideosForCollectionId();
    expect($this->http->last()['url'])->toBe(STREAM.'/videos?page=1&itemsPerPage=100&collection=col&orderBy=date');
});

test('creates and updates videos', function () {
    $this->bunny->setStreamCollectionGuid('col')->createVideoForCollection('Title', 5);
    expect($this->http->lastJson())->toBe(['title' => 'Title', 'collectionId' => 'col', 'thumbnailTime' => 5]);

    $this->bunny->updateVideo('vid', ['title' => 'New']);
    expect($this->http->last())->method->toBe('POST')->url->toBe(STREAM.'/videos/vid');
});

test('uploads video as an octet stream', function () {
    $file = tempnam(sys_get_temp_dir(), 'vid');
    file_put_contents($file, 'video-bytes');

    $this->bunny->uploadVideo('vid', $file, ['enabledResolutions' => '720p']);
    unlink($file);

    expect($this->http->last())
        ->method->toBe('PUT')
        ->url->toBe(STREAM.'/videos/vid?enabledResolutions=720p')
        ->body->toBe('video-bytes')
        ->and($this->http->last()['headers']['Content-Type'])->toBe('application/octet-stream');
});

test('thumbnail, captions and fetch', function () {
    $this->bunny->setThumbnail('vid', 'https://img.test/a.jpg');
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid/thumbnail?thumbnailUrl=https%3A%2F%2Fimg.test%2Fa.jpg');

    $this->bunny->addCaptions('vid', 'en', 'English', "WEBVTT\n");
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid/captions/en')
        ->and($this->http->lastJson())->toBe(['label' => 'English', 'captionsFile' => base64_encode("WEBVTT\n")]);

    $this->bunny->fetchVideo('https://src.test/v.mp4', 'col', 'Title');
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/fetch?collectionId=col')
        ->and($this->http->lastJson())->toBe(['url' => 'https://src.test/v.mp4', 'title' => 'Title']);
});

test('transcribe, repackage and resolutions', function () {
    $this->bunny->transcribeVideo('vid', ['en', 'de'], 'en', true);
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid/transcribe?force=true')
        ->and($this->http->lastJson())->toBe(['targetLanguages' => ['en', 'de'], 'sourceLanguage' => 'en']);

    $this->bunny->repackageVideo('vid');
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid/repackage?keepOriginalFiles=true');

    $this->bunny->getVideoResolutions('vid');
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid/resolutions');

    $this->bunny->cleanupVideoResolutions('vid', ['resolutionsToDelete' => '240p', 'dryRun' => true]);
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid/resolutions/cleanup?resolutionsToDelete=240p&dryRun=true');

    $this->bunny->setStreamVideoGuid('vid')->getVideoPlayData();
    expect($this->http->last()['url'])->toBe(STREAM.'/videos/vid/play');
});

test('video size and resolutions helpers', function () {
    $this->http->push(200, ['storageSize' => 1048576 * 3, 'availableResolutions' => '360p,720p']);
    expect($this->bunny->videoSize('vid'))->toEqual(3);

    $this->http->push(200, ['storageSize' => 0, 'availableResolutions' => '360p,720p']);
    expect($this->bunny->videoResolutionsArray('vid'))->toBe(['360p', '720p']);
});
