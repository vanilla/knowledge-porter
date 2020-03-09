<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Vanilla\KnowledgePorter\HttpClients\VanillaClient;

class VanillaSource extends AbstractSource {
    /**
     * @var VanillaClient
     */
    private $vanillaApi;

    public function __construct(VanillaClient $vanilla) {
        $this->vanillaApi = $vanilla;
    }

    public function setBasePath(string $basePath) {
        $this->basePath = $basePath;
    }

    public function import(): void {
        $this->vanillaApi->init($this->params['baseUrl'], $this->params['token']);
        $dest = $this->getDestination();

        $categories = $this->vanillaApi->getCategories('en');
        $kbs = $this->transform($categories, [
            'foreignID' => 'id',
            'name' => ['column' => 'name', 'filter' => 'html_entity_decode'],
        ]);
        $dest->importKnowledgeBases($kbs);
    }
}
