<?php

namespace TasmoAdmin\Storage;

/**
 * Where the settings live. Values are stored as given: sensitive values are
 * already encrypted by Config before save() and decrypted after load().
 */
interface ConfigStorage
{
    public function exists(): bool;

    /**
     * @return array<string, string>
     */
    public function load(): array;

    /**
     * @param array<string, string> $config
     */
    public function save(array $config): void;

    /**
     * Removes all settings (the "reset configuration" action in Settings).
     */
    public function clear(): void;
}
