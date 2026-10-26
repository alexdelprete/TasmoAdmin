<?php

namespace TasmoAdmin\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Snapshots of TasmoAdmin's own data in ta_snapshots: taken daily, before
 * imports, resets and restores, and on demand. Each snapshot is an export in
 * the same format as "Export everything", without the device backups.
 */
class SnapshotStore
{
    public const DAILY_INTERVAL = 86400;
    public const DEFAULT_KEEP = 30;
    public const KEEP_SETTING = 'snapshot_keep_count';

    public const REASON_DAILY = 'daily';
    public const REASON_MANUAL = 'manual';
    public const REASON_BEFORE_IMPORT = 'before-import';
    public const REASON_BEFORE_RESET = 'before-reset';
    public const REASON_BEFORE_RESTORE = 'before-restore';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function create(string $reason, ?\DateTimeImmutable $createdAt = null): int
    {
        $exporter = new DatabaseExporter($this->connection);
        $content = $exporter->export(DatabaseExporter::DATA_TABLES);
        $counts = $exporter->counts();

        $this->connection->insert(
            Schema::SNAPSHOTS,
            [
                'created_at' => $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'reason' => mb_substr($reason, 0, 32),
                'devices' => $counts[Schema::DEVICES],
                'settings' => $counts[Schema::SETTINGS],
                'size' => strlen($content),
                'content' => $content,
            ],
            ['created_at' => Types::DATETIME_IMMUTABLE, 'content' => ParameterType::LARGE_OBJECT]
        );
        $id = (int) $this->connection->lastInsertId();

        $this->prune($this->getKeepCount());

        return $id;
    }

    /**
     * Takes a snapshot when the newest one is older than $interval seconds and
     * there is something to keep.
     */
    public function createIfDue(string $reason = self::REASON_DAILY, int $interval = self::DAILY_INTERVAL, ?\DateTimeImmutable $now = null): ?int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $latest = $this->connection->fetchOne(sprintf('SELECT MAX(created_at) FROM %s', Schema::SNAPSHOTS));
        if (is_string($latest) && '' !== $latest) {
            $age = $now->getTimestamp() - (new \DateTimeImmutable($latest, new \DateTimeZone('UTC')))->getTimestamp();
            if ($age < $interval) {
                return null;
            }
        }

        $hasData = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', Schema::DEVICES)) > 0
            || (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', Schema::SETTINGS)) > 0;

        return $hasData ? $this->create($reason, $now) : null;
    }

    /**
     * Snapshots without their content, newest first.
     *
     * @return list<Snapshot>
     */
    public function list(): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT id, created_at, reason, devices, settings, size FROM %s ORDER BY created_at DESC, id DESC',
            Schema::SNAPSHOTS
        ));

        return array_map(fn (array $row): Snapshot => $this->toSnapshot($row), $rows);
    }

    public function get(int $id): ?Snapshot
    {
        $row = $this->connection->fetchAssociative(sprintf('SELECT * FROM %s WHERE id = ?', Schema::SNAPSHOTS), [$id]);

        return false === $row ? null : $this->toSnapshot($row, true);
    }

    public function latest(): ?Snapshot
    {
        $id = $this->connection->fetchOne(sprintf('SELECT id FROM %s ORDER BY created_at DESC, id DESC', Schema::SNAPSHOTS));

        return false === $id ? null : $this->get((int) $id);
    }

    public function delete(int $id): void
    {
        $this->connection->delete(Schema::SNAPSHOTS, ['id' => $id]);
    }

    /**
     * @return int the number of snapshots removed
     */
    public function prune(int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $removed = 0;
        foreach (array_slice($this->list(), $keep) as $snapshot) {
            $this->delete($snapshot->id);
            ++$removed;
        }

        return $removed;
    }

    /**
     * Restores a snapshot after taking a snapshot of the current data, so the
     * restore can be undone.
     *
     * @return array<string, int> rows restored per table
     */
    public function restore(int $id): array
    {
        $snapshot = $this->get($id);
        if (null === $snapshot || null === $snapshot->content) {
            throw new \RuntimeException(sprintf('Snapshot %d does not exist.', $id));
        }

        $this->create(self::REASON_BEFORE_RESTORE);

        return (new DatabaseExporter($this->connection))->import($snapshot->content);
    }

    public function getKeepCount(): int
    {
        $value = $this->connection->fetchOne(sprintf('SELECT value FROM %s WHERE name = ?', Schema::SETTINGS), [self::KEEP_SETTING]);

        return false === $value || '' === (string) $value ? self::DEFAULT_KEEP : max(0, (int) $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSnapshot(array $row, bool $withContent = false): Snapshot
    {
        $content = null;
        if ($withContent) {
            $content = $row['content'];
            if (is_resource($content)) {
                $content = (string) stream_get_contents($content);
            }
            $content = (string) $content;
        }

        return new Snapshot(
            (int) $row['id'],
            new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            (string) $row['reason'],
            (int) $row['devices'],
            (int) $row['settings'],
            (int) $row['size'],
            $content
        );
    }
}
