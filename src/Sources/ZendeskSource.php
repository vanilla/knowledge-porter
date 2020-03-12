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
        $this->processKnowledgeArticles($kbCatIDs);
    }

    private function processKnowledgeBases(): array {
        $dest = $this->getDestination();
        $knowledgeBases = $this->zendesk->getCategories('en-us');

        $kbs = $this->transform($knowledgeBases, [
            'foreignID' => ['column' =>'id', 'filter' => [$this, 'addPrefix']],
            'name' => 'name',
            'description' => 'description',
            'urlCode' => ['column' => 'html_url', 'filter' => [$this, 'extractUrlSlug']],
            'sourceLocale' => 'locale',
            'viewType' => 'viewType',
            'sortArticles' => 'sortArticles',
        ]);

        $dest->importKnowledgeBases($kbs);

        return [];
    }

    private function processKnowledgeCategories(array $kbs): array {
        $perPage = $this->config['perPage'] ?? 50;
        $pageFrom = $this->config['pageFrom'] ?? 1;
        $pageTo = $this->config['pageTo'] ?? 10;
        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $categories = $this->zendesk->getSections('en-us', ['page' => $page, 'per_page' => $perPage]);
            if (empty($categories)) {
                break;
            }
            $knowledgeCategories = $this->transform($categories, [
                'foreignID' => ["column" => 'id', "filter" => [$this, "addPrefix"]],
                'knowledgeBaseID' => ["column" => 'category_id', "filter" => [$this, "knowledgeBaseSmartId"]],
                'parentID' => ["column" => 'parent_section_id', "filter" => [$this, "calculateParentID"]],
                'name' => 'name',
            ]);
            $dest = $this->getDestination();
            $dest->importKnowledgeCategories($knowledgeCategories);
        }

        return [];
    }

    /**
     * @param array $kbs
     * @return array
     */
    private function processKnowledgeArticles(array $kbs): array {
        $articles = $this->zendesk->getArticles('en-us');
        $knowledgeArticles = $this->transform($articles, [
            'foreignID' => ["column" =>'id', "filter" => [$this, "addPrefix"]],
            'knowledgeCategoryID' => ["column" =>'section_id', "filter" => [$this, "addPrefix"]],
            'format' => 'format',
            'locale' => 'locale',
            'name' => 'name',
            'body' => 'body',
        ]);
        $dest = $this->getDestination();
        $dest->importKnowledgeArticles($knowledgeArticles);

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
