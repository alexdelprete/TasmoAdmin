<?php

use TasmoAdmin\Helper\BssidNameHelper;
use TasmoAdmin\Helper\HtmlAttributeHelper;

$bssidNames = BssidNameHelper::decode((string) ($config[BssidNameHelper::CONFIG_KEY] ?? ''));
?>
<div class="settings-section bssid-settings-section" id="bssid-settings">
    <div class="row g-4 settings-row">
        <div class="col col-12">
            <h2 class="settings-section-title"><?php echo __('CONFIG_BSSID_TITLE', 'USER_CONFIG'); ?></h2>
            <small class="d-block text-body-secondary mt-2">
                <?php echo __('CONFIG_BSSID_HELP', 'USER_CONFIG'); ?>
            </small>
        </div>
        <div class="col col-12">
            <input type="hidden" name="bssid_names_submitted" value="1">
            <div class="table-responsive">
                <table class="table bssid-names-table mb-2">
                    <thead>
                    <tr>
                        <th scope="col"><?php echo __('CONFIG_BSSID_HEAD_BSSID', 'USER_CONFIG'); ?></th>
                        <th scope="col"><?php echo __('CONFIG_BSSID_HEAD_NAME', 'USER_CONFIG'); ?></th>
                        <th scope="col" class="text-end"><?php echo __('CONFIG_BSSID_HEAD_DEVICES', 'USER_CONFIG'); ?></th>
                    </tr>
                    </thead>
                    <tbody class="bssid-names-rows"
                           data-placeholder="<?php echo HtmlAttributeHelper::escape(__('CONFIG_BSSID_NAME_PLACEHOLDER', 'USER_CONFIG')); ?>"
                    >
                    <?php foreach ($bssidNames as $bssid => $name) { ?>
                        <tr data-bssid="<?php echo HtmlAttributeHelper::escape($bssid); ?>">
                            <td class="bssid-value"><?php echo HtmlAttributeHelper::escape($bssid); ?></td>
                            <td>
                                <input type="text"
                                       class="form-control"
                                       name="bssid_names[<?php echo HtmlAttributeHelper::escape($bssid); ?>]"
                                       value="<?php echo HtmlAttributeHelper::escape($name); ?>"
                                       maxlength="64"
                                       placeholder="<?php echo HtmlAttributeHelper::escape(__('CONFIG_BSSID_NAME_PLACEHOLDER', 'USER_CONFIG')); ?>"
                                       aria-label="<?php echo HtmlAttributeHelper::escape($bssid); ?>"
                                >
                            </td>
                            <td class="bssid-devices text-end">0</td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <small class="form-text text-body-secondary bssid-scan-status" role="status">
                <?php echo __('CONFIG_BSSID_SCANNING', 'USER_CONFIG'); ?>
            </small>
        </div>
    </div>
</div>
