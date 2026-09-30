<?php

declare(strict_types=1);

/*
 * Shared setup for the native (framework-free) usage examples.
 *
 * Every example reads its keys from environment variables and only runs calls that change something
 * (create / update / delete / upload) when BUNNY_EXAMPLES_WRITE=1 is set. Without it the examples are read-only.
 */

require __DIR__.'/../vendor/autoload.php';

/** Read a required (or optional, when $default is given) environment variable. */
function envVar(string $name, ?string $default = null): string
{
    $value = getenv($name);

    if (is_string($value) && $value !== '') {
        return $value;
    }

    return $default ?? throw new RuntimeException("Set the $name environment variable to run this example.");
}

/** True when calls that change your account are allowed (BUNNY_EXAMPLES_WRITE=1). */
function writes(): bool
{
    return getenv('BUNNY_EXAMPLES_WRITE') === '1';
}

/** Print a titled section of output. */
function show(string $title, mixed $value = null): void
{
    echo PHP_EOL, "── $title", PHP_EOL;

    if ($value !== null) {
        echo is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
}
