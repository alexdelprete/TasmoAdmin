<?php

namespace TasmoAdmin\Database;

/**
 * Database location from the environment.
 *
 * TASMO_DB_URI selects the database, for example
 *   sqlite:////data/tasmoadmin.db
 *   mysql://user:secret@mariadb.example:3306/tasmoadmin  (MariaDB or MySQL)
 *   postgres://user:secret@postgres.example:5432/tasmoadmin
 * TASMO_DB_URI_FILE reads the same value from a file (Docker secrets).
 * Without either, an SQLite database in the data directory is used.
 */
class DatabaseUrl
{
    public const ENV_URI = 'TASMO_DB_URI';
    public const ENV_URI_FILE = 'TASMO_DB_URI_FILE';
    public const DEFAULT_SQLITE_FILE = 'tasmoadmin.db';

    private const DRIVERS = [
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
    ];

    private const DEFAULT_PORTS = [
        'pdo_mysql' => 3306,
        'pdo_pgsql' => 5432,
    ];

    private string $uri;

    public function __construct(string $uri)
    {
        $this->uri = trim($uri);
    }

    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(string $dataDir, ?array $environment = null): self
    {
        $environment ??= getenv();

        $uri = trim((string) ($environment[self::ENV_URI] ?? ''));
        $uriFile = trim((string) ($environment[self::ENV_URI_FILE] ?? ''));

        if ('' !== $uriFile) {
            if (!is_readable($uriFile)) {
                throw new DatabaseConfigurationException(sprintf('%s points to %s, which cannot be read.', self::ENV_URI_FILE, $uriFile));
            }
            $fileUri = trim((string) file_get_contents($uriFile));
            if ('' !== $uri && $uri !== $fileUri) {
                throw new DatabaseConfigurationException(sprintf('%s and %s are both set but differ; set only one.', self::ENV_URI, self::ENV_URI_FILE));
            }
            $uri = $fileUri;
        }

        if ('' === $uri) {
            $uri = 'sqlite:///'.rtrim($dataDir, '/').'/'.self::DEFAULT_SQLITE_FILE;
        }

        return new self($uri);
    }

    public function getDriver(): string
    {
        $scheme = 1 === preg_match('#^([a-z0-9+.-]+)://#i', $this->uri, $matches) ? strtolower($matches[1]) : '';
        if (!isset(self::DRIVERS[$scheme])) {
            throw new DatabaseConfigurationException(sprintf(
                'Unsupported database scheme "%s" in %s. Use sqlite, mysql, mariadb or postgres.',
                $scheme,
                self::ENV_URI
            ));
        }

        return self::DRIVERS[$scheme];
    }

    /**
     * Connection parameters for Doctrine DBAL.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        $driver = $this->getDriver();

        if ('pdo_sqlite' === $driver) {
            return $this->sqliteParams();
        }

        $parts = parse_url($this->uri);
        if (false === $parts || empty($parts['host']) || empty($parts['path']) || '/' === $parts['path']) {
            throw new DatabaseConfigurationException(sprintf('%s must include a host and a database name.', self::ENV_URI));
        }

        $params = [
            'driver' => $driver,
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : self::DEFAULT_PORTS[$driver],
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'dbname' => rawurldecode(ltrim($parts['path'], '/')),
        ];

        parse_str($parts['query'] ?? '', $query);
        if ('pdo_mysql' === $driver) {
            $params['charset'] = is_string($query['charset'] ?? null) ? $query['charset'] : 'utf8mb4';
        }
        if ('pdo_pgsql' === $driver && is_string($query['sslmode'] ?? null)) {
            $params['sslmode'] = $query['sslmode'];
        }

        return $params;
    }

    public function isSqlite(): bool
    {
        return 'pdo_sqlite' === $this->getDriver();
    }

    /**
     * The URI without its password, for logs and error pages.
     */
    public function redacted(): string
    {
        return (string) preg_replace('#^([a-z0-9+.-]+://[^:/@]+):[^@]*@#i', '$1:***@', $this->uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function sqliteParams(): array
    {
        $path = (string) preg_replace('#^[a-z0-9]+://#i', '', $this->uri);
        if ('' === $path || '/' === $path) {
            throw new DatabaseConfigurationException(sprintf('%s must include the SQLite file path, for example sqlite:////data/tasmoadmin.db.', self::ENV_URI));
        }

        if (':memory:' === $path || '/:memory:' === $path) {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        // sqlite:////abs/path => "//abs/path" after the scheme, sqlite:///rel => "/rel"
        if (str_starts_with($path, '//')) {
            $path = substr($path, 1);
        } elseif (str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }

        return ['driver' => 'pdo_sqlite', 'path' => $path];
    }
}
