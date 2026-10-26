<?php

namespace TasmoAdmin\Backup;

use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\Filesystem\Filesystem;
use TasmoAdmin\Config;
use TasmoAdmin\Device;
use TasmoAdmin\DeviceRepository;
use TasmoAdmin\Sonoff;

class BackupHelper
{
    private DeviceRepository $deviceRepository;

    private Sonoff $sonoff;
    private string $backupPath;

    private Filesystem $filesystem;

    private ?Config $config;

    private string $baseUrl;

    private string $restorePath;

    private bool $allowNonUploadedFiles;

    private ?BackupStore $store;

    public function __construct(
        DeviceRepository $deviceRepository,
        Sonoff $sonoff,
        string $backupPath,
        ?Config $config = null,
        string $baseUrl = '',
        ?string $restorePath = null,
        bool $allowNonUploadedFiles = false,
        ?BackupStore $store = null
    ) {
        $this->deviceRepository = $deviceRepository;
        $this->sonoff = $sonoff;
        $this->backupPath = $backupPath;
        $this->filesystem = new Filesystem();
        $this->config = $config;
        $this->baseUrl = '' === $baseUrl ? '/' : $baseUrl;
        $this->restorePath = $restorePath ?? dirname(rtrim($backupPath, '/')).'/restore/';
        $this->allowNonUploadedFiles = $allowNonUploadedFiles;
        $this->store = $store;
    }

    public function getStore(): ?BackupStore
    {
        return $this->store;
    }

    public function backup(array $deviceIds): BackupResults
    {
        $this->createCleanBackupDir();
        $files = [];
        $results = [];
        foreach ($deviceIds as $deviceId) {
            $device = $this->deviceRepository->getDeviceById($deviceId);
            if (null === $device) {
                continue;
            }

            try {
                $file = $this->sonoff->backup($device, $this->backupPath);
                $files[] = $file;
                $this->storeBackup($device, $file);
                $results[] = new BackupResult($device, true);
            } catch (ConnectException $exception) {
                // Failed to download
                $results[] = new BackupResult($device, false, $exception->getMessage());
            }
        }

        $this->createZip($files);

        return new BackupResults($results);
    }

    /**
     * Restores a backup from the history to the device it was taken from.
     */
    public function restoreStored(int $backupId): BackupResults
    {
        $backup = $this->store?->get($backupId);
        if (null === $backup || null === $backup->content) {
            throw new \RuntimeException(__('RESTORE_BACKUP_NOT_FOUND', 'BACKUP'));
        }

        $device = $this->deviceRepository->getDeviceById($backup->deviceId);
        if (null === $device) {
            throw new \RuntimeException(__('RESTORE_DEVICE_NOT_FOUND', 'BACKUP'));
        }

        $this->createCleanRestoreDir();
        $restoreToken = bin2hex(random_bytes(12));
        if (false === file_put_contents($this->restorePath.$restoreToken.'.dmp', $backup->content)) {
            throw new \RuntimeException(__('RESTORE_FILE_COULD_NOT_SAVE', 'BACKUP'));
        }

        $result = $this->sonoff->restore($device, $this->getRestoreDownloadUrl($restoreToken));
        $failureReason = $result->ERROR ?? $result->WARNING ?? '';
        if ('' !== $failureReason) {
            return new BackupResults([new BackupResult($device, false, (string) $failureReason)]);
        }

        return new BackupResults([new BackupResult($device, true)]);
    }

    /**
     * A zip of the given stored backups, written to the backup folder.
     *
     * @param int[] $backupIds
     */
    public function createZipFromStore(array $backupIds): ?string
    {
        if (null === $this->store) {
            return null;
        }

        $this->createCleanBackupDir();
        $files = [];
        foreach ($backupIds as $backupId) {
            $backup = $this->store->get((int) $backupId);
            if (null === $backup || null === $backup->content) {
                continue;
            }
            $name = sprintf('%s-%s', $backup->createdAt->format('Ymd-His'), basename($backup->filename));
            $path = $this->backupPath.$name;
            file_put_contents($path, $backup->content);
            $files[] = $path;
        }

        return [] === $files ? null : $this->createZip($files);
    }

    public function getBackupZipPath(): string
    {
        return $this->backupPath.'backup.zip';
    }

    public function restore(array $deviceIds, ?array $uploadedFile): BackupResults
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        if (1 !== count($deviceIds)) {
            throw new \RuntimeException(__('RESTORE_SINGLE_DEVICE_ONLY', 'BACKUP'));
        }

        $validatedUpload = $this->validateRestoreUpload($uploadedFile);

        $device = $this->deviceRepository->getDeviceById($deviceIds[0]);
        if (null === $device) {
            throw new \RuntimeException(__('RESTORE_DEVICE_NOT_FOUND', 'BACKUP'));
        }

