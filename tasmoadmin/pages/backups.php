<?php

use TasmoAdmin\Backup\BackupHelper;
use TasmoAdmin\Backup\BackupStorage;
use TasmoAdmin\Backup\BackupStorageFactory;
use TasmoAdmin\Backup\BackupStorageMigrator;
use TasmoAdmin\Backup\StoredBackup;
use TasmoAdmin\Database\DatabaseExporter;
use TasmoAdmin\Database\SnapshotStore;
use TasmoAdmin\DeviceRepository;
use TasmoAdmin\Helper\HtmlAttributeHelper;
use TasmoAdmin\Helper\RequestHelper;
use TasmoAdmin\Storage\StorageBackend;

$backupHelper = $container->get(BackupHelper::class);
$store = $backupHelper->getStore();
$storageBackend = $container->get(StorageBackend::class);
$connection = $storageBackend->getConnection();
$storageFactory = new BackupStorageFactory($connection, _DATADIR_);
$snapshots = null === $connection ? null : new SnapshotStore($connection);
$deviceRepository = $container->get(DeviceRepository::class);
$messages = [];

$escape = static fn (string $value): string => HtmlAttributeHelper::escape($value);
$formatSize = static fn (int $bytes): string => $bytes >= 1024 ? sprintf('%.1f KB', $bytes / 1024) : sprintf('%d B', $bytes);
$localTime = static fn (DateTimeImmutable $time): string => $time->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');

if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
    $action = (string) ($_POST['backups_action'] ?? '');

    try {
        switch ($action) {
            case 'backup_all':
                $ids = array_map(static fn ($device): int => $device->id, $deviceRepository->getDevices());
                $results = $backupHelper->backup($ids);
                $messages[] = ['success', __('BACKUPS_CREATED', 'BACKUP')];
                foreach ($results->getFailures() as $failure) {
                    $messages[] = ['warning', $failure->getDevice()->getName().': '.$failure->getFailureReason()];
                }

                break;

            case 'restore':
                $results = $backupHelper->restoreStored((string) ($_POST['backup_id'] ?? ''));
                if ($results->successful()) {
                    $messages[] = ['success', __('RESTORE_STARTED', 'BACKUP')];
                } else {
                    foreach ($results->getFailures() as $failure) {
                        $messages[] = ['danger', __('RESTORE_FAILED', 'BACKUP').' '.$failure->getDevice()->getName().': '.$failure->getFailureReason()];
                    }
                }

                break;

            case 'delete':
                $store?->delete((string) ($_POST['backup_id'] ?? ''));
                $messages[] = ['success', __('BACKUP_DELETED', 'BACKUP')];

                break;

            case 'device_settings':
                $Config->write('backup_keep_count', (string) max(0, min(1000, (int) ($_POST['backup_keep_count'] ?? 10))));
                $target = (string) ($_POST['backup_storage'] ?? BackupStorage::DATABASE);
                if (null !== $store && $storageFactory->hasDatabase() && in_array($target, [BackupStorage::DATABASE, BackupStorage::FILESYSTEM], true) && $target !== $store->getType()) {
                    $targetStore = $storageFactory->create($target);
                    $moved = (new BackupStorageMigrator())->migrate($store, $targetStore);
                    $Config->write(BackupStorageFactory::SETTING, $target);
                    $store = $targetStore;
                    $messages[] = ['success', sprintf(__('BACKUP_STORAGE_MOVED', 'BACKUP'), $moved, __(BackupStorage::DATABASE === $target ? 'BACKUP_STORAGE_DATABASE' : 'BACKUP_STORAGE_FILESYSTEM', 'BACKUP'))];
                }
                $messages[] = ['success', __('BACKUP_KEEP_SAVED', 'BACKUP')];

                break;

            case 'snapshot_create':
                $snapshots?->create(SnapshotStore::REASON_MANUAL);
                $messages[] = ['success', __('SNAPSHOT_CREATED', 'BACKUP')];

                break;

            case 'snapshot_restore':
                $counts = $snapshots?->restore((int) ($_POST['snapshot_id'] ?? 0)) ?? [];
                $messages[] = ['success', sprintf(__('SNAPSHOT_RESTORED', 'BACKUP'), $counts['ta_devices'] ?? 0, $counts['ta_settings'] ?? 0)];

                break;

            case 'snapshot_delete':
                $snapshots?->delete((int) ($_POST['snapshot_id'] ?? 0));
                $messages[] = ['success', __('SNAPSHOT_DELETED', 'BACKUP')];

                break;

            case 'snapshot_settings':
                $Config->write(SnapshotStore::KEEP_SETTING, (string) max(0, min(1000, (int) ($_POST['snapshot_keep_count'] ?? SnapshotStore::DEFAULT_KEEP))));
                $messages[] = ['success', __('SNAPSHOT_KEEP_SAVED', 'BACKUP')];

                break;

            case 'import_database':
                if (null === $connection) {
                    break;
                }
                $upload = $_FILES['database_export'] ?? null;
                if (!isset($_POST['confirm_import'])) {
                    throw new RuntimeException(__('DATABASE_IMPORT_CONFIRM_REQUIRED', 'BACKUP'));
                }
                if (!is_array($upload) || UPLOAD_ERR_OK !== ($upload['error'] ?? UPLOAD_ERR_NO_FILE) || !is_uploaded_file((string) $upload['tmp_name'])) {
                    throw new RuntimeException(__('RESTORE_FILE_REQUIRED', 'BACKUP'));
                }
                $snapshots?->create(SnapshotStore::REASON_BEFORE_IMPORT);
                $counts = (new DatabaseExporter($connection, $store))->import((string) file_get_contents((string) $upload['tmp_name']));
                $messages[] = ['success', sprintf(__('DATABASE_IMPORTED', 'BACKUP'), $counts['ta_devices'] ?? 0, $counts['ta_settings'] ?? 0, $counts['ta_device_backups'] ?? 0)];

                break;
        }
    } catch (Throwable $exception) {
        $messages[] = ['danger', $exception->getMessage()];
    }
}

