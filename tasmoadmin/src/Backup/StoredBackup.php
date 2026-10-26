<?php

namespace TasmoAdmin\Backup;

class StoredBackup
{
    public function __construct(
        public readonly int $id,
        public readonly int $deviceId,
        public readonly string $deviceName,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $firmware,
        public readonly string $filename,
        public readonly int $size,
        public readonly string $sha256,
        public readonly ?string $content = null,
    ) {}
}
