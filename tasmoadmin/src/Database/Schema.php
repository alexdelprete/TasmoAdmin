<?php

namespace TasmoAdmin\Database;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * TasmoAdmin's tables. Every name starts with ta_ so the schema can share a
 * database with other applications: only ta_ tables are compared or changed.
 */
class Schema
{
    public const PREFIX = 'ta_';

    public const DEVICES = 'ta_devices';
    public const DEVICE_CHANNELS = 'ta_device_channels';
    public const SETTINGS = 'ta_settings';
    public const DEVICE_BACKUPS = 'ta_device_backups';
    public const META = 'ta_meta';

    public static function build(): DbalSchema
    {
        $devices = self::table(
            self::DEVICES,
            [
                self::column('id', Types::INTEGER, ['autoincrement' => true]),
                self::column('ip', Types::STRING, ['length' => 255, 'default' => '']),
                self::column('port', Types::INTEGER, ['default' => 80]),
                self::column('username', Types::STRING, ['length' => 255, 'default' => '']),
                self::column('password', Types::TEXT, ['notnull' => false]),
                self::column('img', Types::STRING, ['length' => 64, 'default' => 'bulb_1']),
                self::column('position', Types::INTEGER, ['default' => 0]),
                self::column('all_off', Types::SMALLINT, ['default' => 1]),
                self::column('protect_on', Types::SMALLINT, ['default' => 0]),
                self::column('protect_off', Types::SMALLINT, ['default' => 0]),
                self::column('is_updatable', Types::SMALLINT, ['default' => 1]),
                self::column('confirm_toggle', Types::SMALLINT, ['default' => 0]),
                self::column('mqtt_topic', Types::STRING, ['length' => 255, 'default' => '']),
                self::column('hide_from_startpage', Types::SMALLINT, ['default' => 0]),
            ],
            ['id'],
            [self::index('ta_devices_position', 'position')]
        );

        $channels = self::table(
            self::DEVICE_CHANNELS,
            [
                self::column('device_id', Types::INTEGER),
                self::column('channel', Types::SMALLINT),
                self::column('name', Types::STRING, ['length' => 255, 'default' => '']),
                self::column('friendly_name', Types::STRING, ['length' => 255, 'default' => '']),
            ],
            ['device_id', 'channel'],
            [self::index('ta_device_channels_device_id', 'device_id')],
            [
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('ta_device_channels_device')
                    ->setUnquotedReferencingColumnNames('device_id')
                    ->setUnquotedReferencedTableName(self::DEVICES)
                    ->setUnquotedReferencedColumnNames('id')
                    ->setOnDeleteAction(ReferentialAction::CASCADE)
                    ->create(),
            ]
        );

        $settings = self::table(
            self::SETTINGS,
            [
                self::column('name', Types::STRING, ['length' => 191]),
                self::column('value', Types::TEXT, ['notnull' => false]),
            ],
            ['name']
        );

        $backups = self::table(
            self::DEVICE_BACKUPS,
            [
                self::column('id', Types::INTEGER, ['autoincrement' => true]),
                self::column('device_id', Types::INTEGER),
                self::column('device_name', Types::STRING, ['length' => 255, 'default' => '']),
                self::column('created_at', Types::DATETIME_IMMUTABLE),
                self::column('firmware', Types::STRING, ['length' => 128, 'default' => '']),
                self::column('filename', Types::STRING, ['length' => 255]),
                self::column('size', Types::INTEGER),
                self::column('sha256', Types::STRING, ['length' => 64]),
                self::column('content', Types::BLOB),
            ],
            ['id'],
            [self::index('ta_device_backups_device', 'device_id', 'created_at')]
        );

        $meta = self::table(
            self::META,
            [
                self::column('name', Types::STRING, ['length' => 191]),
                self::column('value', Types::TEXT, ['notnull' => false]),
            ],
            ['name']
        );

        return DbalSchema::editor()
            ->setTables($devices, $channels, $settings, $backups, $meta)
            ->create()
        ;
    }

    /**
     * @return string[]
     */
    public static function tableNames(): array
    {
        return [self::DEVICES, self::DEVICE_CHANNELS, self::SETTINGS, self::DEVICE_BACKUPS, self::META];
    }

    /**
     * @param array{length?: int, default?: int|string, notnull?: bool, autoincrement?: bool} $options
     */
    private static function column(string $name, string $type, array $options = []): Column
    {
        $editor = Column::editor()->setUnquotedName($name)->setTypeName($type);
        if (isset($options['length'])) {
            $editor->setLength($options['length']);
        }
        if (array_key_exists('default', $options)) {
            $editor->setDefaultValue($options['default']);
        }
        if (isset($options['notnull'])) {
            $editor->setNotNull($options['notnull']);
        }
        if (!empty($options['autoincrement'])) {
            $editor->setAutoincrement(true);
        }

        return $editor->create();
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $firstColumn
     * @param non-empty-string ...$otherColumns
     */
    private static function index(string $name, string $firstColumn, string ...$otherColumns): Index
    {
        return Index::editor()
            ->setUnquotedName($name)
            ->setUnquotedColumnNames($firstColumn, ...$otherColumns)
            ->create()
        ;
    }

    /**
     * @param non-empty-string           $name
     * @param list<Column>               $columns
     * @param list<non-empty-string>     $primaryKey
     * @param list<Index>                $indexes
     * @param list<ForeignKeyConstraint> $foreignKeys
     */
    private static function table(string $name, array $columns, array $primaryKey, array $indexes = [], array $foreignKeys = []): Table
    {
        $editor = Table::editor()
            ->setUnquotedName($name)
            ->setColumns(...$columns)
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$primaryKey)->create())
        ;

        if ([] !== $indexes) {
            $editor->setIndexes(...$indexes);
        }
        if ([] !== $foreignKeys) {
            $editor->setForeignKeyConstraints(...$foreignKeys);
        }

        return $editor->create();
    }
}
