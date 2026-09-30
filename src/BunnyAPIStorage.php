<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

use FTP\Connection;

class BunnyAPIStorage extends BunnyAPI
{
    /** Storage region code => Edge Storage hostname (same host for HTTP and FTP) */
    public const array REGION_HOSTNAMES = [
        'de' => 'storage.bunnycdn.com',
        'uk' => 'uk.storage.bunnycdn.com',
        'se' => 'se.storage.bunnycdn.com',
        'ny' => 'ny.storage.bunnycdn.com',
        'la' => 'la.storage.bunnycdn.com',
        'sg' => 'sg.storage.bunnycdn.com',
        'syd' => 'syd.storage.bunnycdn.com',
        'br' => 'br.storage.bunnycdn.com',
        'jh' => 'jh.storage.bunnycdn.com',
    ];

    public const int ZONE_TIER_STANDARD = 0;
    public const int ZONE_TIER_EDGE = 1;

    protected string $storage_name;
    protected string $access_key = '';
    protected string $region = 'de';
    protected ?Connection $connection = null;

    /**
     * Select the storage zone used by the Edge Storage (HTTP) methods.
     * When $access_key is empty it is looked up with the account API key.
     */
    public function setStorageZone(string $storage_name, string $access_key = '', string $region = 'de'): static
    {
        $region = strtolower($region);
        if (!isset(self::REGION_HOSTNAMES[$region])) {
            throw new BunnyAPIException("Unknown storage region '$region'. Use one of: " . implode(', ', array_keys(self::REGION_HOSTNAMES)));
        }
        $this->storage_name = $storage_name;
        $this->region = $region;
        $this->access_key = $access_key !== '' ? $access_key : ($this->findStorageZoneAccessKey($storage_name)
            ?? throw new BunnyAPIException("Could not find an access key for storage zone '$storage_name'"));
        return $this;
    }

    /**
     * Select the storage zone and open an FTP connection (needed for the FTP based methods, requires ext-ftp).
     */
    public function zoneConnect(string $storage_name, string $access_key = '', string $region = 'de'): static
    {
        $this->setStorageZone($storage_name, $access_key, $region);
        if (!extension_loaded('ftp')) {
            throw new BunnyAPIException('The ftp extension is required for FTP storage methods');
        }
        $conn_id = ftp_connect($this->storageHostname());
        if ($conn_id === false) {
            throw new BunnyAPIException('Could not make FTP connection to ' . $this->storageHostname());
        }
        if (!@ftp_login($conn_id, $storage_name, $this->access_key)) {
            ftp_close($conn_id);
            throw new BunnyAPIException("FTP login failed for storage zone '$storage_name'");
        }
        ftp_pasv($conn_id, true);
        $this->connection = $conn_id;
        return $this;
    }

    public function storageHostname(): string
    {
        return self::REGION_HOSTNAMES[$this->region];
    }

    protected function findStorageZoneAccessKey(string $storage_name): ?string
    {
        $data = $this->listStorageZones(search: $storage_name);
        foreach ($data['Items'] ?? $data as $zone) {
            if (($zone['Name'] ?? null) === $storage_name) {
                return $zone['Password'];
            }
        }
        return null;//Never found access key for said storage zone
    }

    /*
     * Storage zone management (core API, account API key)
     */

    public function listStorageZones(int $page = 0, int $per_page = 1000, bool $include_deleted = false, ?string $search = null): array
    {
        return $this->APIcall('GET', 'storagezone', ['page' => $page, 'perPage' => $per_page, 'includeDeleted' => $include_deleted, 'search' => $search]);
    }

    public function getStorageZone(int $id): array
    {
        return $this->APIcall('GET', "storagezone/$id");
    }

