<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use DOMDocument;
use DOMNode;
use Garden\Schema\Schema;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\HttpClients\HttpLogMiddleware;
use Vanilla\KnowledgePorter\HttpClients\ZendeskClient;
use Vanilla\KnowledgePorter\HttpClients\HttpCacheMiddleware;

/**
 * Class ZendeskSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class ZendeskSource extends AbstractSource {
    const LIMIT = 50;
    const PAGE_START =  1;
    const PAGE_END = 10;

    const DEFAULT_SOURCE_LOCALE = 'en-us';
    const DEFAULT_LOCALE = 'en';

    /**
     * @var ZendeskClient
     */
    private $zendesk;

    /** @var ContainerInterface $container */
    protected $container;

    /**
     * ZendeskSource constructor.
     *
     * @param ZendeskClient $zendesk
     * @param ContainerInterface $container
     */
    public function __construct(ZendeskClient $zendesk, ContainerInterface $container) {
        $this->zendesk = $zendesk;
        $this->container = $container;
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
        if ($this->config['import']['categories'] ?? true) {
            $this->processKnowledgeBases();
        }
        if ($this->config['import']['sections'] ?? true) {
            $this->processKnowledgeCategories();
        }
        if ($this->config['import']['articles'] ?? true) {
            $this->processKnowledgeArticles();
        }
    }

    /**
     * Process: GET zendesk categories, POST/PATCH vanilla knowledge bases
     */
    private function processKnowledgeBases() {
        $pageLimit = $this->config['pageLimit'] ?? self::LIMIT;
        $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
        $pageTo = $this->config['pageTo'] ?? self::PAGE_END;
        $locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $knowledgeBases = $this->zendesk->getCategories($locale, ['page' => $page, 'per_page' => $pageLimit]);
            if (empty($knowledgeBases)) {
                break;
            }
            $kbs = $this->transform($knowledgeBases, [
                'foreignID' => ['column' => 'id', 'filter' => [$this, 'addPrefix']],
                'name' => 'name',
                'description' => 'description',
                'urlCode' => ['column' => 'html_url', 'filter' => [$this, 'extractUrlSlug']],
                'sourceLocale' => ['column' => 'source_locale', 'filter' => [$this, 'getSourceLocale']],
                'viewType' => 'viewType',
                'sortArticles' => 'sortArticles',
                'dateUpdated' => 'updated_at',
            ]);
            $dest = $this->getDestination();
            $kbs = $dest->importKnowledgeBases($kbs);
            $translate = $this->config['import']['translations'] ?? false;
            foreach ($kbs as $kb) {
                if ($translate) {
                    /** @var iterable $translation */
                    $translation = $this->zendesk->getCategoryTranslations($this->trimPrefix($kb['foreignID']));
                    $kbTranslations = $this->transform($translation, [
                        'recordID' => ['placeholder' => $kb['knowledgeBaseID']],
                        'dateUpdated' => 'updated_at',
                        'recordType' => ['placeholder' => 'knowledgeBase'],
                        'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                        'propertyName' => ['placeholder' => 'name'],
                        'translation' => ['column' => 'title']
                    ]);
                    $dest->importKnowledgeBaseTranslations($kbTranslations);
                    $translation = new \ArrayObject($translation);

                    $kbTranslations = $this->transform($translation, [
                        'recordID' => ['placeholder' => $kb['knowledgeBaseID']],
                        'recordType' => ['placeholder' => 'knowledgeBase'],
                        'dateUpdated' => 'updated_at',
                        'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                        'propertyName' => ['placeholder' => 'description'],
                        'translation' => ['column' => 'body'],
                        'skip' => ['column' => 'body', 'filter' => [$this, 'nullTranslation']]
                    ]);
                    $dest->importKnowledgeBaseTranslations($kbTranslations);
                }
            };
        }
    }

    /**
     * Process: GET zendesk sections, POST/PATCH vanilla knowledge categories
     */
    private function processKnowledgeCategories() {
        $pageLimit = $this->config['pageLimit'] ?? self::LIMIT;
        $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
        $pageTo = $this->config['pageTo'] ?? self::PAGE_END;
        $locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $categories = $this->zendesk->getSections($locale, ['page' => $page, 'per_page' => $pageLimit]);
            if (empty($categories)) {
                break;
            }
            $knowledgeCategories = $this->transform($categories, [
                'foreignID' => ["column" => 'id', "filter" => [$this, "addPrefix"]],
                'knowledgeBaseID' => ["column" => 'category_id', "filter" => [$this, "knowledgeBaseSmartId"]],
                'parentID' => ["column" => 'parent_section_id', "filter" => [$this, "calculateParentID"]],
                'name' => 'name',
                'dateUpdated' => 'updated_at',
            ]);
            $dest = $this->getDestination();
            $knowledgeCategories = $dest->importKnowledgeCategories($knowledgeCategories);
            $translate = $this->config['import']['translations'] ?? false;
            foreach ($knowledgeCategories as $knowledgeCategory) {
                if ($translate) {
                    /** @var iterable $translation */
                    $translation = $this->zendesk->getSectionTranslations($this->trimPrefix($knowledgeCategory['foreignID']));
                    $kbTranslations = $this->transform($translation, [
                        'recordID' => ['placeholder' => $knowledgeCategory['knowledgeCategoryID']],
                        'recordType' => ['placeholder' => 'knowledgeCategory'],
                        'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                        'propertyName' => ['placeholder' => 'name'],
                        'translation' => ['column' => 'title'],
                        'dateUpdated' => 'updated_at',
                    ]);
                    $dest->importKnowledgeBaseTranslations($kbTranslations);
                    $translation = new \ArrayObject($translation);

                    $kbTranslations = $this->transform($translation, [
                        'recordID' => ['placeholder' => $knowledgeCategory['knowledgeCategoryID']],
                        'recordType' => ['placeholder' => 'knowledgeBase'],
                        'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                        'propertyName' => ['placeholder' => 'description'],
                        'translation' => ['column' => 'body'],
                        'skip' => ['column' => 'body', 'filter' => [$this, 'nullTranslation']],
                        'dateUpdated' => 'updated_at',
                    ]);
                    $dest->importKnowledgeBaseTranslations($kbTranslations);
                }
            };
        }
    }

    /**
     * Process: GET zendesk articles, POST/PATCH vanilla knowledge base articles
     */
    private function processKnowledgeArticles() {
        $pageLimit = $this->config['pageLimit'] ?? self::LIMIT;
        $pageFrom = $this->config['pageFrom'] ?? self::PAGE_START;
        $pageTo = $this->config['pageTo'] ?? self::PAGE_END;
        $locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;


        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $articles = $this->zendesk->getArticles($locale, ['page' => $page, 'per_page' => $pageLimit]);
            if (empty($articles)) {
                break;
            }
            $knowledgeArticles = $this->transform($articles, [
                'foreignID' => ["column" => 'id', "filter" => [$this, "addPrefix"]],
                'knowledgeCategoryID' => ["column" => 'section_id', "filter" => [$this, "addPrefix"]],
                'format' => 'format',
                'locale' => ['column' => 'locale', 'filter' => [$this, 'getSourceLocale']],
                'name' => 'name',
                'body' => ['column' => 'body', 'filter' => [$this, 'parseUrls']],
                'alias' => ['column' => 'id', 'filter' => [$this, 'setAlias']],
                'dateUpdated' => 'updated_at',
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
        $newStr = '$foreignID:'.$this->config["foreignIDPrefix"].$str;
        return $newStr;
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
     * Set Alias for Zendesk Article.
     *
     * @param mixed $id
     * @return string
     * @todo Make sure to prefix with the prefix like: `<prefix>/<path>`. Hint: `parse_url()`.
     */
    protected function setAlias($id): string {
        $prefix = ($this->config["foreignIDPrefix"] !== '') ?  '/'.$this->config["foreignIDPrefix"] : '';
        $locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;
        $basePath = "$prefix/hc/$locale/articles/$id";

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
            $newStr = '$foreignID:' . $this->config["foreignIDPrefix"] . $str;
        } else {
            $newStr = 'null';
        }
        return $newStr;
    }

    /**
     * Check if translation filed is null
     *
     * @param string|null $str
     * @return string
     */
    protected function nullTranslation($str): string {
        return is_null($str) ? 'true' : 'false';
    }

    /**
     * Get source locale
     *
     * @param string $sourceLocale
     * @return string
     */
    protected function getSourceLocale(string $sourceLocale): string {
        $localeMapping = [
            "en-gb" => "en_GB",
            "es-mx" => "es_MX",
            "fr-ca" => "fr_CA",
            "mk-mk" => "mk_MK",
            "ms-my" => "ms_MY",
            "pt-br" => "pt_BR",
            "zh-tw" => "zh_TW",
            "zh-za" => "zu_Za"
        ];

        if (isset($localeMapping[$sourceLocale])) {
            $locale = $localeMapping[$sourceLocale];
        } else {
            $sourceLocale = explode("-", $sourceLocale);
            $locale = $sourceLocale[0];
        }

        return $locale;
    }

    /**
     * Parse urls from a string.
     *
     * @param string $body
     * @return string
     */
    protected function parseUrls(string $body): string {
        $sourceDomain = $this->config['domain'] ?? null;
        $targetDomain = $this->config['targetDomain'] ?? null;
        $prefix = $this->config['foreignIDPrefix'] ?? null;
        if ($sourceDomain && $targetDomain && $prefix) {
            $body = self::replaceUrls($body, $sourceDomain, $targetDomain, $prefix);
        }
        return $body;
    }

    /**
     * Replace urls with new domain.
     *
     * @param string $body
     * @param string $sourceDomain
     * @param string $targetBaseUrl
     * @param string $prefix
     *
     * @return string
     */
    public static function replaceUrls(string $body, string $sourceDomain, string $targetBaseUrl, string $prefix) {
        $contentPrefix = <<<HTML
<html><head><meta content="text/html; charset=utf-8" http-equiv="Content-Type"></head>
<body>
HTML;
        $contentSuffix = "</body></html>";
        $dom = new DOMDocument();
        @$dom->loadHTML($contentPrefix . $body . $contentSuffix, LIBXML_HTML_NOIMPLIED| LIBXML_HTML_NODEFDTD);

        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $parseUrl = parse_url($link->getAttribute('href'));
            $host  = $parseUrl['host'] ?? null;
            if ($host === $sourceDomain) {
                $newLink = str_replace($host, $targetBaseUrl.'/kb/articles/aliases/'.$prefix, $link->getAttribute('href'));
                $link->setAttribute('href', $newLink);
            }
        }
        $body = $dom->getElementsByTagName('body');

        // extract all the elements from the body.
        $innerHTML = "";
        foreach ($body as $element) {
            $innerHTML .= self::domInnerHTML($element);
        }

        return $innerHTML;
    }

    /**
     * Traverse a DomNode and return all the inner elements.
     *
     * @param DOMNode $element
     * @return string
     */
    private static function domInnerHTML(DOMNode $element):string {
        $innerHTML = "";
        $children  = $element->childNodes;

        foreach ($children as $child) {
            $innerHTML .= $element->ownerDocument->saveHTML($child);
        }

        return $innerHTML;
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

        if ($config['api']['log'] ?? true) {
            $this->zendesk->addMiddleware($this->container->get(HttpLogMiddleware::class));
        }
        if ($config['api']['cache'] ?? true) {
            $this->zendesk->addMiddleware($this->container->get(HttpCacheMiddleware::class));
        }

        $this->zendesk->setToken($this->config['token']);
        $this->zendesk->setBaseUrl($domain);
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
                "description" => "Zendesk domain.",
                "minLength" => 5
            ],
            "targetDomain:s?" => [
                "description" => "Target domain.",
                "minLength" => 5
            ],
            "token:s" => [
                "description" => "Zendesk api token. Ex: dev@mail.ru/token:8piiaCXA2ts"
            ],
            "sourceLocale:s?" => [
                "description" => "Zendesk api content source locale. Ex: en-us",
                "default" => self::DEFAULT_SOURCE_LOCALE
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
                    "articles" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                    "translations" => [
                        "type" => "boolean",
                        "default" => true,
                    ],
                ],
            ],
            "api:o?" => [
                "properties" => [
                    "log" => [
                        "type" => "boolean",
                        "default" => true,
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
