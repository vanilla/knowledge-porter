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
        $body = '<a href=\"https://raccoonworks.zendesk.com/hc/en-us/articles/360040814891\" target=\"_self\">Raccoon Works First Steps</a>';;
        $expected = '<a href=\"https://dev.vanilla.localhost/kb/articles/aliases/zd-raccoon/hc/en-us/articles/360040814891" target=\"_self\">Raccoon Works First Steps</a>';

        $actual = ZendeskSource::replaceUrls($body, '', '', '');
        $this->assertSame($expected, $actual);
    }
}
