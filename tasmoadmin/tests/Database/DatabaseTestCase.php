<?php

namespace Tests\TasmoAdmin\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use TasmoAdmin\Database\ConnectionFactory;
use TasmoAdmin\Database\DatabaseUrl;
use TasmoAdmin\Database\Migrator;
use TasmoAdmin\Database\Schema;

/**
 * Runs against SQLite in memory, plus every URI listed (comma-separated) in
 * TASMO_TEST_DB_URIS, e.g. a MariaDB and a PostgreSQL test database.
 */
abstract class DatabaseTestCase extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function databaseUris(): array
    {
        $uris = ['sqlite' => ['sqlite:///:memory:']];
        foreach (array_filter(array_map('trim', explode(',', (string) getenv('TASMO_TEST_DB_URIS')))) as $uri) {
            $uris[(new DatabaseUrl($uri))->getDriver()] = [$uri];
        }

        return $uris;
    }

    protected function connect(string $uri, bool $migrate = true): Connection
    {
        $connection = ConnectionFactory::create(new DatabaseUrl($uri));
        $this->dropTables($connection);
        if ($migrate) {
            (new Migrator($connection))->migrate();
        }

        return $connection;
    }

    private function dropTables(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();
        foreach ([Schema::DEVICE_CHANNELS, Schema::DEVICE_BACKUPS, Schema::DEVICES, Schema::SETTINGS, Schema::META] as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $schemaManager->dropTable($table);
            }
        }
    }
}
