<?php

namespace Siberfx\BunnyCdn\Flysystem;

class WriteBatchFile
{
    public function __construct(
        public string $localPath,
        public string $targetPath,
    ) {}
}
