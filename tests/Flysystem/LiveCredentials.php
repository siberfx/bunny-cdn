<?php

namespace Siberfx\BunnyCdn\Tests\Flysystem;

/**
 * Loads optional live storage zone credentials from tests/Flysystem/ClientDI.php (git ignored).
 */
final class LiveCredentials
{
    public static function load(): void
    {
        if (\is_file(__DIR__.'/ClientDI.php')) {
            require_once __DIR__.'/ClientDI.php';
        }
    }
}
