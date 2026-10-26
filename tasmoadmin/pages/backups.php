<?php

use TasmoAdmin\Backup\BackupHelper;
use TasmoAdmin\Backup\StoredBackup;
use TasmoAdmin\Database\DatabaseExporter;
use TasmoAdmin\DeviceRepository;
use TasmoAdmin\Helper\HtmlAttributeHelper;
use TasmoAdmin\Helper\RequestHelper;
use TasmoAdmin\Storage\StorageBackend;

$backupHelper = $container->get(BackupHelper::class);
$store = $backupHelper->getStore();
$storageBackend = $container->get(StorageBackend::class);
$connection = $storageBackend->getConnection();
$exporter = null === $connection ? null : new DatabaseExporter($connection);
$deviceRepository = $container->get(DeviceRepository::class);
$messages = [];

$escape = static fn (string $value): string => HtmlAttributeHelper::escape($value);
$formatSize = static fn (int $bytes): string => $bytes >= 1024 ? sprintf('%.1f KB', $bytes / 1024) : sprintf('%d B', $bytes);

if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && null !== $connection) {
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
                $results = $backupHelper->restoreStored((int) ($_POST['backup_id'] ?? 0));
                if ($results->successful()) {
                    $messages[] = ['success', __('RESTORE_STARTED', 'BACKUP')];
                } else {
                    foreach ($results->getFailures() as $failure) {
                        $messages[] = ['danger', __('RESTORE_FAILED', 'BACKUP').' '.$failure->getDevice()->getName().': '.$failure->getFailureReason()];
                    }
                }

                break;

            case 'delete':
                $store?->delete((int) ($_POST['backup_id'] ?? 0));
                $messages[] = ['success', __('BACKUP_DELETED', 'BACKUP')];

                break;

            case 'keep':
                $Config->write('backup_keep_count', (string) max(0, min(1000, (int) ($_POST['backup_keep_count'] ?? 10))));
                $messages[] = ['success', __('BACKUP_KEEP_SAVED', 'BACKUP')];

                break;

            case 'import_database':
                $upload = $_FILES['database_export'] ?? null;
                if (!isset($_POST['confirm_import'])) {
                    throw new RuntimeException(__('DATABASE_IMPORT_CONFIRM_REQUIRED', 'BACKUP'));
                }
                if (!is_array($upload) || UPLOAD_ERR_OK !== ($upload['error'] ?? UPLOAD_ERR_NO_FILE) || !is_uploaded_file((string) $upload['tmp_name'])) {
                    throw new RuntimeException(__('RESTORE_FILE_REQUIRED', 'BACKUP'));
                }
                $counts = $exporter->import((string) file_get_contents((string) $upload['tmp_name']));
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
?>
<div class='row justify-content-center backups-page'>
    <div class='col col-12 col-xl-10'>
        <h2 class='text-sm-center mb-4'><?php echo __('BACKUPS', 'PAGE_TITLES'); ?></h2>

        <?php foreach ($messages as [$type, $text]) { ?>
            <div class="alert alert-<?php echo $escape($type); ?>" role="alert"><?php echo $escape((string) $text); ?></div>
        <?php } ?>

        <?php if (null === $connection) { ?>
            <div class="alert alert-info"><?php echo __('BACKUPS_NEED_DATABASE', 'BACKUP'); ?></div>
        <?php } else { ?>

        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <h5 class="card-title mb-0"><?php echo __('DEVICE_BACKUPS', 'BACKUP'); ?></h5>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <form method="post" class="d-flex align-items-center gap-2">
                            <?php echo RequestHelper::csrfTokenField(); ?>
                            <input type="hidden" name="backups_action" value="keep">
                            <label for="backup_keep_count" class="text-nowrap mb-0"><?php echo __('BACKUP_KEEP_LABEL', 'BACKUP'); ?></label>
                            <input type="number" min="0" max="1000" class="form-control" style="width: 6rem" id="backup_keep_count" name="backup_keep_count" value="<?php echo $keepCount; ?>" aria-describedby="backupKeepHelp">
                            <button type="submit" class="btn btn-secondary"><?php echo __('BTN_SAVE_USER_CONFIG', 'USER_CONFIG'); ?></button>
                        </form>
                        <form method="post">
                            <?php echo RequestHelper::csrfTokenField(); ?>
                            <input type="hidden" name="backups_action" value="backup_all">
                            <button type="submit" class="btn btn-primary"><?php echo __('BACKUP_ALL_NOW', 'BACKUP'); ?></button>
                        </form>
                    </div>
                </div>
                <small class="form-text text-body-secondary d-block mb-3" id="backupKeepHelp"><?php echo __('BACKUP_KEEP_HELP', 'BACKUP'); ?></small>

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
                                    <td title="<?php echo $escape($backup->sha256); ?>"><?php echo $escape($backup->createdAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i')); ?></td>
                                    <td><?php echo $escape('' !== $backup->firmware ? $backup->firmware : '-'); ?></td>
                                    <td class="text-end"><?php echo $escape($formatSize($backup->size)); ?></td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-secondary btn-sm" href="<?php echo _BASEURL_; ?>actions?downloadStoredBackup=<?php echo $backup->id; ?>"><?php echo __('DOWNLOAD', 'BACKUP'); ?></a>
                                        <?php if ($deviceExists) { ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm(this.dataset.confirm);" data-confirm="<?php echo $escape(__('RESTORE_CONFIRM', 'BACKUP')); ?>">
                                            <?php echo RequestHelper::csrfTokenField(); ?>
                                            <input type="hidden" name="backups_action" value="restore">
                                            <input type="hidden" name="backup_id" value="<?php echo $backup->id; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm"><?php echo __('BATCH_BACKUP_RESTORE', 'BACKUP'); ?></button>
                                        </form>
                                        <?php } ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm(this.dataset.confirm);" data-confirm="<?php echo $escape(__('BACKUP_DELETE_CONFIRM', 'BACKUP')); ?>">
                                            <?php echo RequestHelper::csrfTokenField(); ?>
                                            <input type="hidden" name="backups_action" value="delete">
                                            <input type="hidden" name="backup_id" value="<?php echo $backup->id; ?>">
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

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title"><?php echo __('DATABASE_BACKUP', 'BACKUP'); ?></h5>
                <p class="text-body-secondary mb-3">
                    <?php echo $escape(sprintf(__('DATABASE_IN_USE', 'BACKUP'), $storageBackend->describe())); ?>
                </p>
                <?php $counts = $exporter->counts(); ?>
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
