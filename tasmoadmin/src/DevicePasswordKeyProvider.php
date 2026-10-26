<?php

namespace TasmoAdmin;

use Symfony\Component\Filesystem\Filesystem;

class DevicePasswordKeyProvider
{
    public const ENV_NAME = 'TASMO_DEVICE_PASSWORD_KEY';
    public const ENV_FILE_NAME = 'TASMO_DEVICE_PASSWORD_KEY_FILE';
    public const KEY_LENGTH = 32;
    public const SIDECAR_FILENAME = '.device-password.key';

    private string $dataDir;

    private Filesystem $filesystem;

    private ?string $key = null;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/').'/';
        $this->filesystem = new Filesystem();
    }

    public function getKey(): string
    {
        if (null !== $this->key) {
            return $this->key;
        }

        $sidecarKey = null;
        $sidecarEncodedKey = null;
        if ($this->filesystem->exists($this->getKeyFilePath())) {
            $sidecarEncodedKey = trim((string) file_get_contents($this->getKeyFilePath()));
            $sidecarKey = $this->decodeKey($sidecarEncodedKey, 'sidecar');
        }

        [$envEncodedKey, $envSource] = $this->readEnvironmentKey();
        $envKey = null;
        if (null !== $envEncodedKey) {
            $envKey = $this->decodeKey($envEncodedKey, $envSource);
        }

        if (null !== $sidecarKey) {
            if (null !== $envKey && $sidecarEncodedKey !== $envEncodedKey) {
                throw new DeviceCredentialException(sprintf(
                    'Device password key mismatch between %s and %s.',
                    $this->getKeyFilePath(),
                    $envSource
                ));
            }

            return $this->key = $sidecarKey;
        }

        if (null !== $envKey) {
            return $this->key = $envKey;
        }

        return $this->key = $this->generateAndPersistKey();
    }

    public function getKeyFilePath(): string
    {
        return $this->dataDir.self::SIDECAR_FILENAME;
    }

    /**
     * The key from TASMO_DEVICE_PASSWORD_KEY, or from the file named by
     * TASMO_DEVICE_PASSWORD_KEY_FILE (Docker secrets).
     *
     * @return array{0: ?string, 1: string} the encoded key (null when neither is set) and its source
     */
    private function readEnvironmentKey(): array
    {
        $value = trim((string) getenv(self::ENV_NAME));
        $file = trim((string) getenv(self::ENV_FILE_NAME));

        if ('' === $file) {
            return ['' === $value ? null : $value, self::ENV_NAME];
        }

        if (!is_readable($file)) {
            throw new DeviceCredentialException(sprintf('%s points to %s, which cannot be read.', self::ENV_FILE_NAME, $file));
        }

        $fileValue = trim((string) file_get_contents($file));
        if ('' === $fileValue) {
            throw new DeviceCredentialException(sprintf('%s points to %s, which is empty.', self::ENV_FILE_NAME, $file));
        }
        if ('' !== $value && $value !== $fileValue) {
            throw new DeviceCredentialException(sprintf('%s and %s are both set but contain different keys.', self::ENV_NAME, self::ENV_FILE_NAME));
        }

        return [$fileValue, self::ENV_FILE_NAME];
    }

    private function decodeKey(string $encodedKey, string $source): string
    {
        $decodedKey = base64_decode($encodedKey, true);
        if (false === $decodedKey || self::KEY_LENGTH !== strlen($decodedKey)) {
            throw new DeviceCredentialException(sprintf(
                'Invalid device password key in %s. Expected base64-encoded 32-byte secret.',
                $source
            ));
        }

        return $decodedKey;
    }

    private function generateAndPersistKey(): string
    {
        $key = random_bytes(self::KEY_LENGTH);
        $this->filesystem->mkdir($this->dataDir);
        $written = file_put_contents($this->getKeyFilePath(), base64_encode($key));
        if (false === $written) {
            throw new DeviceCredentialException(sprintf(
                'Failed to persist generated device password key to %s.',
                $this->getKeyFilePath()
            ));
        }
        @chmod($this->getKeyFilePath(), 0o600);

        return $key;
    }
}