    /**
     * @param list<string> $replicated_regions e.g. ['NY', 'SG']
     * @param array<string, mixed> $args Extra fields, e.g. ['StorageZoneType' => 1]
     */
    public function addStorageZone(string $name, string $main_region = 'DE', array $replicated_regions = [], int $zone_tier = self::ZONE_TIER_STANDARD, array $args = []): array
    {
        return $this->APIcall('POST', 'storagezone', json: array_merge([
            'Name' => $name,
            'Region' => strtoupper($main_region),
            'ReplicationRegions' => array_map(strtoupper(...), $replicated_regions),
            'ZoneTier' => $zone_tier,
        ], $args));
    }

    /**
     * @param array<string, mixed> $args e.g. ['OriginUrl' => ..., 'ReplicationZones' => [...], 'Custom404FilePath' => ..., 'Rewrite404To200' => true]
     */
    public function updateStorageZone(int $id, array $args): array
    {
        return $this->APIcall('POST', "storagezone/$id", json: $args);
    }

    /**
     * Note: the API deletes linked pull zones by default; this client only does so when asked.
     */
    public function deleteStorageZone(int $id, bool $delete_linked_pull_zones = false): array
    {
        return $this->APIcall('DELETE', "storagezone/$id", ['deleteLinkedPullZones' => $delete_linked_pull_zones]);
    }

    public function getStorageZoneStatistics(int $id, ?string $date_from = null, ?string $date_to = null): array
    {
        return $this->APIcall('GET', "storagezone/$id/statistics", ['dateFrom' => $date_from, 'dateTo' => $date_to]);
    }

    public function resetStorageZonePassword(int $id): array
    {
        return $this->APIcall('POST', "storagezone/$id/resetPassword");
    }

    public function resetStorageZoneReadOnlyPassword(int $id): array
    {
        return $this->APIcall('POST', 'storagezone/resetReadOnlyPassword', ['id' => $id]);
    }

    public function checkStorageZoneAvailability(string $name): array
    {
        return $this->APIcall('POST', 'storagezone/checkavailability', json: ['Name' => $name]);
    }

    public function getStorageRegions(): array
    {
        return $this->APIcall('GET', 'storagezone/regions');
    }

    /*
     * Edge Storage HTTP API (storage zone password)
     */

    protected function storageUrl(string $path): string
    {
        if (!isset($this->storage_name)) {
            throw new BunnyAPIException('You must select a storage zone first. Use setStorageZone() or zoneConnect()');
        }
        $segments = array_map(rawurlencode(...), explode('/', trim($path, '/')));
        $url = 'https://' . $this->storageHostname() . '/' . rawurlencode($this->storage_name) . '/' . implode('/', $segments);
        return rtrim($url, '/') . (str_ends_with($path, '/') || trim($path, '/') === '' ? '/' : '');
    }

    /** @param array<string, string> $headers */
    protected function storageRequest(string $method, string $path, array $headers = [], mixed $body = null): Http\HttpResponse
    {
        return $this->send($method, $this->storageUrl($path), array_merge(['AccessKey' => $this->access_key], $headers), $body);
    }