        $restoreToken = $this->storeRestoreUpload($validatedUpload);
        $result = $this->sonoff->restore($device, $this->getRestoreDownloadUrl($restoreToken));
        $failureReason = $result->ERROR ?? $result->WARNING ?? '';
        if ('' !== $failureReason) {
            return new BackupResults([new BackupResult($device, false, (string) $failureReason)]);
        }

        return new BackupResults([new BackupResult($device, true)]);
    }

    public function getRestoreFilePath(string $restoreToken): ?string
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $restoreToken)) {
            return null;
        }

        $restorePath = $this->restorePath.$restoreToken.'.dmp';

        return is_file($restorePath) ? $restorePath : null;
    }

    public function deleteRestoreFile(string $restoreToken): void
    {
        $restorePath = $this->getRestoreFilePath($restoreToken);
        if (null !== $restorePath) {
            @unlink($restorePath);
        }
    }

    private function storeBackup(Device $device, string $file): void
    {
        if (null === $this->store || !is_file($file)) {
            return;
        }

        $content = (string) file_get_contents($file);
        if ('' === $content) {
            return;
        }

        $this->store->save($device, basename($file), $content, $this->sonoff->getFirmwareVersion($device));

        $keep = null === $this->config ? 0 : (int) $this->config->read('backup_keep_count');
        $this->store->prune($device->id, $keep);
    }

    private function createZip(array $files): string
    {
        $zipFilePath = $this->getBackupZipPath();
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }

        $zip = new \ZipArchive();
        $zip->open($zipFilePath, \ZipArchive::CREATE);
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        return $zipFilePath;
    }

    private function createCleanBackupDir(): void
    {
        if ($this->filesystem->exists($this->backupPath)) {
            $this->filesystem->remove($this->backupPath);
        }

        $this->filesystem->mkdir($this->backupPath);
    }

    private function storeRestoreUpload(array $uploadedFile): string
    {
        $this->createCleanRestoreDir();

        $restoreToken = bin2hex(random_bytes(12));
        $targetPath = $this->restorePath.$restoreToken.'.dmp';

        if (!$this->moveRestoreUpload($uploadedFile['tmp_name'], $targetPath)) {
            throw new \RuntimeException(__('RESTORE_FILE_COULD_NOT_SAVE', 'BACKUP'));
        }

        return $restoreToken;
    }

    private function validateRestoreUpload(?array $uploadedFile): array
    {
        if (null === $uploadedFile || !isset($uploadedFile['error']) || is_array($uploadedFile['error'])) {
            throw new \RuntimeException(__('RESTORE_FILE_REQUIRED', 'BACKUP'));
        }

        switch ($uploadedFile['error']) {
            case UPLOAD_ERR_OK:
                break;

            case UPLOAD_ERR_NO_FILE:
                throw new \RuntimeException(__('RESTORE_FILE_REQUIRED', 'BACKUP'));

            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new \RuntimeException(__('RESTORE_FILE_TOO_BIG', 'BACKUP'));

            default:
                throw new \RuntimeException(__('RESTORE_FILE_INVALID', 'BACKUP'));
        }

        $filename = (string) ($uploadedFile['name'] ?? '');
        if (!str_ends_with(strtolower($filename), '.dmp')) {
            throw new \RuntimeException(__('RESTORE_FILE_WRONG_FORMAT', 'BACKUP'));
        }

        if ((int) ($uploadedFile['size'] ?? 0) <= 0 || empty($uploadedFile['tmp_name'])) {
            throw new \RuntimeException(__('RESTORE_FILE_INVALID', 'BACKUP'));
        }

        return $uploadedFile;
    }

    private function createCleanRestoreDir(): void
    {
        if ($this->filesystem->exists($this->restorePath)) {
            $this->filesystem->remove($this->restorePath);
        }

        $this->filesystem->mkdir($this->restorePath);
    }

    private function moveRestoreUpload(string $sourcePath, string $targetPath): bool
    {
        if (is_uploaded_file($sourcePath)) {
            return move_uploaded_file($sourcePath, $targetPath);
        }

        if (!$this->allowNonUploadedFiles) {
            return false;
        }

        if (@rename($sourcePath, $targetPath)) {
            return true;
        }

        return @copy($sourcePath, $targetPath);
    }

    private function getRestoreDownloadUrl(string $restoreToken): string
    {
        if (null === $this->config) {
            throw new \RuntimeException(__('RESTORE_SERVER_NOT_CONFIGURED', 'BACKUP'));
        }

        return sprintf(
            'http://%s:%s%sactions?downloadRestore=%s',
            $this->config->read('ota_server_ip'),
            $this->config->read('ota_server_port'),
            $this->baseUrl,
            $restoreToken
        );
    }
}
