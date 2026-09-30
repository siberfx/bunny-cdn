<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn;

use Siberfx\BunnyCdn\Http\CurlHttpClient;
use Siberfx\BunnyCdn\Http\HttpClient;
use Siberfx\BunnyCdn\Http\HttpResponse;

class BunnyAPI
{
    public const string API_URL = 'https://api.bunny.net/';//URL for bunny.net core API
    public const string VIDEO_STREAM_URL = 'https://video.bunnycdn.com/';//URL for Bunny Stream API

    protected HttpClient $http;
    protected ?HttpResponse $last_response = null;

    public function __construct(
        protected string $api_key = '',
        protected string $stream_library_access_key = '',
        ?HttpClient $http = null,
    ) {
        $this->http = $http ?? new CurlHttpClient();
    }

    public function apiKey(string $api_key): static
    {
        if (trim($api_key) === '') {
            throw new BunnyAPIException('$api_key cannot be empty');
        }
        $this->api_key = $api_key;
        return $this;
    }

    public function streamLibraryAccessKey(string $stream_library_access_key): static
    {
        if (trim($stream_library_access_key) === '') {
            throw new BunnyAPIException('$stream_library_access_key cannot be empty');
        }
        $this->stream_library_access_key = $stream_library_access_key;
        return $this;
    }

    public function setHttpClient(HttpClient $http): static
    {
        $this->http = $http;
        return $this;
    }

    /** The raw response of the most recent request (useful for debugging) */
    public function lastResponse(): ?HttpResponse
    {
        return $this->last_response;
    }

    /**
     * Call the core API (https://api.bunny.net) authenticated with the account API key.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     */
    protected function APIcall(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $this->assertKey($this->api_key, 'API key', 'apiKey()');
        return $this->decode($this->jsonRequest($method, self::API_URL . $path, $this->api_key, $query, $json));
    }

