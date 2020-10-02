<?php
/**
 * @author TJ Webb <tj.webb@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Vanilla\KnowledgePorter\HttpClients\VanillaClient;
use Vanilla\KnowledgePorter\Utils\Helpers;

/**
 * Class PhpdocHtmlSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class PhpdocHtmlSource extends AbstractSource {

    const DEFAULT_SOURCE_LOCALE = 'en';

    /**
     * @var array
     */
    private $categories = [];

    /**
     * @var string
     */
    private $foreignID;

    /**
     * @var string
     */
    private $format;

    /**
     * @var string
     */
    private $locale;

    /**
     * @var string
     */
    private $noNamespaceID;

    /**
     * @var string
     */
    private $sortArticles;

    /**
     * @var string
     */
    private $sourcePath;

    /**
     * @var VanillaClient
     */
    private $vanillaApi;

    /**
     * @var string
     */
    private $viewType;

    /**
     * VanillaSource constructor.
     * @param VanillaClient $vanilla
     */
    public function __construct(VanillaClient $vanilla)
    {
        $this->vanillaApi = $vanilla;
    }

    /**
     * Vanilla does not have any need for rehosting headers.
     * @return array
     */
    public function getFileRehostingHeaders(): array
    {
        return [];
    }

    /**
     * Execute import content actions
     */
    public function import(): void
    {
        $this->loadConfigs();
        $this->processKnowledgeBase();
        $this->processKnowledgeCategories();
        $this->processKnowledgeArticles();
    }

    /**
     * Set Vanilla api base path
     *
     * @param string $basePath
     */
    public function setBasePath(string $basePath): void
    {
        $this->basePath = $basePath;
    }

    /**
     * Set config
     *
     * @param array $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Builds the category objects to be inserted
     *
     * @param array $files - the list of file names.
     */
    private function buildCategories(array $files): void
    {
        $createNoNamespaceCategory = false;
        foreach($files as $file){
            $parts = explode('.', $file);
            if(count($parts) > 2 && 'html' === strtolower($parts[count($parts)-1])){
                $keys = array_slice($parts, 0 , -2);

                $branch = [];
                $k = array_shift($keys);
                if($k){
                    $c = &$branch[$k];
                    foreach ($keys as $key) {
                        if($key){
                            if (isset($c[$key]) && $c[$key] === true) {
                                $c[$key] = [];
                            }
                            $c = &$c[$key];
                        }
                    }

                }
                $this->categories = array_merge_recursive($this->categories, $branch);
            }else{
                $createNoNamespaceCategory = true;
            }
        }
        if($createNoNamespaceCategory){
            $this->categories = array_merge_recursive($this->categories, $this->createNoNamespaceCategory());
        }
        $this->categories = $this->filterCategories($this->categories);
    }

    /**
     * Creates a single category for all classes with no namespace.
     *
     * @return array
     */
    private function createNoNamespaceCategory(): array
    {
        return [
            'foreignID' => $this->noNamespaceID,
            'knowledgeBaseID' => '$foreignID:' . $this->foreignID,
            'parentID' => '$foreignID:' . $this->foreignID . '-root',
            'name' => '[no namespace]',
            'sourceParentID' => '$foreignID:' . $this->foreignID . '-root',
            'rootCategory' => 'false',
        ];
    }

    /**
     * Traverses category objects and removes any empty ones. Should be used before writing to API.
     *
     * @param $categories - the built categories to be inserted.
     * @return array
     */
    private function filterCategories($categories): array
    {
        foreach($categories as $k => &$v){
            if(is_null($v) && is_numeric($k)){
                unset($categories[$k]);
            }
            if(is_array($v)){
                $v = $this->filterCategories($v);
            }
            if(empty($v)){
                $v = null;
            }
        }

        return array_values($categories);
    }

    /**
     * Gets the Knowledge Base details for import
     *
     * @return array[]
     */
    private function getKnowledgeBaseConfig(): array
    {
        return [
            [
                'foreignID' => $this->foreignID,
                'name' => $this->config['name'],
                'description' => $this->config['description'],
                'urlCode' => $this->config['urlCode'],
                'sourceLocale' => $this->locale,
                'viewType' => $this->viewType,
                'sortArticles' => $this->sortArticles,
                'dateUpdated' => Helpers::dateFormat(time()),
                'generateRootCategoryForeignID' => 'true',
            ]
        ];
    }

    /**
     * Massage certain file names.
     *
     * As a special request, classes that are prefixed with `Gdn_` are treated as if the were under a "Gdn" namespace.
     *
     * @return array|false
     */
    private function getMassagedFiles(): array
    {
        $files = scandir(Helpers::path($this->sourcePath, 'classes'));
        foreach($files as &$file){
            if(strpos($file, 'Gdn_') === 0){
                $oldName = $file;
                $file = 'Gdn.' . substr($file, 4);
                rename(
                    Helpers::path($this->sourcePath, 'classes', $oldName),
                    Helpers::path($this->sourcePath, 'classes', $file)
                );
            }
        }
        return $files;
    }

    /**
     * Prepares a category to be passed off to the API for insertion.
     *
     * @param string $category - the name of the category
     * @param array $node - the array object of the category that may contain children
     * @param array $categories - the array of API-insertable categories
     * @param string|null $parentID - the ID of the category's parent, if applicable
     */
    private function insertCategory(string $category, $node, array &$categories, string $parentID = null): void
    {
        if(is_null($parentID)){
            $parentID = $this->foreignID . '-root';
        }

        $foreignID = Helpers::dot($parentID, $category);
        $foreignID = Helpers::rtruncate($foreignID);
        $categories[] = [
            'foreignID' => $foreignID,
            'knowledgeBaseID' => '$foreignID:' . $this->foreignID,
            'parentID' => $parentID ? '$foreignID:' . $parentID : '$foreignID:' . $this->foreignID . '-root',
            'name' => $category,
            'sourceParentID' => $parentID ? '$foreignID:' . $parentID : '',
            'rootCategory' => 'false',
        ];
        if(is_array($node)){
            foreach($node as $k => $v){
                $this->insertCategory($k, $v, $categories, $foreignID, $foreignID);
            }
        }
    }

    /**
     * Load the configs to this Source object
     */
    private function loadConfigs(): void
    {
        $this->foreignID = $this->config['foreignID'];
        $this->sourcePath = $this->config['path'];
        $this->locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;
        $this->format = $this->config['importSettings']['format'];
        $this->viewType = $this->config['importSettings']['viewType'];
        $this->sortArticles = $this->config['importSettings']['sortArticles'];
        $this->noNamespaceID = Helpers::dot($this->foreignID . '-root', '\\');
    }

    /**
     * Inserts all articles into their respective categories.
     */
    private function processKnowledgeArticles(): void
    {
        $filePath = 'classes';
        $articles = [];
        $files = scandir(Helpers::path($this->sourcePath, $filePath));
        foreach($files as $file){
            $filename = Helpers::path($this->sourcePath, $filePath, $file);
            if(is_file($filename)){
                $content = file_get_contents($filename);
                $parts = explode('.', $file);
                $ext = array_pop($parts);
                $alias = '/' . implode('.', $parts);
                if('html' === strtolower($ext)){
                    $name = array_pop($parts);
                    $foreignID = Helpers::dot($this->foreignID . '-root', $parts, $name);
                    $foreignID = Helpers::rtruncate($foreignID);
                    $knowledgeCategoryID = Helpers::dot($this->foreignID . '-root', $parts);
                    if(empty($parts)){
                        $knowledgeCategoryID = $this->noNamespaceID;
                    }
                    $knowledgeCategoryID = Helpers::rtruncate($knowledgeCategoryID);
                    $articles[] = [
                        'foreignID' => $foreignID,
                        'knowledgeCategoryID' => $knowledgeCategoryID,
                        'format' => $this->format,
                        'locale' => $this->locale,
                        'name' => $name,
                        'body' => $content,
                        'alias' => $alias,
                        'dateUpdated' => Helpers::dateFormat(time())
                    ];
                }
            }
        }
        $kbArticles = $this->getDestination()->importKnowledgeArticles($articles);
        iterator_count($kbArticles);
    }

    /**
     * Import knowledge base from config
     */
    private function processKnowledgeBase(): void
    {
        $kbs = $this->getKnowledgeBaseConfig();
        foreach ($this->getDestination()->importKnowledgeBases($kbs) as $kb) {
            $this->logger->info('Knowledge base "'.$kb['name'].'" imported successfully');
        }
    }

    /**
     * Import knowledge bases categories from HTML
     *
     * Reads all files from the source directory and uses the files names to create categories. Categories reflect
     * the class namespaces and are derived from the file name (using dot notation).
     */
    private function processKnowledgeCategories(): void
    {
        $files = $this->getMassagedFiles();
        $insertCategories = [];
        $this->buildCategories($files);
        foreach($this->categories as $k => $v){
            $this->insertCategory($k, $v, $insertCategories);
        }

        $kbCategories = $this->getDestination()->importKnowledgeCategories($insertCategories);
        $count = iterator_count($kbCategories);
        $this->logger->info($count.' knowledge categories imported successfully');
    }

}
