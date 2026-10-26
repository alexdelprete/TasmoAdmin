<?php

namespace TasmoAdmin;

use TasmoAdmin\Storage\CsvDeviceStorage;
use TasmoAdmin\Storage\DeviceStorage;

class DeviceRepository
{
    private const UPDATE_FIELD_PROPERTY_MAP = [
        'device_all_off' => 'deviceAllOff',
        'device_protect_on' => 'deviceProtectionOn',
        'device_protect_off' => 'deviceProtectionOff',
        'device_hide_from_startpage' => 'deviceHideFromStartpage',
    ];

    private DeviceStorage $storage;

    private DevicePasswordCipher $devicePasswordCipher;

    private bool $defaultConfirmDeviceToggles;

    private array $allowedUpdateFields = [
        'names',
        'friendlyNames',
        'position',
        'ip',
        'username',
        'password',
        'img',
        'position',
        'device_all_off',
        'device_protect_on',
        'device_protect_off',
        'isUpdatable',
        'port',
        'deviceConfirmToggle',
        'mqttTopic',
        'device_hide_from_startpage',
    ];

    /**
     * @param DeviceStorage|string $storage a storage, or the path of a devices.csv file
     */
    public function __construct(
        DeviceStorage|string $storage,
        string $tmpDir,
        DevicePasswordCipher $devicePasswordCipher,
        bool $defaultConfirmDeviceToggles = false
    ) {
        $this->storage = is_string($storage) ? new CsvDeviceStorage($storage, $tmpDir) : $storage;
        $this->devicePasswordCipher = $devicePasswordCipher;
        $this->defaultConfirmDeviceToggles = $defaultConfirmDeviceToggles;
    }

    public function addDevice(array $request): void
    {
        $deviceUsername = $request['device_username'] ?? '';
        $devicePassword = $request['device_password'] ?? '';
        $this->addDevices([$request], $deviceUsername, $devicePassword);
    }

    public function addDevices(array $devices, string $deviceUsername, string $devicePassword): void
    {
        $rows = [];
        $nextId = $this->getNextId();
        foreach ($devices as $device) {
            $deviceHolder = [];
            $deviceHolder[0] = $nextId++;
            $deviceHolder[1] = implode('|', $device['device_name'] ?? []);
            $deviceHolder[2] = $device['device_ip'] ?? '';
            $deviceHolder[3] = $deviceUsername;
            $deviceHolder[4] = $this->encodePasswordForStorage($devicePassword);
            $deviceHolder[5] = $device['device_img'] ?? 'bulb_1';
            $deviceHolder[6] = $device['device_position'] ?? '';
            $deviceHolder[7] = $device['device_all_off'] ?? 1;
            $deviceHolder[8] = $device['device_protect_on'] ?? 0;
            $deviceHolder[9] = $device['device_protect_off'] ?? 0;
            $deviceHolder[10] = $device['is_updatable'] ?? 1;
            $deviceHolder[11] = $device['device_port'] ?? Device::DEFAULT_PORT;
            $deviceHolder[12] = implode('|', $device['device_friendly_name'] ?? ($device['device_name'] ?? []));
            $deviceHolder[13] = $device['device_confirm_toggle'] ?? ($this->defaultConfirmDeviceToggles ? 1 : 0);
            $deviceHolder[14] = trim((string) ($device['device_mqtt_topic'] ?? ''));
            $deviceHolder[15] = $device['device_hide_from_startpage'] ?? 0;

            $rows[] = $deviceHolder;
        }

        $this->storage->appendRows($rows);
    }

    public function getDeviceById(int $id): ?Device
    {
        $device = null;
        $storageRows = [];
        $needsRewrite = false;
        foreach ($this->storage->loadRows() as $line) {
            [$storageRow, $rowNeedsRewrite] = $this->prepareStorageRow($line);
            $storageRows[] = $storageRow;
            $needsRewrite = $needsRewrite || $rowNeedsRewrite;

            if ($line[0] == $id) {
                $device = $this->createDeviceObject($storageRow);
            }
        }

        if ($needsRewrite) {
            $this->rewriteRows($storageRows);
        }

        return $device;
    }

    /**
     * @return Device[]
     */
    public function getDevices(): array
    {
        $devices = [];
        $storageRows = [];
        $needsRewrite = false;
        foreach ($this->storage->loadRows() as $line) {
            [$storageRow, $rowNeedsRewrite] = $this->prepareStorageRow($line);
            $storageRows[] = $storageRow;
            $needsRewrite = $needsRewrite || $rowNeedsRewrite;
            $devices[] = $this->createDeviceObject($storageRow);
        }

        if ($needsRewrite) {
            $this->rewriteRows($storageRows);
        }

        return $devices;
    }

    public function getDevicesByIds(array $ids): array
    {
        $devices = $this->getDevices();

        foreach ($devices as $index => $device) {
            if (!in_array($device->id, $ids, false)) {
                unset($devices[$index]);
            }
        }

        return array_values($devices);
    }

    /**
     * @return Device[]
     */
    public function getDevicesByMqttTopic(string $mqttTopic): array
    {
        $mqttTopic = trim($mqttTopic);
        if ('' === $mqttTopic) {
            return [];
        }

        $devices = [];
        foreach ($this->getDevices() as $device) {
            if ($device->mqttTopic === $mqttTopic) {
                $devices[] = $device;
            }
        }

        return $devices;
    }

