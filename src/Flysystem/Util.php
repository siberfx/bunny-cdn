<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Flysystem;

final class Util
{
    /**
     * Split a path into its file (last segment) and directory.
     *
     * @return array{file: string, dir: string}
     */
    public static function splitPathIntoDirectoryAndFile(string $path): array
    {
        $segments = explode('/', str_ends_with($path, '/') ? substr($path, 0, -1) : $path);
        $file = (string) array_pop($segments);

        return ['file' => $file, 'dir' => implode('/', $segments)];
    }

    /**
     * Use forward slashes, collapse duplicate slashes and drop the leading slash.
     */
    public static function normalizePath(string $path, bool $isDirectory = false): string
    {
        $path = str_replace('\\', '/', $path);

        if ($isDirectory && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return ltrim((string) preg_replace('#/{2,}#', '/', $path), '/');
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $position = strpos($subject, $search);

        return $position === false ? $subject : substr_replace($subject, $replace, $position, strlen($search));
    }
}