    /**
     * Upload a local file through the HTTP API. A SHA256 checksum is sent so Bunny can verify the upload.
     */
    public function uploadFileHTTP(string $file, string $save_as, bool $checksum = true, ?string $content_type = null): array
    {
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            throw new BunnyAPIException("Unable to open file $file");
        }
        $headers = ['Content-Type' => $content_type ?? 'application/octet-stream'];
        if ($checksum) {
            $headers['Checksum'] = strtoupper(hash_file('sha256', $file));
        }
        try {
            return $this->decode($this->storageRequest('PUT', ltrim($save_as, '/'), $headers, $stream));
        } finally {
            fclose($stream);
        }
    }

    /** Upload a string as a file through the HTTP API. */
    public function uploadContentHTTP(string $content, string $save_as, bool $checksum = true, ?string $content_type = null): array
    {
        $headers = ['Content-Type' => $content_type ?? 'application/octet-stream'];
        if ($checksum) {
            $headers['Checksum'] = strtoupper(hash('sha256', $content));
        }
        return $this->decode($this->storageRequest('PUT', ltrim($save_as, '/'), $headers, $content));
    }

    /** Delete a file, or a directory recursively when $file ends with a slash. */
    public function deleteFileHTTP(string $file): array
    {
        if (trim($file, '/') === '') {
            throw new BunnyAPIException('Refusing to delete the storage zone root');
        }
        return $this->decode($this->storageRequest('DELETE', $file));
    }

    /** Returns the file contents. */
    public function downloadFileHTTP(string $file): string
    {
        return $this->storageRequest('GET', ltrim($file, '/'), ['Accept' => '*/*'])->body;
    }

    /** Raw directory listing from the HTTP API. */
    public function listDirectory(string $path = ''): array
    {
        return $this->decode($this->storageRequest('GET', trim($path, '/') . '/', ['Accept' => 'application/json']));
    }

    public function listAllOG(): array
    {
        return $this->listDirectory();
    }

    public function listFiles(string $location = ''): array
    {
        $items = ['storage_name' => $this->storage_name ?? null, 'current_dir' => $location, 'data' => []];
        foreach ($this->listDirectory($location) as $value) {
            if ($value['IsDirectory'] === false) {
                $items['data'][] = [
                    'name' => $value['ObjectName'],
                    'file_type' => pathinfo($value['ObjectName'], PATHINFO_EXTENSION) ?: null,
                    'size' => $value['Length'] / 1024,
                    'created' => self::formatDate($value['DateCreated']),
                    'last_changed' => self::formatDate($value['LastChanged']),
                    'guid' => $value['Guid'],
                ];
            }
        }
        return $items;
    }

    public function listFolders(string $location = ''): array
    {
        $items = ['storage_name' => $this->storage_name ?? null, 'current_dir' => $location, 'data' => []];
        foreach ($this->listDirectory($location) as $value) {
            if ($value['IsDirectory'] === true) {
                $items['data'][] = [
                    'name' => $value['ObjectName'],
                    'created' => self::formatDate($value['DateCreated']),
                    'last_changed' => self::formatDate($value['LastChanged']),
                    'guid' => $value['Guid'],
                ];
            }
        }
        return $items;
    }

    public function listAll(string $location = ''): array
    {
        $items = ['storage_name' => $this->storage_name ?? null, 'current_dir' => $location, 'data' => []];
        foreach ($this->listDirectory($location) as $value) {
            $is_dir = $value['IsDirectory'] === true;
            $items['data'][] = [
                'name' => $value['ObjectName'],
                'file_type' => $is_dir ? null : (pathinfo($value['ObjectName'], PATHINFO_EXTENSION) ?: null),
                'size' => $is_dir ? null : $value['Length'] / 1024,
                'is_dir' => $is_dir,
                'created' => self::formatDate($value['DateCreated']),
                'last_changed' => self::formatDate($value['LastChanged']),
                'guid' => $value['Guid'],
            ];
        }
        return $items;
    }

    /** Delete every file (not sub folders) in a directory. */
    public function deleteAllFiles(string $dir): array
    {
        $files_deleted = 0;
        foreach ($this->listDirectory($dir) as $value) {
            if ($value['IsDirectory'] === false) {
                $this->deleteFileHTTP(trim($dir, '/') . '/' . $value['ObjectName']);
                $files_deleted++;
            }
        }
        return ['action' => __FUNCTION__, 'value' => $dir, 'files_deleted' => $files_deleted];
    }

    /** Download every file (not sub folders) of a directory into a local directory. */
    public function downloadAll(string $dir_dl_from = '', string $dl_into = ''): array
    {
        $files_downloaded = 0;
        $prefix = trim($dir_dl_from, '/');
        foreach ($this->listDirectory($dir_dl_from) as $value) {
            if ($value['IsDirectory'] === false) {
                $content = $this->downloadFileHTTP(($prefix === '' ? '' : "$prefix/") . $value['ObjectName']);
                if (file_put_contents($dl_into . $value['ObjectName'], $content) !== false) {
                    $files_downloaded++;
                }
            }
        }
        return ['action' => __FUNCTION__, 'files_downloaded' => $files_downloaded];
    }

    public function dirSize(string $dir = ''): array
    {
        $size = $files = 0;
        foreach ($this->listDirectory($dir) as $value) {
            if ($value['IsDirectory'] === false) {
                $size += $value['Length'];
                $files++;
            }
        }
        return ['dir' => $dir, 'files' => $files, 'size_b' => $size, 'size_kb' => number_format(($size / 1024), 3),
            'size_mb' => number_format(($size / 1048576), 3), 'size_gb' => number_format(($size / 1073741824), 3)];
    }

    protected static function formatDate(string $date): string
    {
        return date('Y-m-d H:i:s', (int)strtotime($date));
    }

    /*
     * FTP methods (require zoneConnect() and ext-ftp)
     */

    // Mirrors of the ext-ftp constants, usable when the extension is not loaded
    public const int FTP_BINARY = 2;
    protected const int FTP_FINISHED = 1;
    protected const int FTP_MOREDATA = 2;

    protected function ftp(): Connection
    {
        return $this->connection ?? throw new BunnyAPIException('No FTP connection. Use zoneConnect() first');
    }

    /** Calls an ftp_* function with the active connection as first argument. */
    protected function ftpCall(string $function, mixed ...$args): mixed
    {
        $connection = $this->ftp();
        return $function($connection, ...$args);
    }

    public function fileExists(string $file): bool
    {
        return $this->ftpCall('ftp_size', $file) >= 0;
    }

    public function folderExists(string $path): bool
    {
        return (bool)$this->ftpCall('ftp_nlist', $path);
    }

    public function createFolder(string $name): array
    {
        if (@!$this->ftpCall('ftp_chdir', $name)) {
            if (@$this->ftpCall('ftp_mkdir', $name)) {
                return ['response' => 'success', 'action' => __FUNCTION__, 'value' => $name];
            }
            return ['response' => 'fail', 'action' => __FUNCTION__, 'value' => $name, 'message' => "Failed to create directory $name"];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__, 'value' => $name, 'message' => "Directory $name already exists"];
    }

    public function deleteFolder(string $name): array
    {
        if (@$this->ftpCall('ftp_rmdir', $name)) {
            return ['response' => 'success', 'action' => __FUNCTION__, 'value' => $name];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__, 'value' => $name];
    }

    public function deleteFile(string $name): array
    {
        if (@$this->ftpCall('ftp_delete', $name)) {
            return ['response' => 'success', 'action' => __FUNCTION__, 'value' => $name];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__, 'value' => $name];
    }

    public function uploadAllFiles(string $dir, string $place, int $mode = self::FTP_BINARY): array
    {
        $files_uploaded = 0;
        foreach (scandir($dir) ?: [] as $file) {
            if (!is_dir("$dir/$file") && @$this->ftpCall('ftp_put', $place . $file, "$dir/$file", $mode)) {
                $files_uploaded++;
            }
        }
        return ['action' => __FUNCTION__, 'value' => $dir, 'files_uploaded' => $files_uploaded];
    }

    public function getFileSize(string $file): int
    {
        return $this->ftpCall('ftp_size', $file);
    }

    public function currentDir(): string
    {
        return (string)$this->ftpCall('ftp_pwd');
    }

    public function changeDir(string $moveto): array
    {
        if (@$this->ftpCall('ftp_chdir', $moveto)) {
            return ['response' => 'success', 'action' => __FUNCTION__, 'value' => $moveto];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__, 'value' => $moveto];
    }

    public function moveUpOne(): array
    {
        if (@$this->ftpCall('ftp_cdup')) {
            return ['response' => 'success', 'action' => __FUNCTION__];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__];
    }

    /** Copies $from to $to through a local temp file, then deletes $from (Bunny FTP has no rename). */
    protected function ftpMove(string $from, string $to): bool
    {
        $temp = tempnam(sys_get_temp_dir(), 'bunny');
        try {
            return @$this->ftpCall('ftp_get', $temp, $from, self::FTP_BINARY)
                && @$this->ftpCall('ftp_put', $to, $temp, self::FTP_BINARY)
                && $this->deleteFile($from)['response'] === 'success';
        } finally {
            @unlink($temp);
        }
    }

    public function renameFile(string $dir, string $file_name, string $new_file_name): array
    {
        $response = $this->ftpMove("{$dir}$file_name", "{$dir}$new_file_name") ? 'success' : 'fail';
        return ['response' => $response, 'action' => __FUNCTION__, 'old' => "{$dir}$file_name", 'new' => "{$dir}$new_file_name"];
    }

    public function moveFile(string $dir, string $file_name, string $move_to): array
    {
        $response = $this->ftpMove("{$dir}$file_name", "$move_to{$file_name}") ? 'success' : 'fail';
        return ['response' => $response, 'action' => __FUNCTION__, 'file' => "{$dir}$file_name", 'move_to' => $move_to];
    }

    public function downloadFile(string $save_as, string $get_file, int $mode = self::FTP_BINARY): array
    {
        if (@$this->ftpCall('ftp_get', $save_as, $get_file, $mode)) {
            return ['response' => 'success', 'action' => __FUNCTION__, 'file' => $get_file, 'save_as' => $save_as];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__, 'file' => $get_file, 'save_as' => $save_as];
    }

    public function downloadFileWithProgress(string $save_as, string $get_file, string $progress_file = 'DOWNLOAD_PERCENT.txt'): void
    {
        $size = max(1, $this->getFileSize($get_file));
        $out = fopen($save_as, 'wb');
        $status = $this->ftpCall('ftp_nb_fget', $out, $get_file, self::FTP_BINARY);
        while ($status === self::FTP_MOREDATA) {
            file_put_contents($progress_file, (string)(int)(ftell($out) / $size * 100));
            $status = $this->ftpCall('ftp_nb_continue');
        }
        fclose($out);
        if ($status !== self::FTP_FINISHED) {
            throw new BunnyAPIException("Failed to download $get_file");
        }
        file_put_contents($progress_file, '100');
    }

    public function uploadFile(string $upload, string $upload_as, int $mode = self::FTP_BINARY): array
    {
        if (@$this->ftpCall('ftp_put', $upload_as, $upload, $mode)) {
            return ['response' => 'success', 'action' => __FUNCTION__, 'file' => $upload, 'upload_as' => $upload_as];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__, 'file' => $upload, 'upload_as' => $upload_as];
    }

    public function uploadFileWithProgress(string $upload, string $upload_as, string $progress_file = 'UPLOAD_PERCENT.txt'): void
    {
        $size = max(1, (int)filesize($upload));
        $in = fopen($upload, 'rb');
        $status = $this->ftpCall('ftp_nb_fput', $upload_as, $in, self::FTP_BINARY);
        while ($status === self::FTP_MOREDATA) {
            file_put_contents($progress_file, (string)(int)(ftell($in) / $size * 100));
            $status = $this->ftpCall('ftp_nb_continue');
        }
        fclose($in);
        if ($status !== self::FTP_FINISHED) {
            throw new BunnyAPIException("Failed to upload $upload");
        }
        file_put_contents($progress_file, '100');
    }

    public function closeConnection(): array
    {
        if ($this->connection !== null && ftp_close($this->connection)) {
            $this->connection = null;
            return ['response' => 'success', 'action' => __FUNCTION__];
        }
        return ['response' => 'fail', 'action' => __FUNCTION__];
    }
}
