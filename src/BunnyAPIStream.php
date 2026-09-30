<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

class BunnyAPIStream extends BunnyAPI
{
    private int $stream_library_id;
    private string $stream_collection_guid;
    private string $stream_video_guid;

    //Stream library -> collection -> video
    public function setStreamLibraryId(int $library_id): static
    {
        $this->stream_library_id = $library_id;
        return $this;
    }

    public function setStreamCollectionGuid(string $collection_guid): static
    {
        $this->stream_collection_guid = $collection_guid;
        return $this;
    }

    public function setStreamVideoGuid(string $video_guid): static
    {
        $this->stream_video_guid = $video_guid;
        return $this;
    }

    protected function library(): string
    {
        if (!isset($this->stream_library_id)) {
            throw new BunnyAPIException('You must set the stream library id first. Use setStreamLibraryId()');
        }
        return "library/{$this->stream_library_id}";
    }

    protected function collection(): string
    {
        if (!isset($this->stream_collection_guid)) {
            throw new BunnyAPIException('You must set the stream collection guid first. Use setStreamCollectionGuid()');
        }
        return $this->stream_collection_guid;
    }

    protected function video(?string $video_guid): string
    {
        $video_guid ??= $this->stream_video_guid ?? null;
        if ($video_guid === null) {
            throw new BunnyAPIException('You must pass a video guid or use setStreamVideoGuid()');
        }
        return $this->library() . '/videos/' . rawurlencode($video_guid);
    }

    /*
     * Collections
     */

    public function getStreamCollections(int $page = 1, int $items_pp = 100, string $order_by = 'date', ?string $search = null, bool $include_thumbnails = false): array
    {
        return $this->streamCall('GET', $this->library() . '/collections', [
            'page' => $page, 'itemsPerPage' => $items_pp, 'search' => $search, 'orderBy' => $order_by, 'includeThumbnails' => $include_thumbnails,
        ]);
    }

    public function getVideoCollections(int $page = 1, int $items_per_page = 100): array
    {
        return $this->getStreamCollections($page, $items_per_page);
    }

    public function getStreamForCollection(bool $include_thumbnails = false): array
    {
        return $this->streamCall('GET', $this->library() . '/collections/' . $this->collection(), ['includeThumbnails' => $include_thumbnails]);
    }

    public function getStreamCollectionSize(): int
    {
        return (int)$this->getStreamForCollection()['totalSize'];
    }

    public function updateCollection(string $updated_collection_name): array
    {
        return $this->streamCall('POST', $this->library() . '/collections/' . $this->collection(), [], ['name' => $updated_collection_name]);
    }

    public function deleteCollection(): array
    {
        return $this->streamCall('DELETE', $this->library() . '/collections/' . $this->collection());
    }

    public function createCollection(string $new_collection_name): array
    {
        return $this->streamCall('POST', $this->library() . '/collections', [], ['name' => $new_collection_name]);
    }

    /*
     * Videos
     */

    public function listVideos(int $page = 1, int $items_pp = 100, string $order_by = 'date', ?string $search = null, ?string $collection = null): array
    {
        return $this->streamCall('GET', $this->library() . '/videos', [
            'page' => $page, 'itemsPerPage' => $items_pp, 'search' => $search, 'collection' => $collection, 'orderBy' => $order_by,
        ]);
    }

    public function listVideosForCollectionId(int $page = 1, int $items_pp = 100, string $order_by = 'date', ?string $search = null): array
    {
        return $this->listVideos($page, $items_pp, $order_by, $search, $this->collection());
    }

    /**
     * @param string|null $video_guid Only return statistics for this video
     */
    public function getVideoStatistics(?string $date_from = null, ?string $date_to = null, bool $hourly = false, ?string $video_guid = null): array
    {
        return $this->streamCall('GET', $this->library() . '/statistics', [
            'dateFrom' => $date_from, 'dateTo' => $date_to, 'hourly' => $hourly, 'videoGuid' => $video_guid,
        ]);
    }

    public function getVideoHeatmap(?string $video_guid = null): array
    {
        return $this->streamCall('GET', $this->video($video_guid) . '/heatmap');
    }

    public function getVideoPlayData(?string $video_guid = null, ?string $token = null, ?int $expires = null): array
    {
        return $this->streamCall('GET', $this->video($video_guid) . '/play', ['token' => $token, 'expires' => $expires]);
    }

    public function getVideo(?string $video_guid = null): array
    {
        return $this->streamCall('GET', $this->video($video_guid));
    }

    /**
     * @param array<string, mixed> $args title, collectionId, chapters, moments, metaTags
     */
    public function updateVideo(string $video_guid, array $args): array
    {
        return $this->streamCall('POST', $this->video($video_guid), [], $args);
    }

    public function deleteVideo(?string $video_guid = null): array
    {
        return $this->streamCall('DELETE', $this->video($video_guid));
    }

