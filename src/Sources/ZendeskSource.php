<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use DOMDocument;
use Vanilla\KnowledgePorter\HttpClients\ZendeskClient;

/**
 * Class ZendeskSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class ZendeskSource extends AbstractSource {
    const LIMIT = 50;
    const PAGE_START =  1;
    const PAGE_END = 10;

    /**
     * @var ZendeskClient
     */
    private $zendesk;

    /**
     * ZendeskSource constructor.
     * @param ZendeskClient $zendesk
     */
    public function __construct(ZendeskClient $zendesk) {
        $this->zendesk = $zendesk;
    }

    /**
     * @param string $basePath
     */
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

    /**
     * Process: GET zendesk categories, POST/PATCH vanilla knowledge bases
     *
     * @return array
     */
    private function processKnowledgeBases(): array {
        $perPage = $this->config['perPage'] ?? self::LIMIT;
        $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
        $pageTo = $this->config['pageTo'] ?? self::PAGE_END;

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $knowledgeBases = $this->zendesk->getCategories('en-us', ['page' => $page, 'per_page' => $perPage]);
            if (empty($knowledgeBases)) {
                break;
            }
            $kbs = $this->transform($knowledgeBases, [
                'foreignID' => ['column' => 'id', 'filter' => [$this, 'addPrefix']],
                'name' => 'name',
                'description' => 'description',
                'urlCode' => ['column' => 'html_url', 'filter' => [$this, 'extractUrlSlug']],
                'sourceLocale' => 'locale',
                'viewType' => 'viewType',
                'sortArticles' => 'sortArticles',
            ]);
            $dest = $this->getDestination();
            $dest->importKnowledgeBases($kbs);
        }
        return [];
    }

    /**
     * Process: GET zendesk sections, POST/PATCH vanilla knowledge categories
     *
     * @param array $kbs
     * @return array
     */
    private function processKnowledgeCategories(array $kbs): array {
        $perPage = $this->config['perPage'] ?? self::LIMIT;
        $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
        $pageTo = $this->config['pageTo'] ?? self::PAGE_END;
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
     * Process: GET zendesk articles, POST/PATCH vanilla knowledge base articles
     *
     * @param array $kbs
     * @return array
     */
    private function processKnowledgeArticles(array $kbs): array {
        $perPage = $this->config['perPage'] ?? self::LIMIT;
        $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
        $pageTo = $this->config['pageTo'] ?? self::PAGE_END;

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $articles = $this->zendesk->getArticles('en-us', ['page' => $page, 'per_page' => $perPage]);
            if (empty($articles)) {
                break;
            }
            $knowledgeArticles = $this->transform($articles, [
                'foreignID' => ["column" => 'id', "filter" => [$this, "addPrefix"]],
                'knowledgeCategoryID' => ["column" => 'section_id', "filter" => [$this, "addPrefix"]],
                'format' => 'format',
                'locale' => 'locale',
                'name' => 'name',
                'body' => ['column' => 'body', 'filter' => [$this, 'parseUrls']],
                'alias' => ['column' => 'id', 'filter' => [$this, 'setAlias']],
            ]);
            $dest = $this->getDestination();
            $dest->importKnowledgeArticles($knowledgeArticles);
        }
        return [];
    }

    /**
     * Prepare knowledge base smart id.
     *
     * @param mixed $str
     * @return string
     */
    protected function knowledgeBaseSmartId($str): string {
        $newStr = '$foreignID:'.$this->config["prefix"].$str;
        return $newStr;
    }

    /**
     * Add foreignID prefix to string.
     *
     * @param mixed $str
     * @return string
     */
    protected function addPrefix($str): string {
        $newStr = $this->config["prefix"].$str;
        return $newStr;
    }

    /**
     * Extract url slug from zendesk category url.
     *
     * @param mixed $str
     * @return string
     */
    protected function extractUrlSlug($str): string {
        $pathInfo = pathinfo($str);
        $slug = $pathInfo['basename'] ?? null;
        $urlCode = strtolower($this->config["prefix"].$slug);
        return $urlCode;
    }

    /**
     * Set Alias for Zendesk Article.
     *
     * @param mixed $id
     * @return string
     */
    protected function setAlias($id): string {
        // to refactor, temporary solution to test out aliases.
        $basePath = "/hc/en-us/articles/".$id;
        return $basePath;
    }

    /**
     * Calculate parentID smart key.
     *
     * @param mixed $str
     * @return string
     */
    protected function calculateParentID($str): string {
        if (!is_null($str)) {
            $newStr = '$foreignID:' . $this->config["prefix"] . $str;
        } else {
            $newStr = 'null';
        }
        return $newStr;
    }

    /**
     * Get source locale
     *
     * @param mixed $str
     * @return string
     */
    protected function getSourceLocale($str): string {
        $locale = $str;
        return $locale;
    }

    /**
     * Parse urls from a string.
     *
     * @param $body
     * @return string
     */
    protected function parseUrls($body): string {
        // could use a preg_replace here.  As discussed with Alex use this over domDocument.
        $body = str_replace($this->config['sourceDomain'], $this->config['targetDomain'], $body);
        return $body;
    }

    /**
     * Set config values.
     *
     * @param array $config
     */
    public function setConfig(array $config): void {
        $this->config = $config;
        $this->zendesk->setToken($this->config['token']);
        $this->zendesk->setBaseUrl($this->config['baseUrl']);
    }
}
