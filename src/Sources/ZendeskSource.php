<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Vanilla\KnowledgePorter\HttpClients\ZendeskClient;

class ZendeskSource extends AbstractSource {
    /**
     * @var ZendeskClient
     */
    private $zendesk;

    public function __construct(ZendeskClient $zendesk) {
        $this->zendesk = $zendesk;
    }

    public function import(): void {
        $dest = $this->getDestination();

        $categories = $this->zendesk->getCategories('en');
        $kbs = $this->transform($categories, [
            'foreignID' => 'id',
            'name' => ['column' => 'name', 'filter' => 'html_entity_decode'],
        ]);
        $dest->importKnowledgeBases($kbs);
    }
}
