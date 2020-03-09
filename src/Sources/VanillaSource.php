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
        $dest = $this->getDestination();

//        $categories = $this->vanillaApi->getCategories('en');
//        $kbs = $this->transform($categories, [
//            'foreignID' => 'id',
//            'name' => ['column' => 'name', 'filter' => 'html_entity_decode'],
//        ]);

        $knowledgeBases = $this->vanillaApi->getKnowledgeBases('en');

        $kbs = $this->transform($knowledgeBases, [
            'foreignID' => ["column" =>'knowledgeBaseID', "filter" => [$this, "addPrefix"]],
            'name' => 'name',
            'description' => 'description',
            'icon' => 'icon',
            'urlCode' => ["column" => 'urlCode', "filter" => [$this, "addPrefix"]],
            'sourceLocale' => 'sourceLocale',
            'viewType' => 'viewType',
            'sortArticles' => 'sortArticles',
        ]);

        $dest->importKnowledgeBases($kbs);
    }

    protected function addPrefix($str): string {
        $newStr = $this->config["prefix"].$str;
        return $newStr;
    }

    public function setConfig(array $config): void {
        $this->config = $config;
        $this->vanillaApi->setToken($this->config['token']);
        $this->vanillaApi->setBaseUrl($this->config['baseUrl']);
    }
}
