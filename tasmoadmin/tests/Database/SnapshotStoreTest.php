<?php

namespace Tests\TasmoAdmin\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use TasmoAdmin\Backup\DatabaseBackupStore;
use TasmoAdmin\Database\DatabaseExporter;
use TasmoAdmin\Database\Schema;
use TasmoAdmin\Database\SnapshotStore;
use TasmoAdmin\Device;
use TasmoAdmin\Storage\DatabaseConfigStorage;
use TasmoAdmin\Storage\DatabaseDeviceStorage;

class SnapshotStoreTest extends DatabaseTestCase
{
    #[DataProvider('databaseUris')]
    public function testSnapshotRestoreKeepsDeviceBackups(string $uri): void
    {
        $connection = $this->connect($uri);
        $settings = new DatabaseConfigStorage($connection);
        $devices = new DatabaseDeviceStorage($connection);
        $settings->save(['homepage' => 'devices', 'refreshtime' => '15']);
        $devices->appendRows([['1', 'Plug', '10.0.0.5', '', '', 'bulb_1', '1', '1', '0', '0', '1', '80', 'Plug', '0', 'plug', '0']]);
        $backups = new DatabaseBackupStore($connection);
        $backups->save(new Device(1, ['Plug'], '10.0.0.5', '', '', 'bulb_1'), '1-Plug.dmp', 'config');

        $snapshots = new SnapshotStore($connection);
        $id = $snapshots->create(SnapshotStore::REASON_MANUAL);
        $snapshot = $snapshots->get($id);
        self::assertNotNull($snapshot);
        self::assertSame(1, $snapshot->devices);
        self::assertSame(2, $snapshot->settings);

        $settings->save(['homepage' => 'start', 'backup_storage' => 'filesystem']);
        $devices->saveRows([]);
        $backups->save(new Device(1, ['Plug'], '10.0.0.5', '', '', 'bulb_1'), '1-Plug.dmp', 'newer');

        $counts = $snapshots->restore($id);
        self::assertSame(['ta_settings' => 2, 'ta_devices' => 1, 'ta_device_channels' => 1], $counts);
        self::assertSame('15', $settings->load()['refreshtime']);
        self::assertSame('filesystem', $settings->load()['backup_storage'], 'the backup location is not rolled back by a restore');
        self::assertSame('10.0.0.5', $devices->loadRows()[0][2]);
        self::assertCount(2, $backups->list(), 'device backups are not part of a snapshot');

        $list = $snapshots->list();
        self::assertSame([SnapshotStore::REASON_BEFORE_RESTORE, SnapshotStore::REASON_MANUAL], array_map(static fn ($s): string => $s->reason, $list));
        self::assertSame(0, $list[0]->devices, 'the snapshot taken before the restore holds the data being replaced');

        $latest = $snapshots->latest();
        self::assertNotNull($latest);
        self::assertSame($list[0]->id, $latest->id);
        self::assertSame(['ta_settings' => 2, 'ta_devices' => 0, 'ta_device_channels' => 0], (new DatabaseExporter($this->connect('sqlite:///:memory:')))->import((string) $latest->content));
    }

    #[DataProvider('databaseUris')]
    public function testDailySnapshotAndRetention(string $uri): void
    {
        $connection = $this->connect($uri);
        $snapshots = new SnapshotStore($connection);
        $now = new \DateTimeImmutable('2026-10-26 12:00:00', new \DateTimeZone('UTC'));

        self::assertNull($snapshots->createIfDue(now: $now), 'an empty database is not snapshotted');

        (new DatabaseConfigStorage($connection))->save(['homepage' => 'devices', SnapshotStore::KEEP_SETTING => '2']);
        self::assertNotNull($snapshots->createIfDue(now: $now));
        self::assertNull($snapshots->createIfDue(now: $now->modify('+23 hours')));
        self::assertNotNull($snapshots->createIfDue(now: $now->modify('+25 hours')));
        self::assertNotNull($snapshots->createIfDue(now: $now->modify('+50 hours')));

        self::assertSame(2, $snapshots->getKeepCount());
        self::assertCount(2, $snapshots->list());
        self::assertSame(2, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', Schema::SNAPSHOTS)));
    }
}
