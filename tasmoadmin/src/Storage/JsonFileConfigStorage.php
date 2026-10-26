<?php

namespace TasmoAdmin\Storage;

use Symfony\Component\Filesystem\Filesystem;

class JsonFileConfigStorage implements ConfigStorage
{
    private string $dataDir;

    private string $file;

    private Filesystem $filesystem;

    public function __construct(string $dataDir, ?string $file = null)
    {
        $this->dataDir = $dataDir;
        $this->file = $file ?? $dataDir.'MyConfig.json';
        $this->filesystem = new Filesystem();
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function exists(): bool
    {
        return file_exists($this->file);
    }

    public function load(): array
    {
        $configJSON = file_get_contents($this->file);
        if (false === $configJSON) {
            var_dump(debug_backtrace());

            exit('could not read MyConfig.json');
        }

        $config = json_decode($configJSON, true);
        if (0 !== json_last_error()) {
            exit('JSON CONFIG ERROR: '.json_last_error().' => '.json_last_error_msg());
        }

        if (!is_array($config)) {
            return [];
        }

        return array_map(static fn ($value): string => is_scalar($value) || null === $value ? (string) $value : (string) json_encode($value), $config);
    }

    public function clear(): void
    {
        foreach ([$this->file, $this->dataDir.'MyConfig.php'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function save(array $config): void
    {
        $configJson = json_encode($config, JSON_PRETTY_PRINT);
        if (!is_dir($this->dataDir)) {
            var_dump(debug_backtrace());

            exit($this->dataDir.' is NO DIR! | write()');
        }
        if (!is_writable($this->dataDir)) {
            var_dump(debug_backtrace());

            exit($this->dataDir.' is NOT WRITEABLE! | write()');
        }
        if (!file_exists($this->file)) {
            $this->filesystem->touch($this->file);
        }
        if (!is_writable($this->file)) {
            var_dump(debug_backtrace());

            exit($this->file.' is NOT WRITEABLE! | write()');
        }

        if (empty($configJson)) {
            var_dump($configJson);
            var_dump(debug_backtrace());

            exit('configJSON IS EMPTY! | write()');
        }

        $tempFile = $this->filesystem->tempnam($this->dataDir, 'config');
        $this->filesystem->dumpFile($tempFile, $configJson);
        $this->filesystem->rename($tempFile, $this->file, true);
    }
}
