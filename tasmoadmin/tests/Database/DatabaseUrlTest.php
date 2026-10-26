<?php

namespace Tests\TasmoAdmin\Database;

use PHPUnit\Framework\TestCase;
use TasmoAdmin\Database\DatabaseConfigurationException;
use TasmoAdmin\Database\DatabaseUrl;

class DatabaseUrlTest extends TestCase
{
    public function testDefaultsToSqliteInTheDataDirectory(): void
    {
        $url = DatabaseUrl::fromEnvironment('/data/', []);

        self::assertSame(['driver' => 'pdo_sqlite', 'path' => '/data/tasmoadmin.db'], $url->toParams());
    }

    public function testSqliteAbsoluteAndRelativePaths(): void
    {
        self::assertSame('/srv/ta.db', (new DatabaseUrl('sqlite:////srv/ta.db'))->toParams()['path']);
        self::assertSame('ta.db', (new DatabaseUrl('sqlite:///ta.db'))->toParams()['path']);
        self::assertTrue((new DatabaseUrl('sqlite:///:memory:'))->toParams()['memory']);
    }

    public function testMysqlAndMariadbUris(): void
    {
        $params = (new DatabaseUrl('mariadb://tasmo:p%40ss@db.example/tasmoadmin'))->toParams();

        self::assertSame('pdo_mysql', $params['driver']);
        self::assertSame('db.example', $params['host']);
        self::assertSame(3306, $params['port']);
        self::assertSame('tasmo', $params['user']);
        self::assertSame('p@ss', $params['password']);
        self::assertSame('tasmoadmin', $params['dbname']);
        self::assertSame('utf8mb4', $params['charset']);
    }

    public function testPostgresUri(): void
    {
        $params = (new DatabaseUrl('postgres://tasmo:secret@postgres.example:6543/tasmoadmin?sslmode=require'))->toParams();

        self::assertSame('pdo_pgsql', $params['driver']);
        self::assertSame(6543, $params['port']);
        self::assertSame('tasmoadmin', $params['dbname']);
        self::assertSame('require', $params['sslmode']);
    }

    public function testRedactedHidesThePassword(): void
    {
        self::assertSame(
            'postgres://tasmo:***@postgres.example:5432/tasmoadmin',
            (new DatabaseUrl('postgres://tasmo:not-a-real-password@postgres.example:5432/tasmoadmin'))->redacted()
        );
    }

    public function testUriFileIsRead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'uri');
        file_put_contents($file, "postgres://u:p@h/db\n");

        try {
            $url = DatabaseUrl::fromEnvironment('/data/', [DatabaseUrl::ENV_URI_FILE => $file]);
            self::assertSame('pdo_pgsql', $url->getDriver());
        } finally {
            unlink($file);
        }
    }

    public function testDifferentUriAndUriFileAreRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'uri');
        file_put_contents($file, 'postgres://u:p@h/db');

        try {
            $this->expectException(DatabaseConfigurationException::class);
            DatabaseUrl::fromEnvironment('/data/', [DatabaseUrl::ENV_URI => 'mysql://u:p@h/db', DatabaseUrl::ENV_URI_FILE => $file]);
        } finally {
            unlink($file);
        }
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        $this->expectException(DatabaseConfigurationException::class);

        (new DatabaseUrl('mongodb://h/db'))->toParams();
    }

    public function testServerUriNeedsADatabaseName(): void
    {
        $this->expectException(DatabaseConfigurationException::class);

        (new DatabaseUrl('postgres://u:p@h'))->toParams();
    }
}
