<?php

namespace Tests\TasmoAdmin\Database;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use TasmoAdmin\Backup\BackupStorage;
use TasmoAdmin\Backup\BackupStorageMigrator;
use TasmoAdmin\Backup\DatabaseBackupStore;
use TasmoAdmin\Backup\FilesystemBackupStore;
use TasmoAdmin\Database\DatabaseExporter;
use TasmoAdmin\Device;
use TasmoAdmin\Storage\DatabaseConfigStorage;
use TasmoAdmin\Storage\DatabaseDeviceStorage;

class BackupStorageTest extends DatabaseTestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/tasmoadmin-backups-'.bin2hex(random_bytes(6)).'/';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->folder)) {
            exec('rm -rf '.escapeshellarg($this->folder));
        }
    }

    #[DataProvider('storages')]
    public function testSaveListGetPruneAndDelete(string $uri, string $type): void
    {
        $store = $this->storage($uri, $type);
        $device = new Device(7, ['Pool', 'Light'], '10.0.0.7', '', '', 'bulb_1');
        $binary = random_bytes(512)."\x00\xff end";

        $first = $store->save($device, '7-Pool-Light.dmp', 'first', '15.0.1', new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC')));
        $second = $store->save($device, '7-Pool-Light.dmp', $binary, '16.0.0', new \DateTimeImmutable('2026-10-02 10:00:00', new \DateTimeZone('UTC')));
        $other = $store->save(new Device(8, ['Plug'], '10.0.0.8', '', '', 'bulb_1'), '8-Plug.dmp', 'plug');

        $list = $store->list(7);
        self::assertSame([$second, $first], array_map(static fn ($b): string => $b->id, $list));
        self::assertNull($list[0]->content);
        self::assertSame('16.0.0', $list[0]->firmware);
        self::assertSame('Pool-Light', $list[0]->deviceName);
        self::assertSame(strlen($binary), $list[0]->size);
        self::assertSame('2026-10-02 10:00', $list[0]->createdAt->format('Y-m-d H:i'));
        self::assertCount(3, $store->list());

        $stored = $store->get($second);
        self::assertNotNull($stored);
        self::assertSame($binary, $stored->content);
        self::assertSame(hash('sha256', $binary), $stored->sha256);

        self::assertSame(1, $store->prune(7, 1));
        self::assertSame([$second], array_map(static fn ($b): string => $b->id, $store->list(7)));
        self::assertSame(0, $store->prune(8, 0));

        $store->delete($other);
        self::assertSame([], $store->list(8));
        self::assertNull($store->get($other));
        self::assertNull($store->get('../../etc/passwd'));

        $store->clear();
        self::assertSame([], $store->list());
    }

    #[DataProvider('databaseUris')]
    public function testBackupsMoveBetweenDatabaseAndFolder(string $uri): void
    {
        $database = new DatabaseBackupStore($this->connect($uri));
        $folder = new FilesystemBackupStore($this->folder);
        $device = new Device(3, ['Heater'], '10.0.0.3', '', '', 'bulb_1');
        $contents = [random_bytes(4096), random_bytes(4096)];
        $database->save($device, '3-Heater.dmp', $contents[0], '15.0.0', new \DateTimeImmutable('2026-09-01 08:00:00', new \DateTimeZone('UTC')));
        $database->save($device, '3-Heater.dmp', $contents[1], '16.0.0', new \DateTimeImmutable('2026-10-01 08:00:00', new \DateTimeZone('UTC')));

        $migrator = new BackupStorageMigrator();
        self::assertSame(2, $migrator->migrate($database, $folder));
        self::assertCount(0, $database->list());
        $moved = $folder->list();
        self::assertCount(2, $moved);
        self::assertSame('16.0.0', $moved[0]->firmware);
        self::assertSame('2026-10-01 08:00:00', $moved[0]->createdAt->format('Y-m-d H:i:s'));
        self::assertSame($contents[1], $folder->get($moved[0]->id)?->content);
        self::assertFileExists($this->folder.$moved[0]->id.'.dmp');

        self::assertSame(2, $migrator->migrate($folder, $database));
        self::assertSame([], $folder->list());
        $back = $database->list();
        self::assertCount(2, $back);
        self::assertSame(['16.0.0', '15.0.0'], array_map(static fn ($b): string => $b->firmware, $back));
        self::assertSame($contents[0], $database->get($back[1]->id)?->content);
        self::assertSame(0, $migrator->migrate($database, new DatabaseBackupStore($this->connect($uri, false))));
    }

    #[DataProvider('storages')]
    public function testExportAndImportRoundTrip(string $uri, string $type): void
    {
        $connection = $this->connect($uri);
        $backups = $this->storage($uri, $type, $connection);
        (new DatabaseConfigStorage($connection))->save(['homepage' => 'devices', 'nightmode' => 'always']);
        (new DatabaseDeviceStorage($connection))->appendRows([
            ['1', 'Relay 1|Relay 2', '10.0.0.2', 'admin', 'enc:v1:abc', 'bulb_1', '3', '1', '0', '0', '1', '80', 'R1|R2', '0', 'relay', '0'],
        ]);
        $content = random_bytes(300);
        $backups->save(new Device(1, ['Relay 1', 'Relay 2'], '10.0.0.2', '', '', 'bulb_1'), '1-Relay.dmp', $content, '16.0.0');

        $export = (new DatabaseExporter($connection, $backups))->export();
        self::assertStringStartsWith("\x1f\x8b", $export);

        $target = $this->connect('sqlite:///:memory:');
        $counts = (new DatabaseExporter($target))->import($export);
        self::assertSame(['ta_settings' => 2, 'ta_devices' => 1, 'ta_device_channels' => 2, 'ta_device_backups' => 1], $counts);

        self::assertSame('always', (new DatabaseConfigStorage($target))->load()['nightmode']);
        $rows = (new DatabaseDeviceStorage($target))->loadRows();
        self::assertSame('Relay 1|Relay 2', $rows[0][1]);
        self::assertSame('enc:v1:abc', $rows[0][4]);
        $imported = (new DatabaseBackupStore($target))->list();
        self::assertSame($content, (new DatabaseBackupStore($target))->get($imported[0]->id)?->content);
        self::assertSame('16.0.0', $imported[0]->firmware);

        $backups->clear();
        (new DatabaseExporter($connection, $backups))->import((new DatabaseExporter($target))->export());
        self::assertCount(1, $backups->list());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function storages(): array
    {
        $cases = [];
        foreach (self::databaseUris() as $name => [$uri]) {
            $cases[$name.' database'] = [$uri, BackupStorage::DATABASE];
        }
        $cases['folder'] = ['sqlite:///:memory:', BackupStorage::FILESYSTEM];

        return $cases;
    }

    public function testImportRejectsOtherFiles(): void
    {
        $exporter = new DatabaseExporter($this->connect('sqlite:///:memory:'));

        foreach (['not json', (string) json_encode(['format' => 'other', 'tables' => []]), (string) gzencode('{"format":"tasmoadmin-database","version":1,"tables":{"users":[]}}')] as $invalid) {
            try {
                $exporter->import($invalid);
                self::fail('import should reject the file');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function storage(string $uri, string $type, ?Connection $connection = null): BackupStorage
    {
        if (BackupStorage::FILESYSTEM === $type) {
            return new FilesystemBackupStore($this->folder);
        }

        return new DatabaseBackupStore($connection ?? $this->connect($uri));
    }
}
