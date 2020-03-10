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

    /**
     * Execute import content actions
     */
    public function import(): void {
       $kbIDs = $this->processKnowledgeBases();
       $kbCatIDs = $this->processKnowledgeCategories($kbIDs);
       $this->processArticles($kbCatIDs);
    }

    private function processKnowledgeBases(): array {
        $dest = $this->getDestination();
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
        $array = [];
        array_push($array, ...$kbs);
        return array_column($array, 'foreignID');
    }

    private function processKnowledgeCategories(array $kbIDs): array {
        $categories = $this->vanillaApi->getKnowledgeCategories('en');
        $knowledgeCategories = $this->transform($categories, [
            'foreignID' => ["column" =>'knowledgeCategoryID', "filter" => [$this, "addPrefix"]],
            'knowledgeBaseID' => ["column" =>'knowledgeBaseID', "filter" => [$this, "addSmartId"]],
            'parentID' => ["column" =>'parentID', "filter" => [$this, "addSmartId"]],
            'name' => 'name',
            'description' => 'description',
        ]);
        $dest = $this->getDestination();
        $dest->importKnowledgeCategories($knowledgeCategories);
        $array = [];
        array_push($array, ...$knowledgeCategories);
        return array_column($array, 'foreignID');
    }

    private function processArticles(array $kbCatIDs): array {
        ;
    }

    protected function addPrefix($str): string {
        $newStr = $this->config["prefix"].$str;
        return $newStr;
    }


    protected function addSmartId($str): string {
        $newStr = '$foreignID:'.$this->config["prefix"].$str;
        return $newStr;
    }

    public function setConfig(array $config): void {
        $this->config = $config;
        $this->vanillaApi->setToken($this->config['token']);
        $this->vanillaApi->setBaseUrl($this->config['baseUrl']);
    }
}
