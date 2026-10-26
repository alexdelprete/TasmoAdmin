<?php

namespace TasmoAdmin\Storage;

/**
 * Where device rows live. A row is the list of 16 fields that
 * DeviceRepository and DeviceFactory work with (id, names, ip, ...).
 */
interface DeviceStorage
{
    /**
     * @return list<array<int, string>>
     */
    public function loadRows(): array;

    /**
     * Replaces all stored rows.
     *
     * @param list<array<int, int|string>> $rows
     */
    public function saveRows(array $rows): void;

    /**
     * @param list<array<int, int|string>> $rows
     */
    public function appendRows(array $rows): void;

    /**
     * Removes all devices (the "reset devices" action in Settings).
     */
    public function clear(): void;
}