$backupsByDevice = [];
foreach (null === $store ? [] : $store->list() as $backup) {
    $backupsByDevice[$backup->deviceId][] = $backup;
}
$deviceNames = [];
foreach ($deviceRepository->getDevices() as $device) {
    $deviceNames[$device->id] = $device->getName();
}
$keepCount = (int) $Config->read('backup_keep_count');
$storageType = null === $store ? BackupStorage::FILESYSTEM : $store->getType();
$snapshotKeep = null === $snapshots ? 0 : $snapshots->getKeepCount();
$snapshotList = null === $snapshots ? [] : $snapshots->list();
?>
<div class='row justify-content-center backups-page'>
    <div class='col col-12 col-xl-10'>
        <h2 class='text-sm-center mb-4'><?php echo __('BACKUPS', 'PAGE_TITLES'); ?></h2>

        <?php foreach ($messages as [$type, $text]) { ?>
            <div class="alert alert-<?php echo $escape($type); ?>" role="alert"><?php echo $escape((string) $text); ?></div>
        <?php } ?>

        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <h5 class="card-title mb-0"><?php echo __('DEVICE_BACKUPS', 'BACKUP'); ?></h5>
                    <form method="post">
                        <?php echo RequestHelper::csrfTokenField(); ?>
                        <input type="hidden" name="backups_action" value="backup_all">
                        <button type="submit" class="btn btn-primary"><?php echo __('BACKUP_ALL_NOW', 'BACKUP'); ?></button>
                    </form>
                </div>

                <form method="post" class="d-flex flex-wrap align-items-end gap-3 mb-2">
                    <?php echo RequestHelper::csrfTokenField(); ?>
                    <input type="hidden" name="backups_action" value="device_settings">
                    <div>
                        <label for="backup_storage" class="form-label mb-1"><?php echo __('BACKUP_STORAGE_LABEL', 'BACKUP'); ?></label>
                        <select class="form-select" id="backup_storage" name="backup_storage" <?php echo $storageFactory->hasDatabase() ? '' : 'disabled'; ?>
                                data-confirm="<?php echo $escape(__('BACKUP_STORAGE_CONFIRM', 'BACKUP')); ?>">
                            <option value="<?php echo BackupStorage::DATABASE; ?>" <?php echo BackupStorage::DATABASE === $storageType ? 'selected' : ''; ?>><?php echo __('BACKUP_STORAGE_DATABASE', 'BACKUP'); ?></option>
                            <option value="<?php echo BackupStorage::FILESYSTEM; ?>" <?php echo BackupStorage::FILESYSTEM === $storageType ? 'selected' : ''; ?>><?php echo __('BACKUP_STORAGE_FILESYSTEM', 'BACKUP'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="backup_keep_count" class="form-label mb-1"><?php echo __('BACKUP_KEEP_LABEL', 'BACKUP'); ?></label>
                        <input type="number" min="0" max="1000" class="form-control" style="width: 7rem" id="backup_keep_count" name="backup_keep_count" value="<?php echo $keepCount; ?>">
                    </div>
                    <button type="submit" class="btn btn-secondary"
                            onclick="var s=document.getElementById('backup_storage'); return s.disabled || s.value === '<?php echo $escape($storageType); ?>' || confirm(s.dataset.confirm);"><?php echo __('BTN_SAVE_USER_CONFIG', 'USER_CONFIG'); ?></button>
                </form>
                <small class="form-text text-body-secondary d-block mb-3"><?php echo __('BACKUP_STORAGE_HELP', 'BACKUP'); ?> <?php echo __('BACKUP_KEEP_HELP', 'BACKUP'); ?></small>

                <?php if ([] === $backupsByDevice) { ?>
                    <p class="text-body-secondary mb-0"><?php echo __('NO_BACKUPS_YET', 'BACKUP'); ?></p>
                <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table backups-table align-middle">
                            <thead>
                            <tr>
                                <th scope="col"><?php echo __('TABLE_HEAD_NAME', 'DEVICES'); ?></th>
                                <th scope="col"><?php echo __('BACKUP_DATE', 'BACKUP'); ?></th>
                                <th scope="col"><?php echo __('TABLE_HEAD_VERSION', 'DEVICES'); ?></th>
                                <th scope="col" class="text-end"><?php echo __('BACKUP_SIZE', 'BACKUP'); ?></th>
                                <th scope="col" class="text-end"><?php echo __('BACKUP_ACTIONS', 'BACKUP'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($backupsByDevice as $deviceId => $deviceBackups) {
                                /** @var StoredBackup $backup */
                                foreach ($deviceBackups as $index => $backup) {
                                    $deviceExists = isset($deviceNames[$deviceId]);
                                    ?>
                                <tr>
                                    <td>
                                        <?php if (0 === $index) { ?>
                                            <strong><?php echo $escape($deviceNames[$deviceId] ?? $backup->deviceName); ?></strong>
                                            <?php if (!$deviceExists) { ?><small class="text-body-secondary">(<?php echo __('BACKUP_DEVICE_REMOVED', 'BACKUP'); ?>)</small><?php } ?>
                                        <?php } ?>
                                    </td>
                                    <td title="<?php echo $escape($backup->sha256); ?>"><?php echo $escape($localTime($backup->createdAt)); ?></td>
                                    <td><?php echo $escape('' !== $backup->firmware ? $backup->firmware : '-'); ?></td>
                                    <td class="text-end"><?php echo $escape($formatSize($backup->size)); ?></td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-secondary btn-sm" href="<?php echo _BASEURL_; ?>actions?downloadStoredBackup=<?php echo rawurlencode($backup->id); ?>"><?php echo __('DOWNLOAD', 'BACKUP'); ?></a>
                                        <?php if ($deviceExists) { ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm(this.dataset.confirm);" data-confirm="<?php echo $escape(__('RESTORE_CONFIRM', 'BACKUP')); ?>">
                                            <?php echo RequestHelper::csrfTokenField(); ?>
                                            <input type="hidden" name="backups_action" value="restore">
                                            <input type="hidden" name="backup_id" value="<?php echo $escape($backup->id); ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm"><?php echo __('BATCH_BACKUP_RESTORE', 'BACKUP'); ?></button>
                                        </form>
                                        <?php } ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm(this.dataset.confirm);" data-confirm="<?php echo $escape(__('BACKUP_DELETE_CONFIRM', 'BACKUP')); ?>">
                                            <?php echo RequestHelper::csrfTokenField(); ?>
                                            <input type="hidden" name="backups_action" value="delete">
                                            <input type="hidden" name="backup_id" value="<?php echo $escape($backup->id); ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"><?php echo __('BACKUP_DELETE', 'BACKUP'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php }
                                } ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="form-text text-body-secondary d-block"><?php echo __('RESTORE_WARNING', 'BACKUP'); ?></small>
                <?php } ?>
            </div>
        </div>

        <?php if (null === $connection) { ?>
            <div class="alert alert-info"><?php echo __('BACKUPS_NEED_DATABASE', 'BACKUP'); ?></div>
        <?php } else { ?>

        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <h5 class="card-title mb-0"><?php echo __('SNAPSHOTS', 'BACKUP'); ?></h5>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <a class="btn btn-secondary" href="<?php echo _BASEURL_; ?>actions?exportSnapshot=latest"><?php echo __('SNAPSHOT_EXPORT_LATEST', 'BACKUP'); ?></a>
                        <form method="post">
                            <?php echo RequestHelper::csrfTokenField(); ?>
                            <input type="hidden" name="backups_action" value="snapshot_create">
                            <button type="submit" class="btn btn-primary"><?php echo __('SNAPSHOT_NOW', 'BACKUP'); ?></button>
                        </form>
                    </div>
                </div>
                <form method="post" class="d-flex flex-wrap align-items-end gap-3 mb-2">
                    <?php echo RequestHelper::csrfTokenField(); ?>
                    <input type="hidden" name="backups_action" value="snapshot_settings">
                    <div>
                        <label for="snapshot_keep_count" class="form-label mb-1"><?php echo __('SNAPSHOT_KEEP_LABEL', 'BACKUP'); ?></label>
                        <input type="number" min="0" max="1000" class="form-control" style="width: 7rem" id="snapshot_keep_count" name="snapshot_keep_count" value="<?php echo $snapshotKeep; ?>">
                    </div>
                    <button type="submit" class="btn btn-secondary"><?php echo __('BTN_SAVE_USER_CONFIG', 'USER_CONFIG'); ?></button>
                </form>
                <small class="form-text text-body-secondary d-block mb-3"><?php echo __('SNAPSHOTS_HELP', 'BACKUP'); ?></small>

                <?php if ([] === $snapshotList) { ?>
                    <p class="text-body-secondary mb-0"><?php echo __('NO_SNAPSHOTS_YET', 'BACKUP'); ?></p>
                <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table snapshots-table align-middle">
                            <thead>
                            <tr>
                                <th scope="col"><?php echo __('BACKUP_DATE', 'BACKUP'); ?></th>
                                <th scope="col"><?php echo __('SNAPSHOT_REASON', 'BACKUP'); ?></th>
                                <th scope="col" class="text-end"><?php echo __('DEVICES', 'NAVI'); ?></th>
                                <th scope="col" class="text-end"><?php echo __('SNAPSHOT_SETTINGS', 'BACKUP'); ?></th>
                                <th scope="col" class="text-end"><?php echo __('BACKUP_SIZE', 'BACKUP'); ?></th>
                                <th scope="col" class="text-end"><?php echo __('BACKUP_ACTIONS', 'BACKUP'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($snapshotList as $snapshot) { ?>
                                <tr>
                                    <td><?php echo $escape($localTime($snapshot->createdAt)); ?></td>
                                    <td><?php echo $escape(__('SNAPSHOT_REASON_'.strtoupper(str_replace('-', '_', $snapshot->reason)), 'BACKUP')); ?></td>
                                    <td class="text-end"><?php echo $snapshot->devices; ?></td>
                                    <td class="text-end"><?php echo $snapshot->settings; ?></td>
                                    <td class="text-end"><?php echo $escape($formatSize($snapshot->size)); ?></td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-secondary btn-sm" href="<?php echo _BASEURL_; ?>actions?exportSnapshot=<?php echo $snapshot->id; ?>"><?php echo __('DOWNLOAD', 'BACKUP'); ?></a>
                                        <form method="post" class="d-inline" onsubmit="return confirm(this.dataset.confirm);" data-confirm="<?php echo $escape(__('SNAPSHOT_RESTORE_CONFIRM', 'BACKUP')); ?>">
                                            <?php echo RequestHelper::csrfTokenField(); ?>
                                            <input type="hidden" name="backups_action" value="snapshot_restore">
                                            <input type="hidden" name="snapshot_id" value="<?php echo $snapshot->id; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm"><?php echo __('BATCH_BACKUP_RESTORE', 'BACKUP'); ?></button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm(this.dataset.confirm);" data-confirm="<?php echo $escape(__('SNAPSHOT_DELETE_CONFIRM', 'BACKUP')); ?>">
                                            <?php echo RequestHelper::csrfTokenField(); ?>
                                            <input type="hidden" name="backups_action" value="snapshot_delete">
                                            <input type="hidden" name="snapshot_id" value="<?php echo $snapshot->id; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"><?php echo __('BACKUP_DELETE', 'BACKUP'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title"><?php echo __('DATABASE_BACKUP', 'BACKUP'); ?></h5>
                <p class="text-body-secondary mb-3">
                    <?php echo $escape(sprintf(__('DATABASE_IN_USE', 'BACKUP'), $storageBackend->describe())); ?>
                </p>
                <?php $counts = (new DatabaseExporter($connection, $store))->counts(); ?>
                <p class="mb-3">
                    <?php echo $escape(sprintf(__('DATABASE_COUNTS', 'BACKUP'), $counts['ta_devices'], $counts['ta_settings'], $counts['ta_device_backups'])); ?>
                </p>
                <div class="d-flex flex-wrap gap-4 align-items-start">
                    <div>
                        <a class="btn btn-primary" href="<?php echo _BASEURL_; ?>actions?exportDatabase"><?php echo __('DATABASE_EXPORT', 'BACKUP'); ?></a>
                        <small class="form-text text-body-secondary d-block mt-2" style="max-width: 28rem"><?php echo __('DATABASE_EXPORT_HELP', 'BACKUP'); ?></small>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="d-flex flex-column gap-2" style="max-width: 32rem">
                        <?php echo RequestHelper::csrfTokenField(); ?>
                        <input type="hidden" name="backups_action" value="import_database">
                        <label for="database_export" class="mb-0"><?php echo __('DATABASE_IMPORT', 'BACKUP'); ?></label>
                        <input type="file" class="form-control" id="database_export" name="database_export" accept=".gz,.json">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirm_import" name="confirm_import" value="1">
                            <label class="form-check-label" for="confirm_import"><?php echo __('DATABASE_IMPORT_CONFIRM', 'BACKUP'); ?></label>
                        </div>
                        <div><button type="submit" class="btn btn-danger"><?php echo __('DATABASE_IMPORT', 'BACKUP'); ?></button></div>
                    </form>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
