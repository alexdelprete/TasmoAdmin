<?php

namespace TasmoAdmin\Database;

/**
 * A copy of TasmoAdmin's own data (settings, devices, channels) kept in the database.
 */
class Snapshot
{
    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $reason,
        public readonly int $devices,
        public readonly int $settings,
        public readonly int $size,
        public readonly ?string $content = null,
    ) {}
}
