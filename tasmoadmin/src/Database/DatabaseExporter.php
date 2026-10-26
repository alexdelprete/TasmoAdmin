<?php

namespace TasmoAdmin\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use TasmoAdmin\Backup\BackupStorage;
use TasmoAdmin\Backup\DatabaseBackupStore;
use TasmoAdmin\Backup\StoredBackup;

/**
 * Exports all ta_ tables to one gzip-compressed JSON document and imports it
 * back, into the same or another database engine.
 *
 * Device passwords and the MQTT password stay encrypted in the export; the
 * importing TasmoAdmin needs the same password key (TASMO_DEVICE_PASSWORD_KEY
 * or the key file in the data directory) to read them.
 */
class DatabaseExporter
{
    public const FORMAT = 'tasmoadmin-database';
    public const VERSION = 1;

    /**
     * Tables in insert order (parents before children).
     */
    public const TABLES = [Schema::SETTINGS, Schema::DEVICES, Schema::DEVICE_CHANNELS, Schema::DEVICE_BACKUPS];

    /**
     * What a snapshot of TasmoAdmin's own data contains (no device backups).
     */
    public const DATA_TABLES = [Schema::SETTINGS, Schema::DEVICES, Schema::DEVICE_CHANNELS];

    /**
     * Settings that describe this installation rather than its data: an
     * import or snapshot restore keeps their current values.
     */
    private const KEPT_SETTINGS = ['backup_storage'];

    private const ORDER_BY = [
        Schema::SETTINGS => 'name',
        Schema::DEVICES => 'id',
        Schema::DEVICE_CHANNELS => 'device_id, channel',
    ];

    private Connection $connection;

    private BackupStorage $backups;

    /**
     * @param null|BackupStorage $backups where device backups are read from and written to (the database by default)
     */
    public function __construct(Connection $connection, ?BackupStorage $backups = null)
    {
        $this->connection = $connection;
        $this->backups = $backups ?? new DatabaseBackupStore($connection);
    }

    /**
     * @return array<string, int> rows per table
     */
    public function counts(): array
    {
        $counts = [];
        foreach (self::DATA_TABLES as $table) {
            $counts[$table] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        }
        $counts[Schema::DEVICE_BACKUPS] = count($this->backups->list());

        return $counts;
    }

    /**
     * @param list<string> $only the tables to export, all of them by default
     */
    public function export(array $only = self::TABLES): string
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            if (!in_array($table, $only, true)) {
                continue;
            }

            if (Schema::DEVICE_BACKUPS === $table) {
                $tables[$table] = $this->exportBackups();

                continue;
            }

            $tables[$table] = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY %s', $table, self::ORDER_BY[$table]));
        }

        $document = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'schema_version' => Migrator::SCHEMA_VERSION,
            'exported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'tables' => $tables,
        ];

        return (string) gzencode((string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 9);
    }

    /**
     * Replaces the data of every table contained in the export; tables the
     * export does not contain (e.g. device backups in a snapshot) are kept.
     *
     * @return array<string, int> rows imported per table
     */
    public function import(string $export): array
    {
        $document = $this->decode($export);
        $present = array_values(array_filter(self::TABLES, static fn (string $table): bool => array_key_exists($table, $document['tables'])));

        $counts = [];
        $this->connection->transactional(function (Connection $connection) use ($document, $present, &$counts): void {
            $keptSettings = [];
            if (in_array(Schema::SETTINGS, $present, true)) {
                foreach (self::KEPT_SETTINGS as $name) {
                    $value = $connection->fetchOne(sprintf('SELECT value FROM %s WHERE name = ?', Schema::SETTINGS), [$name]);
                    if (false !== $value) {
                        $keptSettings[$name] = (string) $value;
                    }
                }
            }

            foreach (array_reverse($present) as $table) {
                if (Schema::DEVICE_BACKUPS === $table) {
                    $this->backups->clear();
                } else {
                    $connection->executeStatement(sprintf('DELETE FROM %s', $table));
                }
            }

            foreach ($present as $table) {
                $rows = $document['tables'][$table];
                foreach ($rows as $row) {
                    if (Schema::DEVICE_BACKUPS === $table) {
                        $this->backups->store($this->toBackup($row));
                    } else {
                        $connection->insert($table, $row);
                    }
                }
                $counts[$table] = count($rows);
            }

            foreach ($keptSettings as $name => $value) {
                $connection->delete(Schema::SETTINGS, ['name' => $name]);
                $connection->insert(Schema::SETTINGS, ['name' => $name, 'value' => $value]);
            }

            if (in_array(Schema::DEVICES, $present, true)) {
                $this->resetSequence($connection, Schema::DEVICES);
            }
        });

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportBackups(): array
    {
        $rows = [];
        foreach (array_reverse($this->backups->list()) as $summary) {
            $backup = $this->backups->get($summary->id);
            if (null === $backup) {
                continue;
            }
            $rows[] = [
                'device_id' => $backup->deviceId,
                'device_name' => $backup->deviceName,
                'created_at' => $backup->createdAt->format('Y-m-d H:i:s'),
                'firmware' => $backup->firmware,
                'filename' => $backup->filename,
                'size' => $backup->size,
                'sha256' => $backup->sha256,
                'content' => base64_encode((string) $backup->content),
            ];
        }

        return $rows;
    }

    /**
     * @return array{format: string, version: int, tables: array<string, list<array<string, mixed>>>}
     */
    private function decode(string $export): array
    {
        $json = str_starts_with($export, "\x1f\x8b") ? @gzdecode($export) : $export;
        if (false === $json) {
            throw new \InvalidArgumentException('The file is not a valid TasmoAdmin database export (gzip could not be read).');
        }

        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('The file is not a valid TasmoAdmin database export (no JSON).');
        }

        if (!is_array($document) || self::FORMAT !== ($document['format'] ?? null) || !is_array($document['tables'] ?? null)) {
            throw new \InvalidArgumentException('The file is not a TasmoAdmin database export.');
        }
        if ((int) ($document['version'] ?? 0) > self::VERSION) {
            throw new \InvalidArgumentException('The export was made by a newer TasmoAdmin; update this one first.');
        }

        foreach ($document['tables'] as $table => $rows) {
            if (!in_array($table, self::TABLES, true) || !is_array($rows)) {
                throw new \InvalidArgumentException(sprintf('The export contains an unknown table "%s".', (string) $table));
            }
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toBackup(array $row): StoredBackup
    {
        $content = base64_decode((string) ($row['content'] ?? ''), true);
        if (false === $content) {
            throw new \InvalidArgumentException('A backup in the export has invalid content.');
        }

        return StoredBackup::create(
            (int) ($row['device_id'] ?? 0),
            (string) ($row['device_name'] ?? ''),
            (string) ($row['filename'] ?? 'backup.dmp'),
            $content,
            (string) ($row['firmware'] ?? ''),
            new \DateTimeImmutable((string) ($row['created_at'] ?? 'now'), new \DateTimeZone('UTC'))
        );
    }

    private function resetSequence(Connection $connection, string $table): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        $connection->executeStatement(sprintf(
            "SELECT setval(pg_get_serial_sequence('%1\$s', 'id'), COALESCE((SELECT MAX(id) FROM %1\$s), 0) + 1, false)",
            $table
        ));
    }
}
