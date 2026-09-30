<?php

declare(strict_types=1);

namespace Siberfx\BunnyCdn\Flysystem;

/**
 * A local file to upload with BunnyCDNAdapter::writeBatch().
 */
final readonly class WriteBatchFile
{
    public function __construct(
        public string $localPath,
        public string $targetPath,
    ) {}
}
