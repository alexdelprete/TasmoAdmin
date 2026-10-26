<?php

namespace Tests\TasmoAdmin\Helper;

use PHPUnit\Framework\TestCase;
use TasmoAdmin\Helper\EnvironmentHelper;

class EnvironmentHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('NO_AUTH');
        putenv(EnvironmentHelper::DISABLE_LOGIN);
    }

    public function testLoginIsDisabledByTasmoDisableLogin(): void
    {
        self::assertFalse(EnvironmentHelper::isLoginDisabled());

        putenv('TASMO_DISABLE_LOGIN=true');
        self::assertTrue(EnvironmentHelper::isLoginDisabled());

        putenv('TASMO_DISABLE_LOGIN=false');
        self::assertFalse(EnvironmentHelper::isLoginDisabled());
    }

    public function testNoAuthIsStillAcceptedWhenTasmoDisableLoginIsNotSet(): void
    {
        putenv('NO_AUTH=true');
        self::assertTrue(EnvironmentHelper::isLoginDisabled());

        putenv('TASMO_DISABLE_LOGIN=');
        self::assertTrue(EnvironmentHelper::isLoginDisabled(), 'an empty TASMO_DISABLE_LOGIN counts as not set');

        putenv('TASMO_DISABLE_LOGIN=false');
        self::assertFalse(EnvironmentHelper::isLoginDisabled(), 'TASMO_DISABLE_LOGIN wins over NO_AUTH');
    }

    public function testIsEnabledReturnsTrueForTruthyValues(): void
    {
        putenv('NO_AUTH=true');
        self::assertTrue(EnvironmentHelper::isEnabled('NO_AUTH'));

        putenv('NO_AUTH=1');
        self::assertTrue(EnvironmentHelper::isEnabled('NO_AUTH'));

        putenv('NO_AUTH=yes');
        self::assertTrue(EnvironmentHelper::isEnabled('NO_AUTH'));
    }

    public function testIsEnabledReturnsFalseForFalsyValues(): void
    {
        putenv('NO_AUTH=false');
        self::assertFalse(EnvironmentHelper::isEnabled('NO_AUTH'));

        putenv('NO_AUTH=0');
        self::assertFalse(EnvironmentHelper::isEnabled('NO_AUTH'));

        putenv('NO_AUTH=off');
        self::assertFalse(EnvironmentHelper::isEnabled('NO_AUTH'));
    }

    public function testIsEnabledReturnsFalseWhenUnset(): void
    {
        putenv('NO_AUTH');
        self::assertFalse(EnvironmentHelper::isEnabled('NO_AUTH'));
    }
}
