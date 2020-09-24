<?php
/**
 * @author TJ Webb <tj.webb@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Vanilla\KnowledgePorter\HttpClients\VanillaClient;

/**
 * Class VanillaSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class PhpdocHtmlSource extends AbstractSource {
    const DEFAULT_SOURCE_LOCALE = 'en';
    /**
     * @var VanillaClient
     */
    private $vanillaApi;

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
    private $locale;

    /**
     * @var string
     */
    private $sourcePath;

    /**
     * @var string
     */
    private $format;

    /**
     * @var string
     */
    private $viewType;

    /**
     * @var string
     */
    private $sortArticles;


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

        $this->loadConfigs();

        $this->processKnowledgeBases();
        $this->processKnowledgeCategories();
        $this->processKnowledgeArticles();
    }

    /**
     * Process: import knowledgeBases.xml, POST/PATCH vanilla knowledge bases
     */
    private function processKnowledgeBases() {
        $kbs = [
            [
                'foreignID' => $this->foreignID,
                'name' => $this->config['name'],
                'description' => $this->config['description'],
                'urlCode' => $this->config['urlCode'],
                'sourceLocale' => $this->locale,
                'viewType' => $this->viewType,
                'sortArticles' => $this->sortArticles,
                'dateUpdated' => $this->dateFormat(time()),
                'generateRootCategoryForeignID' => 'true',
            ]
        ];
        foreach ($this->getDestination()->importKnowledgeBases($kbs) as $kb) {
            $this->logger->info('Knowledge base "'.$kb['name'].'" imported successfully');
        }
    }

    /**
     * Process: import knowledgeCategories.xml, POST/PATCH vanilla knowledge categories
     *
     * @return iterable
     */
    private function processKnowledgeCategories() {

        $files = scandir($this->path($this->sourcePath, 'classes'));
        $this->buildCategories($files);
        foreach($this->categories as $k => $v){
            $this->insertCategory($k, $v, $insertCategories);
        }

        $kbCategories = $this->getDestination()->importKnowledgeCategories($insertCategories);
        $i = 0;
        foreach($kbCategories as $kbCat){
            $i++;
        }
        $this->logger->info($i.' knowledge categories imported successfully');
    }

    private function buildCategories(array $files)
    {
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
            }
        }
        $this->filterCategories($this->categories);
    }

    private function filterCategories(&$categories)
    {
        foreach($categories as $k => &$v){
            if(is_null($v) && is_numeric($k)){
                unset($categories[$k]);
            }
            if(is_array($v)){
                $this->filterCategories($v);
            }
            if(empty($v)){
                $v = null;
            }
        }
    }

    private function insertCategory($category, $node, &$categories, $parentID = null)
    {
        if(is_null($parentID)){
            $parentID = $this->foreignID . '-root';
        }

        $foreignID = $this->dot($parentID, $category);
        $foreignID = $this->sanitizeForiegnID($foreignID);
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

    private function processKnowledgeArticles()
    {
        $filePath = 'classes';
        $articles = [];
        $files = scandir($this->path($this->sourcePath, $filePath));
        foreach($files as $file){
            $filename = $this->path($this->sourcePath, $filePath, $file);
            if(is_file($filename)){
                $content = file_get_contents($filename);
                $parts = explode('.', $file);
                $ext = array_pop($parts);
                if('html' === strtolower($ext)){
                    $name = array_pop($parts);
                    $foreignID = $this->dot($this->foreignID . '-root', $parts, $name);
                    $foreignID = $this->sanitizeForiegnID($foreignID);
                    $knowledgeCategoryID = $this->dot($this->foreignID . '-root', $parts);
                    if(empty($parts)){
                        $knowledgeCategoryID = $this->foreignID . '-root';
                    }
                    $knowledgeCategoryID = $this->sanitizeForiegnID($knowledgeCategoryID);
                    $articles[] = [
                        'foreignID' => $foreignID,
                        'knowledgeCategoryID' => $knowledgeCategoryID,
                        'format' => $this->format,
                        'locale' => $this->locale,
                        'name' => $name,
                        'body' => $content,
                        'alias' => $file,
                        'dateUpdated' => $this->dateFormat(time())
                    ];
                }
            }
        }
        $kbArticles = $this->getDestination()->importKnowledgeArticles($articles);
        $i = 0;
        foreach ($kbArticles as $article) {
            $i++;
        }
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
     * Set config
     *
     * @param array $config
     */
    public function setConfig(array $config): void {
        $this->config = $config;
    }

    protected function dateFormat(int $date): string {
        return date(DATE_ATOM, $date);
    }

    private function sanitizeForiegnID(string $input, int $length = 32) : string {
        return substr($input, ($length * -1), $length);
    }

    private function loadConfigs(): void {
        $this->foreignID = $this->config['foreignID'];
        $this->sourcePath = $this->config['path'];
        $this->locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;
        $this->format = $this->config['importSettings']['format'];
        $this->viewType = $this->config['importSettings']['viewType'];
        $this->sortArticles = $this->config['importSettings']['sortArticles'];
    }

    private function path(): string {
        $args = func_get_args();
        foreach($args as $i => &$arg){
            if(0 === $i){
                $arg = rtrim($arg, DIRECTORY_SEPARATOR);
            }else{
                $arg = trim($arg, DIRECTORY_SEPARATOR);
            }
        }
        return implode(DIRECTORY_SEPARATOR, $args);
    }

    private function dot(): string {
        $args = func_get_args();
        $values = [];
        foreach($args as $arg){
            if(is_array($arg)){
                $arg = array_filter($arg);
                foreach($arg as $v){
                    if(is_string($v) || is_numeric($v)){
                        $values[] = $v;
                    }elseif(is_array($v)){
                        $values[] = $this->dot($v);
                    }
                }
            }elseif(is_string($arg) || is_numeric($arg)){
                $values[] = $arg;
            }
        }
        return implode('.', $values);
    }
}
