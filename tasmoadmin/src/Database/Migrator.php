<?php

namespace TasmoAdmin\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Brings the ta_ tables to the current schema. Only tables named ta_* are
 * introspected (see ConnectionFactory), so other tables in a shared database
 * are never touched.
 */
class Migrator
{
    public const SCHEMA_VERSION = '1';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return string[] the statements that were executed
     */
    public function migrate(): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        $current = $schemaManager->introspectSchema();
        $target = Schema::build();

        $diff = $schemaManager->createComparator()->compareSchemas($current, $target);
        $statements = $this->connection->getDatabasePlatform()->getAlterSchemaSQL($diff);

        foreach ($statements as $statement) {
            $this->connection->executeStatement($statement);
        }

        $this->setMeta('schema_version', self::SCHEMA_VERSION);

        return $statements;
    }

    /**
     * Runs migrate() only when the stored schema version differs, so a normal
     * request costs one query instead of a schema introspection.
     *
     * @return string[]
     */
    public function migrateIfNeeded(): array
    {
        try {
            if (self::SCHEMA_VERSION === $this->getMeta('schema_version')) {
                return [];
            }
        } catch (Exception) {
            // ta_meta does not exist yet
        }

        return $this->migrate();
    }

    public function getMeta(string $name): ?string
    {
        $value = $this->connection->fetchOne(sprintf('SELECT value FROM %s WHERE name = ?', Schema::META), [$name]);

        return false === $value ? null : (string) $value;
    }

    public function setMeta(string $name, string $value): void
    {
        $this->connection->transactional(function (Connection $connection) use ($name, $value): void {
            $connection->delete(Schema::META, ['name' => $name]);
            $connection->insert(Schema::META, ['name' => $name, 'value' => $value]);
        });
    }
}