    /** Returns array containing a GUID which is needed to PUT the video file */
    public function createVideo(string $video_title, ?string $collection_id = null, ?int $thumbnail_time = null): array
    {
        $body = array_filter(['title' => $video_title, 'collectionId' => $collection_id, 'thumbnailTime' => $thumbnail_time], static fn ($v) => $v !== null);
        return $this->streamCall('POST', $this->library() . '/videos', [], $body);
    }

    /** Returns array containing a GUID which is needed to PUT the video file */
    public function createVideoForCollection(string $video_title, ?int $thumbnail_time = null): array
    {
        return $this->createVideo($video_title, $this->collection(), $thumbnail_time);
    }

    /**
     * Upload the video file. Use createVideo() first to get the video guid.
     *
     * @param array<string, mixed> $options e.g. ['enabledResolutions' => '720p,1080p', 'transcribeEnabled' => true]
     */
    public function uploadVideo(string $video_guid, string $video_to_upload, array $options = []): array
    {
        $this->assertKey($this->stream_library_access_key, 'stream library API key', 'streamLibraryAccessKey()');
        $stream = @fopen($video_to_upload, 'rb');
        if ($stream === false) {
            throw new BunnyAPIException("Unable to open file $video_to_upload");
        }
        try {
            $url = self::VIDEO_STREAM_URL . $this->video($video_guid) . self::buildQuery($options);
            return $this->decode($this->send('PUT', $url, [
                'AccessKey' => $this->stream_library_access_key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/octet-stream',
            ], $stream));
        } finally {
            fclose($stream);
        }
    }

    public function setThumbnail(string $video_guid, string $thumbnail_url): array
    {
        return $this->streamCall('POST', $this->video($video_guid) . '/thumbnail', ['thumbnailUrl' => $thumbnail_url]);
    }

    /**
     * @param string $captions_file Path to a caption file (e.g. .vtt/.srt) or the raw caption contents
     */
    public function addCaptions(string $video_guid, string $srclang, string $label, string $captions_file): array
    {
        $contents = is_file($captions_file) ? (string)file_get_contents($captions_file) : $captions_file;
        return $this->streamCall('POST', $this->video($video_guid) . '/captions/' . rawurlencode($srclang), [], [
            'label' => $label,
            'captionsFile' => base64_encode($contents),
        ]);
    }

    public function deleteCaptions(string $video_guid, string $srclang): array
    {
        return $this->streamCall('DELETE', $this->video($video_guid) . '/captions/' . rawurlencode($srclang));
    }

    public function reEncodeVideo(string $video_guid): array
    {
        return $this->streamCall('POST', $this->video($video_guid) . '/reencode');
    }

    public function repackageVideo(string $video_guid, bool $keep_original_files = true): array
    {
        return $this->streamCall('POST', $this->video($video_guid) . '/repackage', ['keepOriginalFiles' => $keep_original_files]);
    }

    /**
     * @param list<string> $target_languages e.g. ['en', 'de']
     * @param array<string, mixed> $options generateTitle, generateDescription, generateChapters, generateMoments
     */
    public function transcribeVideo(string $video_guid, array $target_languages = [], ?string $source_language = null, bool $force = false, array $options = []): array
    {
        $body = array_merge(array_filter(['targetLanguages' => $target_languages, 'sourceLanguage' => $source_language], static fn ($v) => $v !== null && $v !== []), $options);
        return $this->streamCall('POST', $this->video($video_guid) . '/transcribe', ['force' => $force], $body);
    }

    public function getVideoResolutions(string $video_guid): array
    {
        return $this->streamCall('GET', $this->video($video_guid) . '/resolutions');
    }

    /**
     * @param array<string, mixed> $options resolutionsToDelete, deleteNonConfiguredResolutions, allResolutions, deleteOriginal, deleteMp4Files, dryRun
     */
    public function cleanupVideoResolutions(string $video_guid, array $options = []): array
    {
        return $this->streamCall('POST', $this->video($video_guid) . '/resolutions/cleanup', $options);
    }

    /**
     * Downloads a video from a URL into the stream library / collection.
     *
     * @param array<string, string> $headers Headers sent when fetching $video_url
     */
    public function fetchVideo(string $video_url, ?string $collection_id = null, ?string $title = null, array $headers = [], ?int $thumbnail_time = null): array
    {
        $body = array_filter(['url' => $video_url, 'title' => $title, 'headers' => $headers ?: null], static fn ($v) => $v !== null);
        return $this->streamCall('POST', $this->library() . '/videos/fetch', ['collectionId' => $collection_id, 'thumbnailTime' => $thumbnail_time], $body);
    }

    public function videoResolutionsArray(string $video_guid): array
    {
        $resolutions = (string)($this->getVideo($video_guid)['availableResolutions'] ?? '');
        return $resolutions === '' ? [] : explode(',', $resolutions);
    }

    public function videoSize(string $video_guid, string $size_type = 'MB', bool $format = false, int $decimals = 2): float|int|string
    {
        return $this->convertBytes((int)$this->getVideo($video_guid)['storageSize'], $size_type, $format, $decimals);
    }
}
