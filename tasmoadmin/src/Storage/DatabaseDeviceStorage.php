<?php

namespace TasmoAdmin\Storage;

use Doctrine\DBAL\Connection;
use TasmoAdmin\Database\Schema;

/**
 * Device rows in ta_devices, with one ta_device_channels row per relay
 * (the CSV format keeps channel names as "name1|name2|...").
 */
class DatabaseDeviceStorage implements DeviceStorage
{
    private const COLUMNS = [
        2 => 'ip',
        3 => 'username',
        4 => 'password',
        5 => 'img',
        6 => 'position',
        7 => 'all_off',
        8 => 'protect_on',
        9 => 'protect_off',
        10 => 'is_updatable',
        11 => 'port',
        13 => 'confirm_toggle',
        14 => 'mqtt_topic',
        15 => 'hide_from_startpage',
    ];

    private const INTEGER_COLUMNS = ['position', 'all_off', 'protect_on', 'protect_off', 'is_updatable', 'port', 'confirm_toggle', 'hide_from_startpage'];

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function loadRows(): array
    {
        $channels = [];
        $channelRows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT device_id, channel, name, friendly_name FROM %s ORDER BY device_id, channel',
            Schema::DEVICE_CHANNELS
        ));
        foreach ($channelRows as $channel) {
            $channels[(int) $channel['device_id']][] = $channel;
        }

        $rows = [];
        $devices = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY id', Schema::DEVICES));
        foreach ($devices as $device) {
            $id = (int) $device['id'];
            $deviceChannels = $channels[$id] ?? [];

            $row = [0 => (string) $id];
            $row[1] = implode('|', array_map(static fn (array $c): string => (string) $c['name'], $deviceChannels));
            foreach (self::COLUMNS as $index => $column) {
                $row[$index] = (string) ($device[$column] ?? '');
            }
            $row[12] = implode('|', array_map(static fn (array $c): string => (string) $c['friendly_name'], $deviceChannels));
            ksort($row);

            $rows[] = $row;
        }

        return $rows;
    }

    public function saveRows(array $rows): void
    {
        $this->connection->transactional(function (Connection $connection) use ($rows): void {
            $connection->executeStatement(sprintf('DELETE FROM %s', Schema::DEVICE_CHANNELS));
            $connection->executeStatement(sprintf('DELETE FROM %s', Schema::DEVICES));
            $this->insertRows($connection, $rows);
        });
    }

    public function appendRows(array $rows): void
    {
        $this->connection->transactional(function (Connection $connection) use ($rows): void {
            $this->insertRows($connection, $rows);
        });
    }

    public function clear(): void
    {
        $this->saveRows([]);
    }

    /**
     * @param list<array<int, int|string>> $rows
     */
    private function insertRows(Connection $connection, array $rows): void
    {
        foreach ($rows as $row) {
            $id = (int) ($row[0] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $values = ['id' => $id];
            foreach (self::COLUMNS as $index => $column) {
                $value = trim((string) ($row[$index] ?? ''));
                $values[$column] = in_array($column, self::INTEGER_COLUMNS, true) ? $this->toInt($column, $value) : $value;
            }
            $connection->insert(Schema::DEVICES, $values);

            $names = $this->splitNames($row[1] ?? '');
            $friendlyNames = $this->splitNames($row[12] ?? '');
            $count = max(count($names), count($friendlyNames));
            for ($channel = 0; $channel < $count; ++$channel) {
                $connection->insert(Schema::DEVICE_CHANNELS, [
                    'device_id' => $id,
                    'channel' => $channel + 1,
                    'name' => $names[$channel] ?? '',
                    'friendly_name' => $friendlyNames[$channel] ?? '',
                ]);
            }
        }
    }

    /**
     * @return string[]
     */
    private function splitNames(int|string $value): array
    {
        $value = (string) $value;

        return '' === $value ? [] : explode('|', $value);
    }

    private function toInt(string $column, string $value): int
    {
        if ('' === $value) {
            return match ($column) {
                'port' => 80,
                'all_off', 'is_updatable' => 1,
                default => 0,
            };
        }

        return (int) $value;
    }
}
