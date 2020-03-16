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

        $body = '<p><strong>Justified Alignment</strong></p>\n<p class=\"wysiwyg-text-align-justify\">
Optimism mass incarceration; academic philanthropy social impact correlation. Segmentation.
</p>\n<p> </p>\n<span class=\"wysiwyg-font-size-large\"><strong>Links and hyperlinks</strong></span></p>\n
<p>Google - <a href=\"https://www.google.com/\">https://www.google.com/</a></p>\n
<p><a href=\"https://www.google.com/\" target=\"_self\">Search Google</a></p>\n<p>Link to another Zendesk article 
<a href=\"https://raccoonworks.zendesk.com/hc/en-us/articles/360040814891" target=\"_self\">Raccoon Works First Steps</a></p>\n
<p>Links within article: </p></p>';

        $expected = '<p><strong>Justified Alignment</strong></p>\n<p class=\"wysiwyg-text-align-justify\">
Optimism mass incarceration; academic philanthropy social impact correlation. Segmentation.
</p>\n<p> </p>\n<span class=\"wysiwyg-font-size-large\"><strong>Links and hyperlinks</strong></span></p>\n
<p>Google - <a href=\"https://www.google.com/\">https://www.google.com/</a></p>\n
<p><a href=\"https://www.google.com/\" target=\"_self\">Search Google</a></p>\n<p>Link to another Zendesk article 
<a href=\"https://dev.vanilla.localhost/kb/articles/aliases/zd-raccoon/hc/en-us/articles/360040814891" target=\"_self\">Raccoon Works First Steps</a></p>\n
<p>Links within article: </p></p>';

        $actual = ZendeskSource::replaceUrls($body, 'raccoonworks.zendesk.com', 'dev.vanilla.localhost', 'zd-raccoon');
        $this->assertSame($expected, $actual);
    }
}
