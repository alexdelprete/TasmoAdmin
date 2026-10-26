<?php

namespace TasmoAdmin\Database;

use Doctrine\DBAL\Connection;
use TasmoAdmin\Storage\CsvDeviceStorage;
use TasmoAdmin\Storage\DatabaseConfigStorage;
use TasmoAdmin\Storage\DatabaseDeviceStorage;
use TasmoAdmin\Storage\JsonFileConfigStorage;

/**
 * One-time import of devices.csv and MyConfig.json into an empty database.
 * The files are renamed to *.migrated afterwards, never deleted.
 */
class FileImporter
{
    public const META_KEY = 'files_imported_at';

    private Connection $connection;

    private Migrator $migrator;

    private string $dataDir;

    private string $tmpDir;

    public function __construct(Connection $connection, Migrator $migrator, string $dataDir, string $tmpDir)
    {
        $this->connection = $connection;
        $this->migrator = $migrator;
        $this->dataDir = rtrim($dataDir, '/').'/';
        $this->tmpDir = $tmpDir;
    }

    /**
     * @return null|array{devices: int, settings: int} null when nothing had to be imported
     */
    public function importIfNeeded(): ?array
    {
        if (null !== $this->migrator->getMeta(self::META_KEY)) {
            return null;
        }

        $csvFile = $this->dataDir.'devices.csv';
        $jsonFile = $this->dataDir.'MyConfig.json';

        if ($this->databaseHasData()) {
            $this->migrator->setMeta(self::META_KEY, $this->now().' (database already had data, files left unchanged)');

            return null;
        }

        $rows = is_file($csvFile) ? (new CsvDeviceStorage($csvFile, $this->tmpDir))->loadRows() : [];
        $config = is_file($jsonFile) ? (new JsonFileConfigStorage($this->dataDir, $jsonFile))->load() : [];

        $this->connection->transactional(function () use ($rows, $config): void {
            if ([] !== $rows) {
                (new DatabaseDeviceStorage($this->connection))->appendRows($rows);
            }
            if ([] !== $config) {
                (new DatabaseConfigStorage($this->connection))->save($config);
            }
            $this->migrator->setMeta(self::META_KEY, $this->now());
        });

        foreach ([$csvFile, $jsonFile] as $file) {
            if (is_file($file)) {
                @rename($file, $file.'.migrated');
            }
        }

        $result = ['devices' => count($rows), 'settings' => count($config)];
        error_log(sprintf('TasmoAdmin: imported %d devices and %d settings into the database.', $result['devices'], $result['settings']));

        return $result;
    }

    private function databaseHasData(): bool
    {
        $devices = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', Schema::DEVICES));
        $settings = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', Schema::SETTINGS));

        return $devices > 0 || $settings > 0;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
