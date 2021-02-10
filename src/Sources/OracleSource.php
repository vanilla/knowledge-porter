<?php
/**
 * @author Olivier Lamy-Canuel <olivier.lamy-canuel@vanillaforums.com>
 * @copyright 2009-2021 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;


use Garden\Schema\Schema;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\Destinations\VanillaDestination;
use Vanilla\KnowledgePorter\HttpClients\HttpLogMiddleware;
use Vanilla\KnowledgePorter\HttpClients\OracleClient;
use Vanilla\KnowledgePorter\HttpClients\HttpCacheMiddleware;

/**
 * Class OracleSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class OracleSource extends AbstractSource {
    const LIMIT = 2;
    const PAGE_START =  1;
    const DEFAULT_LOCALE = 'en';

    /** @var ContainerInterface $container */
    protected $container;
    /**
     * @var OracleClient
     */
    private $oracle;

    /**
     *OracleSource constructor.
     *
     * @param OracleClient $oracle
     * @param ContainerInterface $container
     */
    public function __construct(OracleClient $oracle, ContainerInterface $container) {
        $this->oracle = $oracle;
        $this->container = $container;
    }

    /**
     * Get our authorization headers so that we can rehost files.
     *
     * @return array
     */
    public function getFileRehostingHeaders(): array {

        $this->oracle->setToken($this->config["username"], $this->config["password"]);
        $authHeader = $this->oracle->getToken();

        if ($authHeader == null) {
            return [];
        }

        $result = [
            "Authorization: $authHeader",
        ];

        return $result;
    }

    /**
     * Execute import content actions
     */
    public function import(): void {

        if ($this->config['import']['knowledgeBase'] ?? true) {
            $this->processKnowledgeBases();
        }

        if ($this->config['import']['Categories'] ?? true) {
            $this->processKnowledgeCategories();
        }

        if ($this->config['import']['articles'] ?? true) {
            $this->processKnowledgeArticles();
        }

    }

    /**
     * Process: GET oracle categories, POST/PATCH vanilla knowledge bases
     */
    private function processKnowledgeBases() {
        $kbs[1]['foreignID'] = 1;
        $kbs[1]['name'] = 'OracleKnowledgeBase';
        $kbs[1]['description'] = 'placeholder';
        $kbs[1]['urlCode'] = 'kb';
        $kbs[1]['sourceLocale'] = self::DEFAULT_LOCALE;
        $kbs[1]["viewType"] = "help";
        $kbs[1]["sortArticles"] = "dateInsertedDesc";

        $dest = $this->getDestination();
        $dest->importKnowledgeBases($kbs);
    }

    /**
     * Process: GET oracle sections, POST/PATCH vanilla knowledge categories
     *
     * @return iterable
     */
    private function processKnowledgeCategories() {
        [$perPage, $pageFrom] = $this->getPaginationInformation();

        do {
            $results = $this->oracle->getCategories(['fromId' => $pageFrom, 'limit' => $perPage]);
            $categories = $results['items'];
            $knowledgeCategories = $this->transform($categories, [
                'knowledgeBaseID' => 'knowledgeBaseID',
                'parentID' => 'parent',
                'foreignID' => 'foreignID',
                'name' => 'name',
                'description' => 'description',
                'viewType' => 'viewType',
                'sortArticles' => 'sortArticles',
            ]);
            $dest = $this->getDestination();
            $dest->importKnowledgeCategories($knowledgeCategories);
            $translate = $this->config['import']['translations'] ?? false;
            if($translate){
                $this->translateKnowledgeCategories($categories);
            }

            if($results["next"]){
                $pageFrom = end( $categories)["foreignID"];
            } else {
                break;
            }
        } while($results["next"] == "next");
    }

    /**
     * Process: GET oracle articles, POST/PATCH vanilla knowledge base articles
     */
    private function processKnowledgeArticles() {
        $locales = $this->config['import']['locales'];
        $importProduct = $this->config['import']['products'];
        $importVariables = $this->config['import']['variables'];

        if($importProduct){
            $this->oracle->getProducts();
        }

        if($importVariables){
            $this->oracle->getVariables();
        }


        [$perPage, $pageFrom] = $this->getPaginationInformation();

        do {
            $results = $this->oracle->getArticles(['fromId' => $pageFrom, 'limit' => $perPage], $locales, $importProduct ,$importVariables);
            $articles = $results['items'];
            $knowledgeArticles = $this->transform($articles, [
                'articleID' => 'articleID',
                'foreignID' => 'foreignID',
                'knowledgeBaseID' => 'knowledgeBaseID',
                'knowledgeCategoryID' =>  'knowledgeCategoryID',
                'format' => 'format',
                'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                'name' => 'name',
                'body' => 'body',
                'skip' => 'skip',
                'dateUpdated' => 'createdTime',
                'dateInserted' => 'updatedTime',
            ]);

            $dest = $this->getDestination();
            $dest->importKnowledgeArticles($knowledgeArticles);

            if($results["next"]){
                $pageFrom = end($articles)["foreignID"];
            } else {
                break;
            }
        } while($results["next"] == "next");
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

        $domain = $this->config['domain'];
        $domain = "https://$domain";

        if ($config['api']['log']) {
            /** @var HttpLogMiddleware $middleware */
            $middleware = $this->container->get(HttpLogMiddleware::class);
            if ($config['api']['verbose']) {
                $middleware->setLogBodies(true);
            }
            $this->oracle->addMiddleware($middleware);
        }
        if ($config['api']['cache'] ?? false) {
            $this->oracle->addMiddleware($this->container->get(HttpCacheMiddleware::class));
        }

        $this->oracle->setToken($this->config['username'], $this->config['password']);
        $this->oracle->setBaseUrl($domain);
    }

    protected function translateKnowledgeCategories(iterable $knowledgeCategories) {
        $dest = $this->getDestination();

        foreach($knowledgeCategories as $knowledgeCategory){

            $translations = $this->oracle->getCategoryTranslations($knowledgeCategory["id"]);

            foreach($translations as $translation){
                $kbTranslations = $this->transform($translation, [
                    'recordID' => ['placeholder' => $knowledgeCategory['id']],
                    'recordType' => ['placeholder' => 'knowledgeCategory'],
                    'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                    'propertyName' => ['placeholder' => 'name'],
                    'translation' => ['column' => 'value']
                ]);
                $dest->importKnowledgeBaseTranslations($kbTranslations);
            }

        }
    }

    /**
     * Grab all the pagination information from config
     *
     * @return array
     */
    protected function getPaginationInformation(): array {
        $pageLimit = $this->config['perPage'] ?? self::LIMIT;
        $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
        return array($pageLimit, $pageFrom);
    }


    /**
     * Get source locale
     *
     * @param string $sourceLocale
     * @return string
     */
    protected function getSourceLocale(string $sourceLocale): string {

        $dialects = ["en_GB", "es_MX", "fr_CA", "mk_MK", "ms_MY", "pt_BR","zh_TW", "zu_Za"];

        if (in_array($sourceLocale, $dialects)) {
            $locale =$sourceLocale;
        } else {
            $sourceLocale = explode("_", $sourceLocale);
            $locale = $sourceLocale[0];
        }

        return $locale;
    }

    /**
     * Get schema for config.
     *
     * @return Schema
     */
    private function configSchema(): Schema {
        return Schema::parse([
            "type:s?" => ["default" => 'oracle'],
            "domain:s" => [
                "description" => "Oracle domain.",
                "minLength" => 5
            ],
            "targetDomain:s?" => [
                "description" => "Target domain.",
                "minLength" => 5
            ],
            "username:s" => [
                "description" => "Oracle Cloud Services username. Ex: vanilla1234"
            ],
            "password:s" => [
                "description" => "Oracle Cloud Services password. Ex: vanilla1234"
            ],
            "articleLimit:i?" => [
                "allowNull" => true,
                "minimum" => 1,
                "maximum" => 300,
            ],
            "pageLimit:i?" => [
                "default" => 100,
                "minimum" => 1,
                "maximum" => 300,
            ],
            "pageFrom:i?" => [
                "description" => "Page number to start pull from api.",
                "default" => 1,
                "minimum" => 1,
                "maximum" => 1000,
            ],
            "pageTo:i?" => [
                "description" => "Page number to end pull from api.",
                "default" => 100,
                "minimum" => 1,
                "maximum" => 1000,
            ],
            "syncFrom:s?" => [
                "description" => "Days or Date from which to start import or sync",
                "allowNull" => true,
                "minLength" => 5
            ],
            "import:o?" => [
                "description" => "Import by content type: categories, sections, articles.",
                "properties" => [
                    "categories" => [
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
                    "retrySections" => [
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
                    "products" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "variables" => [
                        "type" => "boolean",
                        "default" => false,
                    ],
                    "locales" => [
                        "default" => ['en_US'],
                    ],
                ],
            ],
            "localeMapping:o?",
            "api:o?" => [
                "properties" => [
                    "log" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "verbose" => [
                        "type" => "boolean",
                        "default" => false,
                    ],
                    "cache" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                ],

            ]
        ]);
    }
}

