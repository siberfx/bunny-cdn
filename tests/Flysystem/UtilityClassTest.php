<?php

declare(strict_types=1);

use Siberfx\BunnyCdn\Flysystem\Util;

test('startsWith', function () {
    expect(Util::startsWith('/test', '/'))->toBeTrue()
        ->and(Util::startsWith('test', '/'))->toBeFalse();
});

test('endsWith', function () {
    expect(Util::endsWith('test/', '/'))->toBeTrue()
        ->and(Util::endsWith('test', '/'))->toBeFalse()
        ->and(Util::endsWith('test', ''))->toBeTrue();
});

test('normalizePath', function (string $path, bool $directory, string $expected) {
    expect(Util::normalizePath($path, $directory))->toBe($expected);
})->with([
    ['/test/', true, 'test/'],
    ['/test', true, 'test/'],
    ['/test', false, 'test'],
    ['a\\b//c', false, 'a/b/c'],
]);

test('splitPathIntoDirectoryAndFile', function (string $path, string $file, string $dir) {
    expect(Util::splitPathIntoDirectoryAndFile($path))->toBe(['file' => $file, 'dir' => $dir]);
})->with([
    ['/testing-dir', 'testing-dir', ''],
    ['/testing.txt', 'testing.txt', ''],
    ['/testing-dir/', 'testing-dir', ''],
    ['/testing-dir/file.txt', 'file.txt', '/testing-dir'],
    ['/testing-dir/nested/file.txt', 'file.txt', '/testing-dir/nested'],
]);

test('replaceFirst', function () {
    expect(Util::replaceFirst('X', 'S', 'XX'))->toBe('SX')
        ->and(Util::replaceFirst('X', 'S', 'ORIGINAL'))->toBe('ORIGINAL');
});
