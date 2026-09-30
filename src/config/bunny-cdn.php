<?php

return [

    // Account API key (Dashboard -> Account settings -> API)
    'api_key' => env('BUNNY_API_KEY', ''),

    'storage' => [
        // Storage zone name and password (FTP & API access -> Password)
        'zone' => env('BUNNY_STORAGE_ZONE', ''),
        'access_key' => env('BUNNY_STORAGE_ACCESS_KEY', ''),
        // Primary storage region: de, uk, se, ny, la, sg, syd, br, jh
        'region' => env('BUNNY_STORAGE_REGION', 'de'),
    ],

    'stream' => [
        'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
        // Stream library API key (Stream -> library -> API)
        'access_key' => env('BUNNY_STREAM_ACCESS_KEY', ''),
    ],

];