    public function getDeviceByMqttTopic(string $mqttTopic): ?Device
    {
        $devices = $this->getDevicesByMqttTopic($mqttTopic);

        return 1 === count($devices) ? $devices[0] : null;
    }

    public function isMqttTopicAmbiguous(string $mqttTopic): bool
    {
        return count($this->getDevicesByMqttTopic($mqttTopic)) > 1;
    }

    public function setDeviceValue(int $id, string $field, $value = null): ?Device
    {
        $device = $this->getDeviceById($id);
        if (null === $device) {
            return null;
        }

        if (!in_array($field, $this->allowedUpdateFields, true)) {
            return null;
        }

        $property = self::UPDATE_FIELD_PROPERTY_MAP[$field] ?? $field;
        $device->{$property} = $value;

        return $this->updateDevice($device);
    }

    public function removeDevice(int $id): void
    {
        $this->removeDevices([$id]);
    }

    public function setDeviceConfirmToggleForAll(bool $enabled): void
    {
        $storageRows = [];
        foreach ($this->storage->loadRows() as $data) {
            [$storageRow] = $this->prepareStorageRow($data);
            $storageRow[13] = $enabled ? 1 : 0;
            $storageRows[] = $storageRow;
        }

        $this->rewriteRows($storageRows);
    }

    public function removeDevices(array $ids): void
    {
        $rows = [];
        foreach ($this->storage->loadRows() as $data) {
            if (in_array($data[0], $ids)) {
                continue;
            }
            $rows[] = $data;
        }

        $this->storage->saveRows($rows);
    }

    public function updateDevice(Device $device): ?Device
    {
        if (empty($device->id)) {
            return null;
        }
        $deviceArr[0] = $device->id;
        $deviceArr[1] = implode('|', !empty($device->names) ? $device->names : []);
        $deviceArr[2] = !empty($device->ip) ? $device->ip : '';
        $deviceArr[3] = !empty($device->username) ? $device->username : '';
        $deviceArr[4] = $this->encodePasswordForStorage($device->password ?? '');
        $deviceArr[5] = !empty($device->img) ? $device->img : '';
        $deviceArr[6] = !empty($device->position) ? $device->position : '';
        $deviceArr[7] = !empty($device->deviceAllOff) ? $device->deviceAllOff : 0;
        $deviceArr[8] = !empty($device->deviceProtectionOn) ? $device->deviceProtectionOn : 0;
        $deviceArr[9] = !empty($device->deviceProtectionOff) ? $device->deviceProtectionOff : 0;
        $deviceArr[10] = !empty($device->isUpdatable) ? $device->isUpdatable : 0;
        $deviceArr[11] = $device->port;
        $deviceArr[12] = implode('|', !empty($device->friendlyNames) ? $device->friendlyNames : $device->names);
        $deviceArr[13] = !empty($device->deviceConfirmToggle) ? $device->deviceConfirmToggle : 0;
        $deviceArr[14] = $device->mqttTopic ?? '';
        $deviceArr[15] = !empty($device->deviceHideFromStartpage) ? $device->deviceHideFromStartpage : 0;

        foreach ($deviceArr as $key => $field) {
            $deviceArr[$key] = trim($field);
        }

        $rows = [];
        foreach ($this->storage->loadRows() as $data) {
            if ($data[0] == $deviceArr[0]) {
                $data = $deviceArr;
            }
            $rows[] = $data;
        }

        $this->storage->saveRows($rows);

        return $this->createDeviceObject($deviceArr);
    }

    private function createDeviceObject(array $deviceLine): ?Device
    {
        $deviceLine[4] = $this->devicePasswordCipher->decrypt((string) ($deviceLine[4] ?? ''));

        return DeviceFactory::fromArray($deviceLine);
    }

    private function prepareStorageRow(array $deviceLine): array
    {
        $needsRewrite = false;
        $defaults = [
            0 => '',
            1 => '',
            2 => '',
            3 => '',
            4 => '',
            5 => Device::DEFAULT_IMAGE,
            6 => '0',
            7 => '1',
            8 => '0',
            9 => '0',
            10 => '1',
            11 => (string) Device::DEFAULT_PORT,
            12 => '',
            13 => $this->defaultConfirmDeviceToggles ? '1' : '0',
            14 => '',
            15 => '0',
        ];

        foreach ($defaults as $index => $defaultValue) {
            if (!array_key_exists($index, $deviceLine)) {
                $deviceLine[$index] = $defaultValue;
                $needsRewrite = true;
            }
        }

        ksort($deviceLine);

        $password = (string) ($deviceLine[4] ?? '');
        if ('' === $password || $this->devicePasswordCipher->isRecognizedEncryptedPayload($password)) {
            return [$deviceLine, $needsRewrite];
        }

        $deviceLine[4] = $this->devicePasswordCipher->encrypt($password);

        return [$deviceLine, true];
    }

    private function getNextId(): int
    {
        $id = 0;
        foreach ($this->getDevices() as $device) {
            $id = max($id, $device->id);
        }

        return $id + 1;
    }

    private function encodePasswordForStorage(string $password): string
    {
        if ('' === $password) {
            return '';
        }

        return $this->devicePasswordCipher->encrypt($password);
    }

    private function rewriteRows(array $rows): void
    {
        $this->storage->saveRows($rows);
    }
}
