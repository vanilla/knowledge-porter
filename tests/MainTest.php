<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Tests;

use PHPUnit\Framework\TestCase;
use Vanilla\KnowledgePorter\Main;

/**
 * Tests for the `Main` class.
 */
class MainTest extends TestCase {
    /**
     * Test `Main::changeCase()`.
     *
     * @param string $kebab
     * @param string $expected
     * @dataProvider provideChangeCaseTests
     */
    public function testChangeCase(string $kebab, string $expected): void {
        $actual = Main::changeCase($kebab);
        $this->assertSame($expected, $actual);
    }

    /**
     * Provide data for `testChangeCase`.
     *
     * @return array
     */
    public function provideChangeCaseTests(): array {
        $r = [
            ['foo', 'Foo'],
            ['foo-bar', 'FooBar'],
        ];

        return array_column($r, null, 0);
    }
}
