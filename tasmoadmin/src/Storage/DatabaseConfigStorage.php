<?php

namespace TasmoAdmin\Storage;

use Doctrine\DBAL\Connection;
use TasmoAdmin\Database\Schema;

class DatabaseConfigStorage implements ConfigStorage
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function exists(): bool
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', Schema::SETTINGS)) > 0;
    }

    public function clear(): void
    {
        $this->connection->executeStatement(sprintf('DELETE FROM %s', Schema::SETTINGS));
    }

    public function load(): array
    {
        $config = [];
        foreach ($this->connection->fetchAllAssociative(sprintf('SELECT name, value FROM %s', Schema::SETTINGS)) as $row) {
            $config[(string) $row['name']] = (string) ($row['value'] ?? '');
        }

        return $config;
    }

    public function save(array $config): void
    {
        $this->connection->transactional(function (Connection $connection) use ($config): void {
            $current = [];
            foreach ($connection->fetchAllAssociative(sprintf('SELECT name, value FROM %s', Schema::SETTINGS)) as $row) {
                $current[(string) $row['name']] = (string) ($row['value'] ?? '');
            }

            foreach (array_diff_key($current, $config) as $name => $unused) {
                $connection->delete(Schema::SETTINGS, ['name' => $name]);
            }

            foreach ($config as $name => $value) {
                $name = (string) $name;
                $value = (string) $value;
                if (!array_key_exists($name, $current)) {
                    $connection->insert(Schema::SETTINGS, ['name' => $name, 'value' => $value]);
                } elseif ($current[$name] !== $value) {
                    $connection->update(Schema::SETTINGS, ['value' => $value], ['name' => $name]);
                }
            }
        });
    }
}
