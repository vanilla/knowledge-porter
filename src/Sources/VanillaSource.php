<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Vanilla\KnowledgePorter\HttpClients\VanillaClient;

/**
 * Class VanillaSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class VanillaSource extends AbstractSource {
    /**
     * @var VanillaClient
     */
    private $vanillaApi;

    /**
     * VanillaSource constructor.
     * @param VanillaClient $vanilla
     */
    public function __construct(VanillaClient $vanilla) {
        $this->vanillaApi = $vanilla;
    }

    /**
     * Set Vanilla api base path
     *
     * @param string $basePath
     */
    public function setBasePath(string $basePath) {
        $this->basePath = $basePath;
    }

    /**
     * Execute import content actions
     */
    public function import(): void {
        $this->processKnowledgeBases();
        $this->processKnowledgeCategories();
        $this->processArticles();
    }

    /**
     * Process: GET knowledge bases, POST/PATCH vanilla knowledge bases
     */
    private function processKnowledgeBases() {
        $dest = $this->getDestination();
        $knowledgeBases = $this->vanillaApi->getKnowledgeBases([
            "locale" => "en",
        ]);
        $kbs = $this->transform($knowledgeBases, [
            'foreignID' => ["column" =>'knowledgeBaseID', "filter" => [$this, "addPrefix"]],
            'name' => 'name',
            'description' => 'description',
            'icon' => 'icon',
            'urlCode' => ["column" => 'urlCode', "filter" => [$this, "addPrefix"]],
            'sourceLocale' => 'sourceLocale',
            'viewType' => 'viewType',
            'sortArticles' => 'sortArticles',
            'skip' => ["column" =>'foreignID', "filter" => [$this, "isOrigin"]],
        ]);

        $dest->importKnowledgeBases($kbs);
    }

    /**
     * Vanilla does not have any need for rehosting headers.
     * @return array
     */
    public function getFileRehostingHeaders(): array {
        return [];
    }

    /**
     * Process: GET vanilla kb categories, POST/PATCH vanilla knowledge categories
     */
    private function processKnowledgeCategories()
    {
        $query = [
            "locale" => "en",
        ];
        $categories = $this->vanillaApi->getKnowledgeCategories($query);
        $knowledgeCategories = $this->transform($categories, [
            'foreignID' => ["column" =>'knowledgeCategoryID', "filter" => [$this, "addPrefix"]],
            'knowledgeBaseID' => ["column" =>'knowledgeBaseID', "filter" => [$this, "knowledgeBaseSmartId"]],
            'parentID' => ["column" =>'parentID', "filter" => [$this, "calculateParentID"]],
            'name' => 'name',
            'rootCategory' => ["column" =>'parentID', "filter" => [$this, "isRoot"]],
            'sourceParentID' => ["column" =>'parentID', "filter" => [$this, "isRoot"]],
            'skip' => ["column" =>'knowledgeBaseID', "filter" => [$this, "isOriginKb"]],

        ]);
        $dest = $this->getDestination();
        $dest->importKnowledgeCategories($knowledgeCategories);
    }

    /**
     * Process: GET vanilla kb articles, POST/PATCH vanilla knowledge base articles
     */
    private function processArticles() {
        return [];
    }

    /**
     * Add prefix.
     *
     * @param mixed $str
     * @return string
     */
    protected function addPrefix($str): string {
        $newStr = $this->config["foreignIDPrefix"].$str;
        return $newStr;
    }

    /**
     * Prepare smartID for knowledgeCategoryID field
     *
     * @param mixed $str
     * @return string
     */
    protected function knowledgeCategorySmartId($str): string {
        $newStr = '$foreignID:'.$this->config["foreignIDPrefix"].$str;
        return $newStr;
    }

    /**
     * Prepare smartID for parentID field
     *
     * @param mixed $str
     * @return string
     */
    protected function calculateParentID($str): string {
        if ($str != "-1") {
            $newStr = '$foreignID:' . $this->config["foreignIDPrefix"] . $str;
        } else {
            $newStr = $str;
        }
        return $newStr;
    }

    /**
     * Validate parentID field
     *
     * @param mixed $str
     * @return string
     */
    protected function isRoot($str): string {
        if ($str == "-1") {
            $newStr = 'true';
        } else {
            $newStr = 'false';
        }

        return $newStr;
    }

    /**
     * Detect if knowledge base is in scope of this import command
     * Note: this is done for specific case when source and destination are the same instance.
     *
     * @param mixed $str Check foreignID field
     * @return string
     */
    protected function isOrigin($str): string {
        $newStr = !empty($str) ? 'true' : 'false';
        return $newStr;
    }

    /**
     * Detect if knowledge base is in scope of this import command
     * Note: this is done for specific case when source and destination are the same instance.
     *
     * @param mixed $str
     * @return string
     */
    protected function isOriginKb($str): string {
        $max = $this->config['maxKbID'] ?? false;
        if ($max) {
            $newStr = ($str > $max) ? "true" : 'false';
        } else {
            $newStr = 'false';
        }

        return $newStr;
    }

    /**
     * Generate knowledge base smartID.
     *
     * @param mixed $str
     * @return string
     */
    protected function knowledgeBaseSmartId($str): string {
        $newStr = '$foreignID:'.$this->config["foreignIDPrefix"].$str;
        return $newStr;
    }

    /**
     * Set config
     *
     * @param array $config
     */
    public function setConfig(array $config): void {
        $this->config = $config;
        $this->vanillaApi->setToken($this->config['token']);
        $this->vanillaApi->setBaseUrl($this->config['baseUrl']);
    }
}
