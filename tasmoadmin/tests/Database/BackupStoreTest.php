<?php

namespace Tests\TasmoAdmin\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use TasmoAdmin\Backup\BackupStore;
use TasmoAdmin\Database\DatabaseExporter;
use TasmoAdmin\Device;
use TasmoAdmin\Storage\DatabaseConfigStorage;
use TasmoAdmin\Storage\DatabaseDeviceStorage;

class BackupStoreTest extends DatabaseTestCase
{
    #[DataProvider('databaseUris')]
    public function testSaveListGetPruneAndDelete(string $uri): void
    {
        $store = new BackupStore($this->connect($uri));
        $device = new Device(7, ['Pool', 'Light'], '10.0.0.7', '', '', 'bulb_1');
        $binary = random_bytes(512)."\x00\xff end";

        $first = $store->save($device, '7-Pool-Light.dmp', 'first', '15.0.1', new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC')));
        $second = $store->save($device, '7-Pool-Light.dmp', $binary, '16.0.0', new \DateTimeImmutable('2026-10-02 10:00:00', new \DateTimeZone('UTC')));
        $other = $store->save(new Device(8, ['Plug'], '10.0.0.8', '', '', 'bulb_1'), '8-Plug.dmp', 'plug');

        $list = $store->list(7);
        self::assertSame([$second, $first], array_map(static fn ($b): int => $b->id, $list));
        self::assertNull($list[0]->content);
        self::assertSame('16.0.0', $list[0]->firmware);
        self::assertSame('Pool-Light', $list[0]->deviceName);
        self::assertSame(strlen($binary), $list[0]->size);
        self::assertSame('2026-10-02 10:00', $list[0]->createdAt->format('Y-m-d H:i'));

        $stored = $store->get($second);
        self::assertNotNull($stored);
        self::assertSame($binary, $stored->content);
        self::assertSame(hash('sha256', $binary), $stored->sha256);

        self::assertSame(1, $store->prune(7, 1));
        self::assertSame([$second], array_map(static fn ($b): int => $b->id, $store->list(7)));
        self::assertSame(0, $store->prune(8, 0));

        $store->delete($other);
        self::assertSame([], $store->list(8));
        self::assertNull($store->get($other));
    }

    #[DataProvider('databaseUris')]
    public function testExportAndImportRoundTrip(string $uri): void
    {
        $connection = $this->connect($uri);
        (new DatabaseConfigStorage($connection))->save(['homepage' => 'devices', 'nightmode' => 'always']);
        (new DatabaseDeviceStorage($connection))->appendRows([
            ['1', 'Relay 1|Relay 2', '10.0.0.2', 'admin', 'enc:v1:abc', 'bulb_1', '3', '1', '0', '0', '1', '80', 'R1|R2', '0', 'relay', '0'],
        ]);
        $content = random_bytes(300);
        (new BackupStore($connection))->save(new Device(1, ['Relay 1', 'Relay 2'], '10.0.0.2', '', '', 'bulb_1'), '1-Relay.dmp', $content, '16.0.0');

        $exporter = new DatabaseExporter($connection);
        $export = $exporter->export();
        self::assertStringStartsWith("\x1f\x8b", $export);

        $target = $this->connect('sqlite:///:memory:');
        $counts = (new DatabaseExporter($target))->import($export);
        self::assertSame(['ta_settings' => 2, 'ta_devices' => 1, 'ta_device_channels' => 2, 'ta_device_backups' => 1], $counts);

        self::assertSame('always', (new DatabaseConfigStorage($target))->load()['nightmode']);
        $rows = (new DatabaseDeviceStorage($target))->loadRows();
        self::assertSame('Relay 1|Relay 2', $rows[0][1]);
        self::assertSame('enc:v1:abc', $rows[0][4]);
        $backups = (new BackupStore($target))->list();
        self::assertSame($content, (new BackupStore($target))->get($backups[0]->id)?->content);

        $next = (new BackupStore($target))->save(new Device(1, ['Relay 1'], '10.0.0.2', '', '', 'bulb_1'), 'x.dmp', 'x');
        self::assertGreaterThan($backups[0]->id, $next);

        $roundTrip = $this->connect($uri);
        (new DatabaseExporter($roundTrip))->import((new DatabaseExporter($target))->export());
        self::assertCount(2, (new BackupStore($roundTrip))->list());
        $nextOnSource = (new BackupStore($roundTrip))->save(new Device(1, ['Relay 1'], '10.0.0.2', '', '', 'bulb_1'), 'y.dmp', 'y');
        self::assertGreaterThan($next, $nextOnSource);
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
}
