<?php

namespace TasmoAdmin\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use TasmoAdmin\Database\Schema;
use TasmoAdmin\Device;

/**
 * Device backups kept in ta_device_backups.
 */
class DatabaseBackupStore implements BackupStorage
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getType(): string
    {
        return self::DATABASE;
    }

    public function save(Device $device, string $filename, string $content, string $firmware = '', ?\DateTimeImmutable $createdAt = null): string
    {
        return $this->store(StoredBackup::create($device, $device->getName(), $filename, $content, $firmware, $createdAt));
    }

    public function store(StoredBackup $backup): string
    {
        $content = (string) $backup->content;
        $this->connection->insert(
            Schema::DEVICE_BACKUPS,
            [
                'device_id' => $backup->deviceId,
                'device_name' => $backup->deviceName,
                'created_at' => $backup->createdAt->setTimezone(new \DateTimeZone('UTC')),
                'firmware' => $backup->firmware,
                'filename' => $backup->filename,
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

        return (string) $this->connection->lastInsertId();
    }

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

    public function get(string $id): ?StoredBackup
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', Schema::DEVICE_BACKUPS), [(int) $id]);

        return false === $row ? null : $this->toBackup($row, true);
    }

    public function delete(string $id): void
    {
        if (ctype_digit($id)) {
            $this->connection->delete(Schema::DEVICE_BACKUPS, ['id' => (int) $id]);
        }
    }

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

    public function clear(): void
    {
        $this->connection->executeStatement(sprintf('DELETE FROM %s', Schema::DEVICE_BACKUPS));
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
            (string) $row['id'],
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
