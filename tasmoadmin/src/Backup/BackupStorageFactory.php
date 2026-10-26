<?php

namespace TasmoAdmin\Backup;

use Doctrine\DBAL\Connection;

class BackupStorageFactory
{
    public const SETTING = 'backup_storage';
    public const FOLDER = 'backups/';

    private ?Connection $connection;

    private string $dataDir;

    public function __construct(?Connection $connection, string $dataDir)
    {
        $this->connection = $connection;
        $this->dataDir = rtrim($dataDir, '/').'/';
    }

    /**
     * The storage for a setting value; without a database the data folder is always used.
     */
    public function create(?string $type): BackupStorage
    {
        if (null !== $this->connection && BackupStorage::FILESYSTEM !== $type) {
            return new DatabaseBackupStore($this->connection);
        }

        return new FilesystemBackupStore($this->dataDir.self::FOLDER);
    }

    public function hasDatabase(): bool
    {
        return null !== $this->connection;
    }
}
