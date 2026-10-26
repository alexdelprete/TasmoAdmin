<?php

namespace TasmoAdmin\Storage;

use Symfony\Component\Filesystem\Filesystem;

class CsvDeviceStorage implements DeviceStorage
{
    private const CSV_ESCAPE = '\\';

    private string $file;

    private string $tmpDir;

    private Filesystem $filesystem;

    public function __construct(string $file, string $tmpDir)
    {
        $this->file = $file;
        $this->tmpDir = $tmpDir;
        $this->filesystem = new Filesystem();

        if (!$this->filesystem->exists($this->file)) {
            $this->filesystem->touch($this->file);
        }
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function loadRows(): array
    {
        $rows = [];
        $input = $this->openInputFile();
        while (($line = fgetcsv($input, escape: self::CSV_ESCAPE)) !== false) {
            if ([null] === $line) {
                continue;
            }
            $rows[] = $line;
        }
        fclose($input);

        return $rows;
    }

    public function saveRows(array $rows): void
    {
        $tempFile = $this->filesystem->tempnam($this->tmpDir, 'tmp');
        $output = $this->openOutputFile($tempFile);

        foreach ($rows as $row) {
            fputcsv($output, $row, escape: self::CSV_ESCAPE);
        }

        fclose($output);
        $this->filesystem->rename($tempFile, $this->file, true);
    }

    public function appendRows(array $rows): void
    {
        $handle = fopen($this->file, 'a');
        if (false === $handle) {
            exit(__('ERROR_CANNOT_READ_CSV_FILE', 'DEVICE_ACTIONS', ['csvFilePath' => $this->file]));
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: self::CSV_ESCAPE);
        }

        fclose($handle);
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /**
     * @return resource
     */
    private function openInputFile()
    {
        $input = fopen($this->file, 'r');
        if (false === $input) {
            $csvFilePath = defined('_CSVFILE_') ? _CSVFILE_ : $this->file;

            exit(__('ERROR_CANNOT_READ_CSV_FILE', 'DEVICE_ACTIONS', ['csvFilePath' => $csvFilePath]));
        }

        return $input;
    }

    /**
     * @return resource
     */
    private function openOutputFile(string $path)
    {
        $output = fopen($path, 'w');
        if (false === $output) {
            exit(__('ERROR_CANNOT_CREATE_TMP_FILE', 'DEVICE_ACTIONS', ['tmpFilePath' => $path]));
        }

        return $output;
    }
}
