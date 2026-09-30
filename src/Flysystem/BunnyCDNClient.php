<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Flysystem;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;
use Siberfx\BunnyCdn\Flysystem\Exceptions\BunnyCDNException;
use Siberfx\BunnyCdn\Flysystem\Exceptions\NotFoundException;

/**
 * Minimal Guzzle based Edge Storage client used by the Flysystem adapter.
 */
class BunnyCDNClient
{
    public Guzzle $guzzleClient;

    public function __construct(
        public readonly string $storage_zone_name,
        private readonly string $api_key,
        private readonly string $region = BunnyCDNRegion::FALKENSTEIN,
    ) {
        $this->guzzleClient = new Guzzle(['handler' => HandlerStack::create(new CurlHandler())]);
    }

    private static function baseUrl(string $region): string
    {
        $hosts = BunnyAPIStorage::REGION_HOSTNAMES;

        return 'https://'.($hosts[strtolower($region)] ?? $hosts[BunnyCDNRegion::DEFAULT]).'/';
    }

    /**
     * @param array<string, string|int> $headers
     */
    public function createRequest(string $path, string $method = 'GET', array $headers = [], mixed $body = null): Request
    {
        return new Request(
            $method,
            self::baseUrl($this->region).Util::normalizePath('/'.$this->storage_zone_name.'/'.$path),
            [
                'Accept' => '*/*',
                'AccessKey' => $this->api_key,
                ...$headers,
            ],
            $body
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>|string Decoded JSON arrays, anything else as the raw body
     *
     * @throws GuzzleException
     */
    private function request(Request $request, array $options = []): array|string
    {
        $contents = $this->guzzleClient->send($request, $options)->getBody()->getContents();
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : $contents;
    }

    private static function translate(GuzzleException $e, ?string $on400 = null): BunnyCDNException
    {
        return match (true) {
            $e->getCode() === 404 => new NotFoundException($e->getMessage()),
            $e->getCode() === 400 && $on400 !== null => new BunnyCDNException($on400),
            default => new BunnyCDNException($e->getMessage()),
        };
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws NotFoundException|BunnyCDNException
     */
    public function list(string $path): array
    {
        try {
            $listing = $this->request($this->createRequest(Util::normalizePath($path).'/'));
        } catch (GuzzleException $e) {
            throw self::translate($e);
        }

        if (! is_array($listing)) {
            throw new NotFoundException('File is not a directory');
        }

        return $listing;
    }

    /**
     * @throws NotFoundException|BunnyCDNException
     */
    public function download(string $path): string
    {
        try {
            $content = $this->request($this->createRequest($path.'?download'));
        } catch (GuzzleException $e) {
            throw self::translate($e);
        }

        return is_array($content) ? (string) json_encode($content) : $content;
    }

    /**
     * @return resource|null
     *
     * @throws NotFoundException|BunnyCDNException
     */
    public function stream(string $path)
    {
        try {
            return $this->guzzleClient->send($this->createRequest($path), ['stream' => true])->getBody()->detach();
        } catch (GuzzleException $e) {
            throw self::translate($e);
        }
    }

    /**
     * @param string|resource $contents
     */
    public function getUploadRequest(string $path, mixed $contents): Request
    {
        $headers = ['Content-Type' => 'application/octet-stream'];

        if (is_resource($contents) && isset(fstat($contents)['size'])) {
            $headers['Content-Length'] = fstat($contents)['size'];
        }

        return $this->createRequest($path, 'PUT', $headers, $contents);
    }

    /**
     * @param string|resource $contents
     * @return array<mixed>|string
     *
     * @throws BunnyCDNException
     */
    public function upload(string $path, mixed $contents): array|string
    {
        try {
            return $this->request($this->getUploadRequest($path, $contents), [
                'connect_timeout' => 5,
                'timeout' => 60 * 60,
                'expect' => true,
            ]);
        } catch (GuzzleException $e) {
            throw new BunnyCDNException($e->getMessage());
        }
    }

    /**
     * @return array<mixed>|string
     *
     * @throws BunnyCDNException
     */
    public function make_directory(string $path): array|string
    {
        try {
            return $this->request($this->createRequest(Util::normalizePath($path).'/', 'PUT', ['Content-Length' => 0]));
        } catch (GuzzleException $e) {
            throw self::translate($e, on400: 'Directory already exists');
        }
    }

    /**
     * @return array<mixed>|string
     *
     * @throws NotFoundException|BunnyCDNException
     */
    public function delete(string $path): array|string
    {
        try {
            return $this->request($this->createRequest($path, 'DELETE'));
        } catch (GuzzleException $e) {
            throw self::translate($e);
        }
    }
}
