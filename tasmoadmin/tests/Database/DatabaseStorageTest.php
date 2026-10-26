<?php

namespace Tests\TasmoAdmin\Database;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use TasmoAdmin\Config;
use TasmoAdmin\Database\FileImporter;
use TasmoAdmin\Database\Migrator;
use TasmoAdmin\Database\Schema;
use TasmoAdmin\DevicePasswordCipher;
use TasmoAdmin\DevicePasswordKeyProvider;
use TasmoAdmin\DeviceRepository;
use TasmoAdmin\Storage\DatabaseConfigStorage;
use TasmoAdmin\Storage\DatabaseDeviceStorage;

class DatabaseStorageTest extends DatabaseTestCase
{
    private const TEST_KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    private vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('ta', null, ['data' => [], 'tmp' => []]);
        putenv(DevicePasswordKeyProvider::ENV_NAME.'='.self::TEST_KEY);
    }

    protected function tearDown(): void
    {
        putenv(DevicePasswordKeyProvider::ENV_NAME);
    }

    #[DataProvider('databaseUris')]
    public function testMigrationIsIdempotent(string $uri): void
    {
        $connection = $this->connect($uri);
        $migrator = new Migrator($connection);

        self::assertSame([], $migrator->migrate());
        self::assertSame([], $migrator->migrateIfNeeded());
        self::assertSame(Migrator::SCHEMA_VERSION, $migrator->getMeta('schema_version'));
    }

    #[DataProvider('databaseUris')]
    public function testImportsDevicesAndSettingsFromFiles(string $uri): void
    {
        $connection = $this->connect($uri);
        copy(__DIR__.'/../fixtures/devices.csv', $this->dataDir().'devices.csv');
        file_put_contents($this->dataDir().'MyConfig.json', json_encode(['homepage' => 'devices', 'refreshtime' => '15']));

        copy(__DIR__.'/../fixtures/devices.csv', $this->tmpDir().'expected.csv');
        $csvRepository = new DeviceRepository($this->tmpDir().'expected.csv', $this->tmpDir(), $this->cipher());
        $expected = $csvRepository->getDevices();

        $importer = new FileImporter($connection, new Migrator($connection), $this->dataDir(), $this->tmpDir());
        self::assertSame(['devices' => count($expected), 'settings' => 2], $importer->importIfNeeded());
        self::assertNull($importer->importIfNeeded());

        self::assertFileDoesNotExist($this->dataDir().'devices.csv');
        self::assertFileExists($this->dataDir().'devices.csv.migrated');
        self::assertFileExists($this->dataDir().'MyConfig.json.migrated');

        $repository = new DeviceRepository(new DatabaseDeviceStorage($connection), $this->tmpDir(), $this->cipher());
        $actual = $repository->getDevices();

        self::assertCount(count($expected), $actual);
        foreach ($expected as $index => $device) {
            self::assertSame($device->id, $actual[$index]->id);
            self::assertSame($device->names, $actual[$index]->names);
            self::assertSame($device->ip, $actual[$index]->ip);
            self::assertSame($device->port, $actual[$index]->port);
            self::assertSame($device->position, $actual[$index]->position);
            self::assertSame($device->password, $actual[$index]->password);
        }

        $storedPassword = (string) $connection->fetchOne(sprintf('SELECT password FROM %s WHERE id = 1', Schema::DEVICES));
        self::assertStringStartsWith(DevicePasswordCipher::STORAGE_PREFIX, $storedPassword);

        self::assertSame('15', (new DatabaseConfigStorage($connection))->load()['refreshtime']);
    }

    #[DataProvider('databaseUris')]
    public function testImportLeavesFilesAloneWhenTheDatabaseHasData(string $uri): void
    {
        $connection = $this->connect($uri);
        (new DatabaseConfigStorage($connection))->save(['homepage' => 'start']);
        copy(__DIR__.'/../fixtures/devices.csv', $this->dataDir().'devices.csv');

        $importer = new FileImporter($connection, new Migrator($connection), $this->dataDir(), $this->tmpDir());

        self::assertNull($importer->importIfNeeded());
        self::assertFileExists($this->dataDir().'devices.csv');
        self::assertSame(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', Schema::DEVICES)));
    }

    #[DataProvider('databaseUris')]
    public function testRepositoryOperations(string $uri): void
    {
        $connection = $this->connect($uri);
        $repository = new DeviceRepository(new DatabaseDeviceStorage($connection), $this->tmpDir(), $this->cipher());

        $repository->addDevices([
            ['device_name' => ['Relay 1', 'Relay 2'], 'device_ip' => '10.0.0.2', 'device_position' => 1],
            ['device_name' => ['Plug'], 'device_ip' => '10.0.0.3', 'device_position' => 2, 'device_mqtt_topic' => 'plug'],
        ], 'admin', 'secret');

        $devices = $repository->getDevices();
        self::assertCount(2, $devices);
        self::assertSame(['Relay 1', 'Relay 2'], $devices[0]->names);
        self::assertSame('secret', $devices[0]->password);
        self::assertSame(2, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE device_id = 1', Schema::DEVICE_CHANNELS)));

        $updated = $repository->setDeviceValue(2, 'ip', '10.0.0.30');
        self::assertSame('10.0.0.30', $updated?->ip);
        self::assertSame('10.0.0.30', $repository->getDeviceById(2)?->ip);
        self::assertSame(2, $repository->getDeviceByMqttTopic('plug')?->id);

        $repository->setDeviceConfirmToggleForAll(true);
        self::assertTrue((bool) $repository->getDeviceById(1)?->deviceConfirmToggle);

        $repository->addDevice(['device_name' => ['Third'], 'device_ip' => '10.0.0.4']);
        self::assertSame(3, $repository->getDevices()[2]->id);

        $repository->removeDevice(1);
        self::assertNull($repository->getDeviceById(1));
        self::assertCount(2, $repository->getDevices());
        self::assertSame(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE device_id = 1', Schema::DEVICE_CHANNELS)));
    }

    #[DataProvider('databaseUris')]
    public function testConfigReadsAndWritesTheDatabase(string $uri): void
    {
        $connection = $this->connect($uri);
        $storage = new DatabaseConfigStorage($connection);

        $config = new Config($this->dataDir(), $this->root->url().'/', $storage);
        $config->write('homepage', 'start');
        $config->write('mqtt_discovery_password', 'hunter2');

        $reloaded = new Config($this->dataDir(), $this->root->url().'/', new DatabaseConfigStorage($connection));
        self::assertSame('start', $reloaded->read('homepage'));
        self::assertSame('hunter2', $reloaded->readAll()['mqtt_discovery_password']);

        $stored = (string) $connection->fetchOne(sprintf('SELECT value FROM %s WHERE name = ?', Schema::SETTINGS), ['mqtt_discovery_password']);
        self::assertNotSame('hunter2', $stored);
        self::assertFileDoesNotExist($this->dataDir().'MyConfig.json');

        $config->write('homepage', null);
        self::assertArrayNotHasKey('homepage', (new DatabaseConfigStorage($connection))->load());
    }

    private function cipher(): DevicePasswordCipher
    {
        return new DevicePasswordCipher(new DevicePasswordKeyProvider($this->dataDir()));
    }

    private function dataDir(): string
    {
        return $this->root->url().'/data/';
    }

    private function tmpDir(): string
    {
        return $this->root->url().'/tmp/';
    }
}
