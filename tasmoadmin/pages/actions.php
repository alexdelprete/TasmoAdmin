<?php

use TasmoAdmin\Backup\BackupHelper;
use TasmoAdmin\Database\DatabaseExporter;
use TasmoAdmin\Database\SnapshotStore;
use TasmoAdmin\DevicePasswordKeyProvider;
use TasmoAdmin\DeviceRepository;
use TasmoAdmin\Helper\CacheCleanupHelper;
use TasmoAdmin\Helper\FirmwareFolderHelper;
use TasmoAdmin\Helper\SupportedLanguageHelper;
use TasmoAdmin\Sonoff;
use TasmoAdmin\Storage\StorageBackend;

$Sonoff = $container->get(Sonoff::class);

if (isset($_POST['removeDevices'], $_POST['ids'])) {
    $deviceRepository = $container->get(DeviceRepository::class);
    $ids = array_map('intval', explode(',', $_POST['ids']));
    $deviceRepository->removeDevices($ids);

    exit;
}

if (isset($_POST['doAjax'])) {
    session_write_close(); // stop blocking other ajax batch
    if (isset($_POST['target'])) {
        $data = $Sonoff->setDeviceValue((int) $_POST['id'], $_POST['field'], $_POST['newvalue']);
    } else {
        $data = $Sonoff->doAjax($_POST['id'], urldecode($_POST['cmnd']));
    }
    header('Content-Type: application/json');
    echo json_encode($data);

    exit;
}

if (isset($_POST['doAjaxAll'])) {
    session_write_close(); // stop blocking other ajax batch
    $data = $Sonoff->doAjaxAll();

    header('Content-Type: application/json');
    echo json_encode($data);

    exit;
}

if (isset($_GET['i18n'])) {
    $requestedLang = $_GET['lang'] ?? $lang;
    $supportedLanguages = SupportedLanguageHelper::getSupportedLanguages();
    $language = array_key_exists($requestedLang, $supportedLanguages) ? $requestedLang : $lang;
    $cacheFile = _TMPDIR_.'cache/i18n/json_i18n_'.$language.'.cache.json';

    if (!is_file($cacheFile)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Language cache not found']);

        exit;
    }

    header('Content-Type: application/json');
    readfile($cacheFile);

    exit;
}

if (isset($_GET['downloadBackup'])) {
    $backup = $container->get(BackupHelper::class);

    header('Content-type: application/zip');
    header('Content-Disposition: attachment; filename="tasmota-backup.zip"');
    header('Content-Length: '.filesize($backup->getBackupZipPath()));
    ob_clean();
    flush();
    readfile($backup->getBackupZipPath());

    exit;
}

if (isset($_GET['downloadStoredBackup'])) {
    $backup = $container->get(BackupHelper::class)->getStore()?->get((string) $_GET['downloadStoredBackup']);
    if (null === $backup || null === $backup->content) {
        http_response_code(404);

        exit;
    }

    $filename = sprintf('%s-%s', $backup->createdAt->format('Ymd-His'), basename($backup->filename));
    header('Content-type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.str_replace('"', '', $filename).'"');
    header('Content-Length: '.strlen($backup->content));
    ob_clean();
    flush();
    echo $backup->content;

    exit;
}

if (isset($_GET['exportDatabase'])) {
    $connection = $container->get(StorageBackend::class)->getConnection();
    if (null === $connection) {
        http_response_code(404);

        exit;
    }

    $export = (new DatabaseExporter($connection, $container->get(BackupHelper::class)->getStore()))->export();
    header('Content-type: application/gzip');
    header('Content-Disposition: attachment; filename="tasmoadmin-'.date('Ymd-His').'.json.gz"');
    header('Content-Length: '.strlen($export));
    ob_clean();
    flush();
    echo $export;

    exit;
}

if (isset($_GET['exportSnapshot'])) {
    $connection = $container->get(StorageBackend::class)->getConnection();
    $snapshots = null === $connection ? null : new SnapshotStore($connection);
    $requested = (string) $_GET['exportSnapshot'];
    $snapshot = 'latest' === $requested ? $snapshots?->latest() : $snapshots?->get((int) $requested);
    if (null === $snapshot || null === $snapshot->content) {
        http_response_code(404);

        exit;
    }

    header('Content-type: application/gzip');
    header('Content-Disposition: attachment; filename="tasmoadmin-snapshot-'.$snapshot->createdAt->format('Ymd-His').'.json.gz"');
    header('Content-Length: '.strlen($snapshot->content));
    ob_clean();
    flush();
    echo $snapshot->content;

    exit;
}

if (isset($_GET['downloadRestore'])) {
    $backup = $container->get(BackupHelper::class);
    $restoreToken = (string) $_GET['downloadRestore'];
    $restorePath = $backup->getRestoreFilePath($restoreToken);
    if (null === $restorePath) {
        http_response_code(404);

        exit;
    }

    header('Content-type: application/octet-stream');
    header('Content-Disposition: attachment; filename="restore.dmp"');
    header('Content-Length: '.filesize($restorePath));
    ob_clean();
    flush();
    readfile($restorePath);
    $backup->deleteRestoreFile($restoreToken);

    exit;
}

if (isset($_POST['clean'])) {
    $what = explode('_', $_POST['clean']);

    if (array_intersect(['sessions', 'i18n'], $what)) {
        CacheCleanupHelper::cleanTargets(_TMPDIR_, $what);
    }

    if (in_array('firmwares', $what)) {
        FirmwareFolderHelper::clean(_DATADIR_.'firmwares/');
    }

    if (array_intersect(['config', 'devices'], $what)) {
        $resetConnection = $container->get(StorageBackend::class)->getConnection();
        if (null !== $resetConnection) {
            (new SnapshotStore($resetConnection))->create(SnapshotStore::REASON_BEFORE_RESET);
        }
    }

    if (in_array('config', $what)) {
        $container->get(StorageBackend::class)->getConfigStorage()->clear();
        session_destroy();
    }

    if (in_array('devices', $what)) {
        $container->get(StorageBackend::class)->getDeviceStorage()->clear();

        $devicePasswordKeyFile = _DATADIR_.DevicePasswordKeyProvider::SIDECAR_FILENAME;
        if (is_file($devicePasswordKeyFile)) {
            @unlink($devicePasswordKeyFile);
        }
    }

    exit;
}
