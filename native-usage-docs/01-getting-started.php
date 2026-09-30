<?php

declare(strict_types=1);

/*
 * Getting started: creating clients, keys, responses and errors.
 *
 *   BUNNY_API_KEY=... php native-usage-docs/01-getting-started.php
 */

require __DIR__.'/bootstrap.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPI;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIException;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIPull;
use Siberfx\BunnyCdn\BunnyCore\Http\CurlHttpClient;

/*
 * 1. Every client takes the same constructor: account API key, stream library key, optional HTTP client.
 *    BunnyAPI (account), BunnyAPIPull, BunnyAPIStorage, BunnyAPIStream and BunnyAPIDNS all extend BunnyAPI.
 */
$bunny = new BunnyAPI(api_key: envVar('BUNNY_API_KEY'));

// Keys can be set or swapped later; setters are chainable
$pull = new BunnyAPIPull()
    ->apiKey(envVar('BUNNY_API_KEY'))
    ->streamLibraryAccessKey(envVar('BUNNY_STREAM_ACCESS_KEY', 'not-needed-here'));

/*
 * 2. Responses are decoded JSON arrays. Empty responses (HTTP 204) come back as ['http_code' => 204, 'response' => null].
 */
show('Regions', array_slice($bunny->getRegions(), 0, 3));

// The raw response of the last request is always available
$last = $bunny->lastResponse();
show('Last response', ['status' => $last?->status, 'bytes' => strlen($last->body ?? '')]);

/*
 * 3. Every non-2xx response throws BunnyAPIException with the HTTP status and the decoded body.
 */
try {
    $pull->getPullZone(0);
} catch (BunnyAPIException $e) {
    show('Error handling', [
        'status' => $e->getStatusCode(),     // e.g. 404
        'body' => $e->getResponseBody(),     // decoded JSON (or raw string)
        'message' => $e->getMessage(),
    ]);
}

// Missing keys are reported before any request is sent
try {
    new BunnyAPI()->getBilling();
} catch (BunnyAPIException $e) {
    show('Missing key', $e->getMessage());
}

/*
 * 4. Timeouts: pass your own CurlHttpClient (or any Http\HttpClient implementation, see 10-custom-http-client.php).
 */
$patient = new BunnyAPI(envVar('BUNNY_API_KEY'), http: new CurlHttpClient(timeout: 60, connect_timeout: 5));
show('Countries (custom timeouts)', count($patient->getCountries()).' countries');

/*
 * 5. Helpers that need no API call
 */
show('convertBytes', [
    '5 GiB as GB' => $bunny->convertBytes(5_368_709_120),               // "5.00"
    '5 GiB as MB (raw)' => $bunny->convertBytes(5_368_709_120, 'MB', false), // 5120
]);
show('costCalculator (1 TB)', $bunny->costCalculator(1_099_511_627_776));
