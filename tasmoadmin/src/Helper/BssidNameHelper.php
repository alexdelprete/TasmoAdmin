<?php

namespace TasmoAdmin\Helper;

/**
 * Names for Wi-Fi access points, keyed by BSSID (AA:BB:CC:DD:EE:FF).
 * Stored in the configuration as a JSON object under "bssid_names".
 */
class BssidNameHelper
{
    public const CONFIG_KEY = 'bssid_names';

    private const MAX_NAME_LENGTH = 64;

    public static function normalizeBssid(string $bssid): ?string
    {
        $bssid = strtoupper(trim($bssid));
        if (1 !== preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $bssid)) {
            return null;
        }

        return $bssid;
    }

    /**
     * @param array<mixed, mixed> $input
     *
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $names = [];
        foreach ($input as $bssid => $name) {
            if (!is_string($name)) {
                continue;
            }
            $bssid = self::normalizeBssid((string) $bssid);
            $name = trim($name);
            if (null === $bssid || '' === $name) {
                continue;
            }
            $names[$bssid] = mb_substr($name, 0, self::MAX_NAME_LENGTH);
        }
        ksort($names);

        return $names;
    }

    /**
     * @return array<string, string>
     */
    public static function decode(?string $json): array
    {
        if (null === $json || '' === trim($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    /**
     * @param array<string, string> $names
     */
    public static function encode(array $names): string
    {
        return (string) json_encode((object) self::normalize($names), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * JSON safe to print inside a <script> element.
     *
     * @param array<string, string> $names
     */
    public static function toScriptJson(array $names): string
    {
        return (string) json_encode(
            (object) self::normalize($names),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
