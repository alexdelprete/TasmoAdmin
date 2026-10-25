<?php

namespace Tests\TasmoAdmin\Helper;

use PHPUnit\Framework\TestCase;
use TasmoAdmin\Helper\BssidNameHelper;

class BssidNameHelperTest extends TestCase
{
    public function testNormalizeUppercasesBssidsAndDropsEmptyNames(): void
    {
        self::assertSame(
            ['AA:BB:CC:00:11:22' => 'AP Soggiorno'],
            BssidNameHelper::normalize([
                'aa:bb:cc:00:11:22' => '  AP Soggiorno ',
                '11:22:33:44:55:66' => '',
            ])
        );
    }

    public function testNormalizeDropsInvalidBssidsAndNonStringNames(): void
    {
        self::assertSame([], BssidNameHelper::normalize([
            'not-a-mac' => 'x',
            'AA:BB:CC:00:11' => 'short',
            'AA:BB:CC:00:11:22' => ['nested'],
        ]));
    }

    public function testNormalizeLimitsTheNameLength(): void
    {
        $names = BssidNameHelper::normalize(['AA:BB:CC:00:11:22' => str_repeat('a', 80)]);

        self::assertSame(64, mb_strlen($names['AA:BB:CC:00:11:22']));
    }

    public function testEncodeAndDecodeRoundTrip(): void
    {
        $json = BssidNameHelper::encode(['aa:bb:cc:00:11:22' => 'Cucina']);

        self::assertSame('{"AA:BB:CC:00:11:22":"Cucina"}', $json);
        self::assertSame(['AA:BB:CC:00:11:22' => 'Cucina'], BssidNameHelper::decode($json));
    }

    public function testEncodeOfNoNamesIsAnEmptyObject(): void
    {
        self::assertSame('{}', BssidNameHelper::encode([]));
        self::assertSame([], BssidNameHelper::decode(''));
        self::assertSame([], BssidNameHelper::decode('not json'));
    }

    public function testScriptJsonEscapesHtml(): void
    {
        self::assertStringNotContainsString(
            '</script>',
            BssidNameHelper::toScriptJson(['AA:BB:CC:00:11:22' => '</script><b>'])
        );
    }
}
