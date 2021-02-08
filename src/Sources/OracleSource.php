<?php
/**
 * @author Olivier Lamy-Canuel <olivier.lamy-canuel@vanillaforums.com>
 * @copyright 2009-2021 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use DOMDocument;
use DOMNode;
use Garden\Container\Container;
use Garden\Http\HttpResponseException;
use Garden\Schema\Schema;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\Destinations\VanillaDestination;
use Vanilla\KnowledgePorter\HttpClients\HttpLogMiddleware;
use Vanilla\KnowledgePorter\HttpClients\NotFoundException;
use Vanilla\KnowledgePorter\HttpClients\OracleClient;
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;
use Vanilla\KnowledgePorter\HttpClients\HttpCacheMiddleware;

/**
 * Class OracleSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class OracleSource extends AbstractSource {
    const LIMIT = 50;
    const PAGE_START =  1;
    const PAGE_END = 10;

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
        // TODO
    }

    /**
     * Process: GET oracle sections, POST/PATCH vanilla knowledge categories
     *
     * @return iterable
     */
    private function processKnowledgeCategories() {
        [$pageLimit, $pageFrom, $pageTo] = $this->getPaginationInformation();

        /** @var VanillaDestination $dest */
        $dest = $this->getDestination();

        do {
            $results = $this->oracle->getCategories(['fromId' => $pageFrom, 'limit' => $pageLimit]);
            $categories = $results['items'];

            $knowledgeCategories = $this->transform($categories, [
                'knowledgeCategoryID' => 'id',
                'parentID' => 'parent',
                'name' => 'lookupName',
                'description' => 'description',
                'viewType' => 'viewType',
                'sortArticles' => 'sortArticles',
            ]);

            $knowledgeCategories = $dest->importKnowledgeCategories($knowledgeCategories);
            $this->translateKnowledgeCategories($knowledgeCategories);

            if($results["links"][2]["rel"] == "next"){
                $pageFrom = $categories[$pageLimit -1]["id"];
            } else {
                break;
            }
        } while($results["links"][2]["rel"] == "next");
    }

    /**
     * Process: GET oracle articles, POST/PATCH vanilla knowledge base articles
     */
    private function processKnowledgeArticles() {

        $this->oracle->getProducts();

        [$pageLimit, $pageFrom, $pageTo] = $this->getPaginationInformation();

        do {
            $results = $this->oracle->getArticles(['fromId' => $pageFrom, 'limit' => $pageLimit]);
            $articles = $results['items'];
            $knowledgeArticles = $this->transform($articles, [
                'articleID' => 'siblingArticleID',
                'articleRevisionID' =>'id',
                'foreignID' => 'id',
                'knowledgeCategoryID' => 'knowledgeCategoryID' ,
                'format' => 'format',
                'locale' => 'language',
                'name' => 'summary',
                'body' => 'body',
                'dateUpdated' => 'createdTime',
                'dateInserted' => 'updatedTime',
            ]);

            $dest = $this->getDestination();
            $dest->importKnowledgeArticles($knowledgeArticles);

            if($results["links"][2]["rel"] == "next"){
                $pageFrom = $articles[$pageLimit -1]["id"];
            } else {
                break;
            }
        } while($results["links"][2]["rel"] == "next");
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
                    "sections" => [
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
                    "delete" => [
                        "type" => "boolean",
                        "default" => false,
                    ],
                    "fetchDraft" => [
                        "type" => "boolean",
                        "default" => false,
                    ],
                    "fetchPrivateArticles" => [
                        "type" => "boolean",
                        "default" => false,
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

    /**
     * Set PageLimits for import.
     *
     * @return array
     */
    private function setPageLimits(): array {
        if ($this->config['articleLimit'] ?? false) {
            $pageLimit = $this->config['articleLimit'];
            $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
            $pageTo = $pageFrom;
            $this->logger->info('Article limit set to ' . $this->config['articleLimit'] . 'will be fetched');
        } else {
            $pageLimit = $this->config['pageLimit'] ?? self::LIMIT;
            $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
            $pageTo = $this->config['pageTo'] ?? self::PAGE_END;
            $this->logger->info('No Article limit set to all articles will be fetched');
        }

        return array($pageLimit, $pageFrom, $pageTo);
    }

    private function translateKnowledgeCategories(iterable $knowledgeCategories) {
        $dest = $this->getDestination();

        foreach($knowledgeCategories as $knowledgeCategory){

            $this->oracle->getCategoryTranslations($knowledgeCategory["knowledgeCategoryID"]);

            foreach($knowledgeCategory['translations'] as $translation){

                $kbTranslations = $this->transform($translation, [
                    'recordID' => ['placeholder' => $knowledgeCategory['knowledgeCategoryID']],
                    'recordType' => ['placeholder' => 'knowledgeCategory'],
                    'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                    'propertyName' => ['placeholder' => 'name'],
                    'translation' => ['column' => 'name'],
                ]);
                $dest->importKnowledgeBaseTranslations($kbTranslations);

                $translation = new \ArrayObject($translation);
                $kbTranslations = $this->transform($translation, [
                    'recordID' => ['placeholder' => $knowledgeCategory['knowledgeCategoryID']],
                    'recordType' => ['placeholder' => 'knowledgeCategory'],
                    'locale' => ['column' => 'locale', 'filter' => [$this, $knowledgeCategory['knowledgeCategoryID']]],
                    'propertyName' => ['placeholder' => 'description'],
                    'translation' => ['column' => 'description'],
                ]);
                $dest->importKnowledgeBaseTranslations($kbTranslations);
            }

        }
    }
}
