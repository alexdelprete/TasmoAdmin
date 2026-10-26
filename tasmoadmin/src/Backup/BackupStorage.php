<?php

namespace TasmoAdmin\Backup;

use TasmoAdmin\Device;

/**
 * Where device backups (.dmp files) are kept: the database or the data folder.
 */
interface BackupStorage
{
    public const DATABASE = 'database';
    public const FILESYSTEM = 'filesystem';

    public function getType(): string;

    /**
     * @return string the id of the new backup
     */
    public function save(Device $device, string $filename, string $content, string $firmware = '', ?\DateTimeImmutable $createdAt = null): string;

    /**
     * Stores a backup with its content and metadata (used when moving backups between storages).
     *
     * @return string the id of the stored backup
     */
    public function store(StoredBackup $backup): string;

    /**
     * Backups without their content, newest first.
     *
     * @return list<StoredBackup>
     */
    public function list(?int $deviceId = null): array;

    public function get(string $id): ?StoredBackup;

    public function delete(string $id): void;

    /**
     * Keeps the newest $keep backups of a device; 0 keeps all of them.
     *
     * @return int the number of backups removed
     */
    public function prune(int $deviceId, int $keep): int;

    public function clear(): void;
}
