<?php

namespace TasmoAdmin\Storage;

use Doctrine\DBAL\Connection;
use TasmoAdmin\Database\ConnectionFactory;
use TasmoAdmin\Database\DatabaseUrl;
use TasmoAdmin\Database\FileImporter;
use TasmoAdmin\Database\Migrator;

/**
 * Chooses where TasmoAdmin keeps its data.
 *
 * By default a database (TASMO_DB_URI, SQLite in the data directory when
 * unset). TASMO_STORAGE=files keeps the previous devices.csv/MyConfig.json files.
 */
class StorageBackend
{
    public const ENV_STORAGE = 'TASMO_STORAGE';

    private ConfigStorage $configStorage;

    private DeviceStorage $deviceStorage;

    private ?Connection $connection;

    private ?DatabaseUrl $databaseUrl;

    private function __construct(ConfigStorage $configStorage, DeviceStorage $deviceStorage, ?Connection $connection, ?DatabaseUrl $databaseUrl)
    {
        $this->configStorage = $configStorage;
        $this->deviceStorage = $deviceStorage;
        $this->connection = $connection;
        $this->databaseUrl = $databaseUrl;
    }

    /**
     * @param null|array<string, string> $environment
     */
    public static function fromEnvironment(string $dataDir, string $tmpDir, ?array $environment = null): self
    {
        $environment ??= getenv();

        if ('files' === strtolower(trim((string) ($environment[self::ENV_STORAGE] ?? '')))) {
            return self::files($dataDir, $tmpDir);
        }

        $url = DatabaseUrl::fromEnvironment($dataDir, $environment);

        return self::database(ConnectionFactory::create($url), $dataDir, $tmpDir, $url);
    }

    public static function files(string $dataDir, string $tmpDir): self
    {
        return new self(
            new JsonFileConfigStorage($dataDir),
            new CsvDeviceStorage(rtrim($dataDir, '/').'/devices.csv', $tmpDir),
            null,
            null
        );
    }

    public static function database(Connection $connection, string $dataDir, string $tmpDir, ?DatabaseUrl $url = null): self
    {
        $migrator = new Migrator($connection);
        $migrator->migrateIfNeeded();
        (new FileImporter($connection, $migrator, $dataDir, $tmpDir))->importIfNeeded();

        return new self(
            new DatabaseConfigStorage($connection),
            new DatabaseDeviceStorage($connection),
            $connection,
            $url
        );
    }

    public function getConfigStorage(): ConfigStorage
    {
        return $this->configStorage;
    }

    public function getDeviceStorage(): DeviceStorage
    {
        return $this->deviceStorage;
    }

    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    public function describe(): string
    {
        return null === $this->databaseUrl ? 'files' : $this->databaseUrl->redacted();
    }
}
