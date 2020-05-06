<?php
/**
 * @author Alexander Kim <alexander.k@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Garden\Schema\Schema;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\Destinations\VanillaDestination;
use Vanilla\KnowledgePorter\Adapters\KayakoXmlAdapter;

/**
 * Class KayakoXmlSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class KayakoXmlSource extends AbstractSource {
    const DEFAULT_SOURCE_LOCALE = 'en-us';
    const DEFAULT_LOCALE = 'en';

    /** @var KayakoXmlAdapter $kayakoXml */
    private $kayakoXml;

    /** @var ContainerInterface $container */
    protected $container;

    /**
     * KayakoXmlSource constructor.
     *
     * @param KayakoXmlAdatpter $kayakoXml
     * @param ContainerInterface $container
     */
    public function __construct(KayakoXmlAdapter $kayakoXml, ContainerInterface $container) {
        $this->kayakoXml = $kayakoXml;
        $this->container = $container;
    }

    /**
     * Get our authorization headers so that we can rehost files.
     *
     * @return array
     */
    public function getFileRehostingHeaders(): array {
        return [];
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
        if ($this->config['import']['knowledgeBases'] ?? true) {
            $this->processKnowledgeBases();
        }

        if ($this->config['import']['knowledgeCategories'] ?? true) {
            $this->processKnowledgeCategories();
        }
    }

    /**
     * Process: import knowledgeBases.xml, POST/PATCH vanilla knowledge bases
     */
    private function processKnowledgeBases() {
        $locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;

        $knowledgeBases = $this->kayakoXml->getKnowledgeBases();

        $kbs = $this->transform($knowledgeBases, [
            'foreignID' => ['column' => 'id', 'filter' => [$this, 'addPrefix']],
            'name' => 'name',
            'description' => 'description',
            'urlCode' => 'urlCode',
            'sourceLocale' => ['placeholder' => $locale],
            'viewType' => 'viewType',
            'sortArticles' => 'sortArticles',
            'dateUpdated' => ['column' => 'dateUpdated', 'filter' => [$this, 'dateFormat']],
        ]);
        $dest = $this->getDestination();
        foreach ($dest->importKnowledgeBases($kbs) as $kb) {
            $this->logger->info('Knowledge base "'.$kb['name'].'" imported successfully');
        }
    }

    /**
     * Process: import knowledgeCategories.xml, POST/PATCH vanilla knowledge categories
     *
     * @return iterable
     */
    private function processKnowledgeCategories() {
        $locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;

        /** @var VanillaDestination $dest */
        $dest = $this->getDestination();

        $categories = $this->kayakoXml->getKnowledgeCategories();

        $knowledgeCategories = $this->transform($categories, [
            'foreignID' => ["column" => 'id', "filter" => [$this, "addPrefix"]],
            'knowledgeBaseID' => ["column" => 'knowledgeBaseID', "filter" => [$this, "knowledgeBaseSmartId"]],
            'parentID' => ["column" => 'parentkbcategoryid', "filter" => [$this, "calculateParentID"]],
            'name' => 'title',
            'sourceParentID' => ["column" =>'parentkbcategoryid', "filter" => [$this, "isRoot"]],
        ]);

        $knowledgeCategories = $dest->importKnowledgeCategories($knowledgeCategories);
        $i = 0;
        foreach ($knowledgeCategories as $knowledgeCategory) {
            $i++;
        }
        $this->logger->info($i.' knowledge categories imported successfully');
        if ($this->config['import']['retryCategories'] ?? true) {
            $this->rerunProcessKnowledgeCategories($dest);
        }
    }

    /**
     * Retry processing failed knowledge categories.
     *
     * @param $dest
     */
    private function rerunProcessKnowledgeCategories(VanillaDestination $dest) {
        $knowledgeCategories = $dest->processFailedImportedKnowledgeCategories();
        $i = 0;
        foreach ($knowledgeCategories as $knowledgeCategory) {
            $i++;
        }
        $this->logger->info($i.' failed knowledge categories imported successfully after retry');
    }

    /**
     * Validate parentID field
     *
     * @param mixed $str
     * @return string
     */
    protected function isRoot($str): string {
        if ($str == 0) {
            $newStr = 'true';
        } else {
            $newStr = 'false';
        }

        return $newStr;
    }
    /**
     * Prepare knowledge base smart id.
     *
     * @param mixed $str
     * @return string
     */
    protected function knowledgeBaseSmartId($str): string {
        $newStr = '$foreignID:'.$this->config["foreignIDPrefix"].$str;
        return $newStr;
    }

    protected function dateFormat(int $date): string {
        return date(DATE_ATOM, $date);
    }

    /**
     * Add foreignID prefix to string.
     *
     * @param mixed $str
     * @return string
     */
    protected function addPrefix($str): string {
        $newStr = $this->config["foreignIDPrefix"].$str;
        return $newStr;
    }

    /**
     * Add foreignID prefix to string.
     *
     * @param mixed $str
     * @return string
     */
    protected function trimPrefix($str): string {
        $newStr = str_replace($this->config["foreignIDPrefix"], '', $str);
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
        $urlCode = strtolower($this->config["foreignIDPrefix"].$slug);
        return $urlCode;
    }

    /**
     * Calculate parentID smart key.
     *
     * @param mixed $str
     * @return string
     */
    protected function calculateParentID($str): string {
        if (!empty($str)) {
            $newStr = '$foreignID:' . $this->config["foreignIDPrefix"] . $str;
        } else {
            $newStr = 'null';
        }
        return $newStr;
    }

    /**
     * Set config values.
     *
     * @param array $config
     */
    public function setConfig(array $config): void {
        /** @var Schema $schema */
        $schema = $this->configSchema();
        $config = $schema->validate($config);
        $this->config = $config;
        $this->kayakoXml->setBaseDir($this->config['folder']);
    }

    /**
     * Get schema for config.
     *
     * @return Schema
     */
    private function configSchema(): Schema {
        return Schema::parse([
            "type:s?" => ["default" => 'zendesk'],
            "foreignIDPrefix:s?" => ["default" => 'zd-'],
            "domain:s" => [
                "description" => "Source domain.",
                "minLength" => 5
            ],
            "folder:s" => [
                "description" => "Base folder to read XML data files.",
                "minLength" => 5
            ],
            "targetDomain:s?" => [
                "description" => "Target domain.",
                "minLength" => 5
            ],
            "sourceLocale:s?" => [
                "description" => "Zendesk api content source locale. Ex: en-us",
                "default" => self::DEFAULT_SOURCE_LOCALE
            ],
            "articleLimit:i?" => [
                "allowNull" => true,
                "minimum" => 1,
                "maximum" => 300,
            ],
            "syncFrom:s?" => [
                "description" => "Days or Date from which to start import or sync",
                "allowNull" => true,
                "minLength" => 5
            ],
            "import:o?" => [
                "description" => "Import by content type: categories, sections, articles.",
                "properties" => [
                    "knowledgeBases" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "knowledgeCategories" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "authors" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "articles" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "translations" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "retryCategories" => [
                        "type" => "boolean",
                        "default" => false,
                    ],
                    "helpful" => [
                        "type" => "boolean",
                        "default" => false,
                    ],
                    "attachments" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                ],
            ],
        ]);
    }
}
