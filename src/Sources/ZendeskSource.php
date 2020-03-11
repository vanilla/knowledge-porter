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

    public function setBasePath(string $basePath) {
        $this->basePath = $basePath;
    }

    /**
     * Execute import content actions
     */
    public function import(): void {
        $kbIDs = $this->processKnowledgeBases();
        $kbCatIDs = $this->processKnowledgeCategories($kbIDs);
        //$this->processArticles($kbCatIDs);
    }

    private function processKnowledgeBases(): array {
        $dest = $this->getDestination();
        $knowledgeBases = $this->zendesk->getCategories('en-us');

        $kbs = $this->transform($knowledgeBases, [
            'foreignID' => ['column' =>'id', 'filter' => [$this, 'addPrefix']],
            'name' => 'name',
            'description' => 'description',
            'urlCode' => ['column' => 'html_url', 'filter' => [$this, 'extractUrlSlug']],
            'sourceLocale' => 'source_locale',
            'viewType' => 'viewType',
            'sortArticles' => 'sortArticles',
        ]);

        $dest->importKnowledgeBases($kbs);

        return [];
    }

    private function processKnowledgeCategories(array $kbs): array {
        $categories = $this->zendesk->getSections('en-us', ['knowledgeBaseID']);
        $knowledgeCategories = $this->transform($categories, [
            'foreignID' => ["column" =>'id', "filter" => [$this, "addPrefix"]],
            'knowledgeBaseID' => ["column" =>'category_id', "filter" => [$this, "knowledgeBaseSmartId"]],
            'parentID' => ["column" =>'parent_section_id', "filter" => [$this, "calculateParentID"]],
            'name' => 'name',
        ]);
        $dest = $this->getDestination();
        $dest->importKnowledgeCategories($knowledgeCategories);

//        $array = [];
//        array_push($array, ...$knowledgeCategories);
//
//        return array_column($array, 'foreignID');
        return [];
    }


    protected function knowledgeBaseSmartId($str): string {
        $newStr = '$foreignID:'.$this->config["prefix"].$str;
        return $newStr;
    }

    protected function addPrefix($str): string {
        $newStr = $this->config["prefix"].$str;
        return $newStr;
    }

    /**
 * @param $str
 * @return string
 */
    protected function extractUrlSlug($str): string {
        $pathInfo = pathinfo($str);
        $slug = $pathInfo['basename'] ?? null;
        $urlCode = strtolower($this->config["prefix"].$slug);
        return $urlCode;
    }

    protected function calculateParentID($str): string {
        if (!is_null($str)) {
            $newStr = '$foreignID:' . $this->config["prefix"] . $str;
        } else {
            $newStr = 'null';
        }
        return $newStr;
    }

    /**
     * @param $str
     * @return string
     */
    protected function getSourceLocale($str): string {
        $locale = $str;
        return $locale;
    }

    public function setConfig(array $config): void {
        $this->config = $config;
        $this->zendesk->setToken($this->config['token']);
        $this->zendesk->setBaseUrl($this->config['baseUrl']);
    }
}
