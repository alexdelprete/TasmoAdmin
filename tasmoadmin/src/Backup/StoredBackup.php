<?php

namespace TasmoAdmin\Backup;

use TasmoAdmin\Device;

class StoredBackup
{
    public function __construct(
        public readonly string $id,
        public readonly int $deviceId,
        public readonly string $deviceName,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $firmware,
        public readonly string $filename,
        public readonly int $size,
        public readonly string $sha256,
        public readonly ?string $content = null,
    ) {}

    public static function create(Device|int $device, string $deviceName, string $filename, string $content, string $firmware = '', ?\DateTimeImmutable $createdAt = null): self
    {
        return new self(
            '',
            $device instanceof Device ? $device->id : $device,
            mb_substr($deviceName, 0, 255),
            $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            mb_substr($firmware, 0, 128),
            mb_substr(basename($filename), 0, 255),
            strlen($content),
            hash('sha256', $content),
            $content
        );
    }
}
