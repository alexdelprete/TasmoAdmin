<?php

namespace TasmoAdmin\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use TasmoAdmin\Database\Schema;
use TasmoAdmin\Device;

/**
 * Device configuration backups (.dmp files) kept in ta_device_backups.
 */
class BackupStore
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function save(Device $device, string $filename, string $content, string $firmware = '', ?\DateTimeImmutable $createdAt = null): int
    {
        $this->connection->insert(
            Schema::DEVICE_BACKUPS,
            [
                'device_id' => $device->id,
                'device_name' => mb_substr($device->getName(), 0, 255),
                'created_at' => $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'firmware' => mb_substr($firmware, 0, 128),
                'filename' => mb_substr($filename, 0, 255),
                'size' => strlen($content),
                'sha256' => hash('sha256', $content),
                'content' => $content,
            ],
            [
                'device_id' => ParameterType::INTEGER,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'size' => ParameterType::INTEGER,
                'content' => ParameterType::LARGE_OBJECT,
            ]
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Backups without their content, newest first.
     *
     * @return list<StoredBackup>
     */
    public function list(?int $deviceId = null): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'device_id', 'device_name', 'created_at', 'firmware', 'filename', 'size', 'sha256')
            ->from(Schema::DEVICE_BACKUPS)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
        ;
        if (null !== $deviceId) {
            $query->where('device_id = :device')->setParameter('device', $deviceId, ParameterType::INTEGER);
        }

        return array_map(fn (array $row): StoredBackup => $this->toBackup($row), $query->fetchAllAssociative());
    }

    public function get(int $id): ?StoredBackup
    {
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', Schema::DEVICE_BACKUPS), [$id]);

        return false === $row ? null : $this->toBackup($row, true);
    }

    public function delete(int $id): void
    {
        $this->connection->delete(Schema::DEVICE_BACKUPS, ['id' => $id]);
    }

    /**
     * Keeps the newest $keep backups of a device; 0 keeps all of them.
     *
     * @return int the number of backups removed
     */
    public function prune(int $deviceId, int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $removed = 0;
        foreach (array_slice($this->list($deviceId), $keep) as $backup) {
            $this->delete($backup->id);
            ++$removed;
        }

        return $removed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toBackup(array $row, bool $withContent = false): StoredBackup
    {
        $content = null;
        if ($withContent) {
            $content = $row['content'];
            if (is_resource($content)) {
                $content = (string) stream_get_contents($content);
            }
            $content = (string) $content;
        }

        return new StoredBackup(
            (int) $row['id'],
            (int) $row['device_id'],
            (string) $row['device_name'],
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            (string) $row['firmware'],
            (string) $row['filename'],
            (int) $row['size'],
            (string) $row['sha256'],
            $content
        );
    }
}
