<?php

declare(strict_types=1);

/*
 * Bunny Stream: collections, uploads, fetching, captions, thumbnails, transcription, statistics.
 *
 *   BUNNY_STREAM_LIBRARY_ID=1234 BUNNY_STREAM_ACCESS_KEY=... [BUNNY_EXAMPLES_WRITE=1] \
 *   php native-usage-docs/07-stream.php [/path/to/video.mp4]
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStream;

// Stream calls use the library API key (Stream -> library -> API), not the account key
$stream = new BunnyAPIStream(stream_library_access_key: envVar('BUNNY_STREAM_ACCESS_KEY'))
    ->setStreamLibraryId((int) envVar('BUNNY_STREAM_LIBRARY_ID'));

/*
 * Reading
 */
$videos = $stream->listVideos(items_pp: 10, order_by: 'date');
show('Latest videos', array_map(fn (array $video): array => [
    'guid' => $video['guid'],
    'title' => $video['title'],
    'status' => $video['status'],          // 4 = finished
    'length' => $video['length'].'s',
], $videos['items'] ?? []));

show('Search "cat"', $stream->listVideos(search: 'cat')['totalItems'] ?? 0);
show('Collections', array_column($stream->getStreamCollections(include_thumbnails: false)['items'] ?? [], 'name', 'guid'));
show('Library statistics (7 days)', $stream->getVideoStatistics(
    date_from: new DateTimeImmutable('-7 days')->format('Y-m-d'),
    hourly: false,
));

if ($first = $videos['items'][0]['guid'] ?? null) {
    show('Video', $stream->getVideo($first));
    show('Resolutions', $stream->videoResolutionsArray($first));
    show('Stored resolutions', $stream->getVideoResolutions($first));
    show('Size', $stream->videoSize($first, 'MB', format: true).' MB');
    show('Heatmap', $stream->getVideoHeatmap($first));
    show('Play data', $stream->getVideoPlayData($first));
}

if (! writes()) {
    exit(PHP_EOL.'Set BUNNY_EXAMPLES_WRITE=1 to create collections and upload videos.'.PHP_EOL);
}

/*
 * Collections
 */
$collection = $stream->createCollection('Docs examples');
$stream->setStreamCollectionGuid($collection['guid']);
$stream->updateCollection('Docs examples (renamed)');
show('Collection', $stream->getStreamForCollection());

/*
 * Upload a local file: create the video object first, then PUT the file
 */
$file = $argv[1] ?? null;
if ($file !== null && is_file($file)) {
    $video = $stream->createVideoForCollection('Uploaded from docs', thumbnail_time: 5_000);
    $stream->uploadVideo($video['guid'], $file, [
        'enabledResolutions' => '360p,720p,1080p',
        'transcribeEnabled' => true,
    ]);
    show('Uploaded', $video['guid']);
}

/*
 * Or let Bunny fetch a video from a URL (optionally with request headers)
 */
$fetched = $stream->fetchVideo(
    'https://example.com/videos/intro.mp4',
    collection_id: $collection['guid'],
    title: 'Fetched intro',
    headers: ['Authorization' => 'Bearer secret'],
);
show('Fetch queued', $fetched);

/*
 * Working with a video
 */
if (isset($first)) {
    $stream->updateVideo($first, [
        'title' => 'Renamed from docs',
        'chapters' => [['title' => 'Intro', 'start' => 0, 'end' => 30]],
        'metaTags' => [['property' => 'description', 'value' => 'Updated by the docs example']],
    ]);
    $stream->setThumbnail($first, 'https://example.com/thumbnail.jpg');
    $stream->addCaptions($first, 'en', 'English', "WEBVTT\n\n00:00.000 --> 00:02.000\nHello!\n");  // file path or contents
    $stream->transcribeVideo($first, target_languages: ['en', 'de'], source_language: 'en');
    // $stream->reEncodeVideo($first);
    // $stream->repackageVideo($first, keep_original_files: true);
    // $stream->cleanupVideoResolutions($first, ['resolutionsToDelete' => '240p', 'dryRun' => true]);
    $stream->deleteCaptions($first, 'en');
}

/*
 * Cleanup
 */
foreach ($stream->listVideosForCollectionId()['items'] ?? [] as $video) {
    $stream->deleteVideo($video['guid']);
}
$stream->deleteCollection();
show('Done');
