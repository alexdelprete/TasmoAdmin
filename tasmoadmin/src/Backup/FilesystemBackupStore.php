<?php

namespace TasmoAdmin\Backup;

use Symfony\Component\Filesystem\Filesystem;
use TasmoAdmin\Device;

/**
 * Device backups kept in the data folder: <dir>/<device id>/<date>-<sha>.dmp,
 * each with a .json file holding its metadata.
 */
class FilesystemBackupStore implements BackupStorage
{
    private const ID_PATTERN = '#^(\d+)/(\d{8}-\d{6}-[0-9a-f]{12})$#';

    private string $directory;

    private Filesystem $filesystem;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/').'/';
        $this->filesystem = new Filesystem();
    }

    public function getType(): string
    {
        return self::FILESYSTEM;
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function save(Device $device, string $filename, string $content, string $firmware = '', ?\DateTimeImmutable $createdAt = null): string
    {
        return $this->store(StoredBackup::create($device, $device->getName(), $filename, $content, $firmware, $createdAt));
    }

    public function store(StoredBackup $backup): string
    {
        $content = (string) $backup->content;
        $sha256 = hash('sha256', $content);
        $createdAt = $backup->createdAt->setTimezone(new \DateTimeZone('UTC'));
        $id = sprintf('%d/%s-%s', $backup->deviceId, $createdAt->format('Ymd-His'), substr($sha256, 0, 12));

        $metadata = [
            'device_id' => $backup->deviceId,
            'device_name' => $backup->deviceName,
            'created_at' => $createdAt->format(DATE_ATOM),
            'firmware' => $backup->firmware,
            'filename' => $backup->filename,
            'size' => strlen($content),
            'sha256' => $sha256,
        ];

        $this->filesystem->mkdir($this->directory.$backup->deviceId);
        $this->filesystem->dumpFile($this->directory.$id.'.dmp', $content);
        $this->filesystem->dumpFile($this->directory.$id.'.json', (string) json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $id;
    }

    public function list(?int $deviceId = null): array
    {
        $pattern = null === $deviceId ? $this->directory.'*/*.json' : $this->directory.$deviceId.'/*.json';
        $backups = [];
        foreach (glob($pattern) ?: [] as $metadataFile) {
            $relative = substr($metadataFile, strlen($this->directory), -5);
            $backup = $this->read($relative, false);
            if (null !== $backup) {
                $backups[] = $backup;
            }
        }

        usort($backups, static fn (StoredBackup $a, StoredBackup $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $backups;
    }

    public function get(string $id): ?StoredBackup
    {
        return $this->read($id, true);
    }

    public function delete(string $id): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return;
        }

        $this->filesystem->remove([$this->directory.$id.'.dmp', $this->directory.$id.'.json']);
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
        foreach ($this->list() as $backup) {
            $this->delete($backup->id);
        }
    }

    private function read(string $id, bool $withContent): ?StoredBackup
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        $metadataFile = $this->directory.$id.'.json';
        $contentFile = $this->directory.$id.'.dmp';
        if (!is_file($metadataFile) || !is_file($contentFile)) {
            return null;
        }

        $metadata = json_decode((string) file_get_contents($metadataFile), true);
        if (!is_array($metadata)) {
            return null;
        }

        return new StoredBackup(
            $id,
            (int) ($metadata['device_id'] ?? 0),
            (string) ($metadata['device_name'] ?? ''),
            new \DateTimeImmutable((string) ($metadata['created_at'] ?? 'now'), new \DateTimeZone('UTC')),
            (string) ($metadata['firmware'] ?? ''),
            (string) ($metadata['filename'] ?? basename($contentFile)),
            (int) ($metadata['size'] ?? filesize($contentFile)),
            (string) ($metadata['sha256'] ?? ''),
            $withContent ? (string) file_get_contents($contentFile) : null
        );
    }
}
