<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Tests;


use PHPUnit\Framework\TestCase;
use Vanilla\KnowledgePorter\Sources\ZendeskSource;

class ZendeskSourceTest extends TestCase {
    public function testBasicReplaceUrls() {
        $body = '';
        $expected = '';

        $actual = ZendeskSource::replaceUrls($body, '', '', '');
        $this->assertSame($expected, $actual);
    }
}
