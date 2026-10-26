<?php

namespace TasmoAdmin\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;

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
    private const TABLES = [Schema::SETTINGS, Schema::DEVICES, Schema::DEVICE_CHANNELS, Schema::DEVICE_BACKUPS];

    private const ORDER_BY = [
        Schema::SETTINGS => 'name',
        Schema::DEVICES => 'id',
        Schema::DEVICE_CHANNELS => 'device_id, channel',
        Schema::DEVICE_BACKUPS => 'id',
    ];

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, int> rows per table
     */
    public function counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        }

        return $counts;
    }

    public function export(): string
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $rows = [];
            foreach ($this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY %s', $table, self::ORDER_BY[$table])) as $row) {
                if (Schema::DEVICE_BACKUPS === $table) {
                    $content = $row['content'];
                    if (is_resource($content)) {
                        $content = (string) stream_get_contents($content);
                    }
                    $row['content'] = base64_encode((string) $content);
                }
                $rows[] = $row;
            }
            $tables[$table] = $rows;
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
     * Replaces everything in the ta_ tables with the export.
     *
     * @return array<string, int> rows imported per table
     */
    public function import(string $export): array
    {
        $document = $this->decode($export);

        $counts = [];
        $this->connection->transactional(function (Connection $connection) use ($document, &$counts): void {
            foreach (array_reverse(self::TABLES) as $table) {
                $connection->executeStatement(sprintf('DELETE FROM %s', $table));
            }

            foreach (self::TABLES as $table) {
                $rows = $document['tables'][$table] ?? [];
                foreach ($rows as $row) {
                    $connection->insert($table, ...$this->prepareRow($table, $row));
                }
                $counts[$table] = count($rows);
            }

            $this->resetSequence($connection, Schema::DEVICE_BACKUPS);
            $this->resetSequence($connection, Schema::DEVICES);
        });

        return $counts;
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
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function prepareRow(string $table, array $row): array
    {
        $types = [];
        if (Schema::DEVICE_BACKUPS === $table) {
            $content = base64_decode((string) ($row['content'] ?? ''), true);
            if (false === $content) {
                throw new \InvalidArgumentException('A backup in the export has invalid content.');
            }
            $row['content'] = $content;
            $types['content'] = ParameterType::LARGE_OBJECT;
            $row['created_at'] = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));
            $types['created_at'] = Types::DATETIME_IMMUTABLE;
        }

        return [$row, $types];
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
