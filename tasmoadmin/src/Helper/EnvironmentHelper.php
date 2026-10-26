<?php

namespace TasmoAdmin\Helper;

class EnvironmentHelper
{
    public const DISABLE_LOGIN = 'TASMO_DISABLE_LOGIN';

    /**
     * Earlier name of TASMO_DISABLE_LOGIN, still accepted.
     */
    public const DISABLE_LOGIN_LEGACY = 'NO_AUTH';

    public static function isEnabled(string $variable): bool
    {
        return filter_var(getenv($variable), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the built-in login is switched off by TASMO_DISABLE_LOGIN, or by
     * NO_AUTH when TASMO_DISABLE_LOGIN is not set.
     */
    public static function isLoginDisabled(): bool
    {
        $value = getenv(self::DISABLE_LOGIN);
        if (false !== $value && '' !== trim($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return self::isEnabled(self::DISABLE_LOGIN_LEGACY);
    }
}
