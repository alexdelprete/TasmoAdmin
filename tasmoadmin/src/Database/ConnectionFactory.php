<?php

namespace TasmoAdmin\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

class ConnectionFactory
{
    private const DRIVER_EXTENSIONS = [
        'pdo_sqlite' => 'pdo_sqlite',
        'pdo_mysql' => 'pdo_mysql',
        'pdo_pgsql' => 'pdo_pgsql',
    ];

    public static function create(DatabaseUrl $url): Connection
    {
        $params = $url->toParams();
        $driver = $params['driver'];

        if (!extension_loaded(self::DRIVER_EXTENSIONS[$driver])) {
            throw new DatabaseConfigurationException(sprintf('The PHP extension %s is not installed, so %s cannot be used.', self::DRIVER_EXTENSIONS[$driver], $url->redacted()));
        }

        if ('pdo_sqlite' === $driver && isset($params['path'])) {
            $directory = dirname($params['path']);
            if (!is_dir($directory) || !is_writable($directory)) {
                throw new DatabaseConfigurationException(sprintf('The SQLite directory %s does not exist or is not writable.', $directory));
            }
        }

        $configuration = new Configuration();
        $configuration->setSchemaAssetsFilter(static function ($asset): bool {
            $name = is_string($asset) ? $asset : $asset->getName();
            $name = substr($name, (int) strrpos($name, '.') + (str_contains($name, '.') ? 1 : 0));

            return str_starts_with($name, Schema::PREFIX);
        });

        $connection = DriverManager::getConnection($params, $configuration);

        if ('pdo_sqlite' === $driver) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
            $connection->executeStatement('PRAGMA busy_timeout = 5000');
            if (isset($params['path'])) {
                $connection->executeStatement('PRAGMA journal_mode = WAL');
            }
        }

        return $connection;
    }
}