    /**
     * Call the Stream API (https://video.bunnycdn.com) authenticated with the stream library API key.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     */
    protected function streamCall(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $this->assertKey($this->stream_library_access_key, 'stream library API key', 'streamLibraryAccessKey()');
        return $this->decode($this->jsonRequest($method, self::VIDEO_STREAM_URL . $path, $this->stream_library_access_key, $query, $json));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     */
    protected function jsonRequest(string $method, string $url, string $access_key, array $query = [], ?array $json = null): HttpResponse
    {
        $headers = ['AccessKey' => $access_key, 'Accept' => 'application/json'];
        $body = null;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json === [] ? new \stdClass() : $json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        return $this->send($method, $url . self::buildQuery($query), $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     * @param string|resource|null $body
     */
    protected function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse
    {
        $response = $this->http->send($method, $url, $headers, $body);
        $this->last_response = $response;
        if (!$response->successful()) {
            throw BunnyAPIException::fromResponse($method, $url, $response);
        }
        return $response;
    }

    protected function decode(HttpResponse $response): array
    {
        $data = $response->json();
        if (is_array($data)) {
            return $data;
        }
        return ['http_code' => $response->status, 'response' => $data];
    }

    /** @param array<string, mixed> $query */
    protected static function buildQuery(array $query): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);
        if ($query === []) {
            return '';
        }
        $query = array_map(static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $query);
        return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    protected function assertKey(string $key, string $name, string $setter): void
    {
        if (trim($key) === '') {
            throw new BunnyAPIException("You must provide a $name. Pass it to the constructor or use $setter");
        }
    }

    public function purgeCache(string $url, bool $async = false, bool $exact_path = false): array
    {
        return $this->APIcall('POST', 'purge', ['url' => $url, 'async' => $async, 'exactPath' => $exact_path]);
    }

    public function convertBytes(int $bytes, string $convert_to = 'GB', bool $format = true, int $decimals = 2): float|int|string
    {
        $value = match ($convert_to) {
            'GB' => $bytes / 1073741824,
            'MB' => $bytes / 1048576,
            'KB' => $bytes / 1024,
            default => $bytes,
        };
        if ($format) {
            return number_format($value, $decimals);
        }
        return $value;
    }

    /**
     * @param string|null $date_from ISO 8601 date, e.g. 2026-09-01
     * @param string|null $date_to ISO 8601 date
     * @param array<string, mixed> $options Extra query flags, e.g. ['loadErrors' => true, 'loadOriginTraffic' => true]
     */
    public function getStatistics(?int $pullzone_id = null, ?int $serverzone_id = null, bool $hourly = false, ?string $date_from = null, ?string $date_to = null, array $options = []): array
    {
        return $this->APIcall('GET', 'statistics', array_merge([
            'dateFrom' => $date_from,
            'dateTo' => $date_to,
            'pullZone' => $pullzone_id,
            'serverZoneId' => $serverzone_id,
            'hourly' => $hourly,
        ], $options));
    }

    public function getBilling(): array
    {
        return $this->APIcall('GET', 'billing');
    }

    public function getBillingSummary(): array
    {
        return $this->APIcall('GET', 'billing/summary');
    }

    public function getAffiliate(): array
    {
        return $this->APIcall('GET', 'billing/affiliate');
    }

    public function claimAffiliate(): array
    {
        return $this->APIcall('POST', 'billing/affiliate/claim');
    }

    public function balance(): float
    {
        return (float)$this->getBilling()['Balance'];
    }

    public function monthCharges(): float
    {
        return (float)$this->getBilling()['ThisMonthCharges'];
    }

    public function totalBillingAmount(bool $format = false, int $decimals = 2): array
    {
        $records = $this->getBilling()['BillingRecords'] ?? [];
        $tally = 0.0;
        $since = null;
        foreach ($records as $charge) {
            $tally += $charge['Amount'];
            $since = str_replace('T', ' ', (string)$charge['Timestamp']);
        }
        return ['amount' => $format ? round($tally, $decimals) : $tally, 'since' => $since];
    }

    public function monthChargeBreakdown(): array
    {
        $ar = $this->getBilling();
        return [
            'storage' => $ar['MonthlyChargesStorage'] ?? null,
            'EU' => $ar['MonthlyChargesEUTraffic'] ?? null,
            'US' => $ar['MonthlyChargesUSTraffic'] ?? null,
            'ASIA' => $ar['MonthlyChargesASIATraffic'] ?? null,
            'SA' => $ar['MonthlyChargesSATraffic'] ?? null,
            'AF' => $ar['MonthlyChargesAFTraffic'] ?? null,
        ];
    }

    public function getCountries(): array
    {
        return $this->APIcall('GET', 'country');
    }

    public function getRegions(): array
    {
        return $this->APIcall('GET', 'region');
    }

    public function getAbuseCases(): array
    {
        return $this->APIcall('GET', 'abusecase');
    }

    public function getAbuseCase(int $id): array
    {
        return $this->APIcall('GET', "abusecase/$id");
    }

    public function checkAbuseCase(int $id): array
    {
        return $this->APIcall('POST', "abusecase/$id/check");
    }

    public function costCalculator(int $bytes): array
    {
        $zone1 = 0.01;
        $zone2 = 0.03;
        $zone3 = 0.045;
        $zone4 = 0.06;
        $s500t = 0.005;
        $s1pb = 0.004;
        $s2pb = 0.003;
        $s2pb_plus = 0.0025;
        $gigabytes = $bytes / 1073741824;
        $terabytes = $gigabytes / 1024;
        return [
            'bytes' => $bytes,
            'gigabytes' => $gigabytes,
            'terabytes' => $terabytes,
            'EU_NA' => ($zone1 * $gigabytes),
            'ASIA_OC' => ($zone2 * $gigabytes),
            'SOUTH_AMERICA' => ($zone3 * $gigabytes),
            'MIDDLE_EAST_AFRICA' => ($zone4 * $gigabytes),
            'storage_500tb' => sprintf('%f', ($s500t * $terabytes)),
            'storage_500tb_1PB' => sprintf('%f', ($s1pb * $terabytes)),
            'storage_1PB_2PB' => sprintf('%f', ($s2pb * $terabytes)),
            'storage_2PB_PLUS' => sprintf('%f', ($s2pb_plus * $terabytes)),
        ];
    }
}
