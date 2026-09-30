<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\BunnyCore\Http;

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIException;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeout = 0,
        private readonly int $connect_timeout = 10,
    ) {
    }

    public function send(string $method, string $url, array $headers = [], mixed $body = null): HttpResponse
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new BunnyAPIException('Unable to initialise cURL');
        }

        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = "$name: $value";
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
        ]);

        if (is_resource($body)) {
            $stat = fstat($body);
            curl_setopt_array($curl, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $body,
                CURLOPT_INFILESIZE => $stat['size'] ?? -1,
            ]);
        } elseif ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, (string)$body);
        }

        $result = curl_exec($curl);
        if ($result === false) {
            throw new BunnyAPIException('cURL error: ' . curl_error($curl));
        }

        return new HttpResponse((int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$result);
    }
}
