<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
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
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;
use Vanilla\KnowledgePorter\HttpClients\ZendeskClient;
use Vanilla\KnowledgePorter\HttpClients\HttpCacheMiddleware;

/**
 * Class ZendeskSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class ZendeskSource extends AbstractSource
{
    const LIMIT = 50;
    const PAGE_START = 1;
    const PAGE_END = 10;

    const DEFAULT_SOURCE_LOCALE = "en-us";
    const DEFAULT_LOCALE = "en";

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
     * @param Container $container
     */
    public function __construct(ZendeskClient $zendesk, Container $container)
    {
        $this->zendesk = $zendesk;
        $this->container = $container;
    }

    /**
     * Get our authorization headers so that we can rehost files.
     *
     * @return array
     */
    public function getFileRehostingHeaders(): array
    {
        $zdAuthHeader = $this->zendesk->getDefaultHeader("Authorization");

        if ($zdAuthHeader == null) {
            return [];
        }

        $result = ["Authorization: $zdAuthHeader"];

        return $result;
    }

    /**
     * @param string $basePath
     */
    public function setBasePath(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Execute import content actions
     */
    public function import(): void
    {
        $deleteModeEnabled = $this->config["import"]["delete"] ?? false;
        if ($deleteModeEnabled) {
            $this->syncUpArchivedZendeskArticles();
        } else {
            if ($this->config["import"]["categories"] ?? true) {
                $this->processKnowledgeBases();
            }

            if ($this->config["import"]["sections"] ?? true) {
                $this->processKnowledgeCategories();
            }

            if ($this->config["import"]["articles"] ?? true) {
                $this->processKnowledgeArticles();
            }
        }
    }

    /**
     * Process: GET zendesk categories, POST/PATCH vanilla knowledge bases
     * @return array
     */
    public function processKnowledgeBases(): array
    {
        [$pageLimit, $pageFrom, $pageTo] = $this->getPaginationInformation();
        $locale = $this->config["sourceLocale"] ?? self::DEFAULT_SOURCE_LOCALE;
        $results = [];

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $knowledgeBases = $this->zendesk->getCategories($locale, [
                "page" => $page,
                "per_page" => $pageLimit,
            ]);
            if (empty($knowledgeBases)) {
                return [];
            }
            $kbs = $this->transform($knowledgeBases, [
                "foreignID" => [
                    "column" => "id",
                    "filter" => [$this, "addPrefix"],
                ],
                "name" => "name",
                "description" => "description",
                "urlCode" => [
                    "column" => "html_url",
                    "filter" => [$this, "extractUrlSlug"],
                ],
                "sourceLocale" => [
                    "column" => "source_locale",
                    "filter" => [$this, "getDestinationLocale"],
                ],
                "viewType" => "viewType",
                "sortArticles" => "sortArticles",
                "dateUpdated" => "updated_at",
            ]);
            $dest = $this->getDestination();
            $kbs = $dest->importKnowledgeBases($kbs);
            $translate = $this->config["import"]["translations"] ?? false;
            foreach ($kbs as $kb) {
                $results[] = $kb["foreignID"];
                if ($translate) {
                    /** @var iterable $translation */
                    $translation = $this->zendesk->getCategoryTranslations(
                        $this->trimPrefix($kb["foreignID"])
                    );
                    $kbTranslations = $this->transform($translation, [
                        "recordID" => ["placeholder" => $kb["knowledgeBaseID"]],
                        "dateUpdated" => "updated_at",
                        "recordType" => ["placeholder" => "knowledgeBase"],
                        "locale" => [
                            "column" => "locale",
                            "filter" => [$this, "getSourceLocale"],
                        ],
                        "propertyName" => ["placeholder" => "name"],
                        "translation" => ["column" => "title"],
                    ]);
                    $dest->importKnowledgeBaseTranslations($kbTranslations);
                    $translation = new \ArrayObject($translation);

                    $kbTranslations = $this->transform($translation, [
                        "recordID" => ["placeholder" => $kb["knowledgeBaseID"]],
                        "recordType" => ["placeholder" => "knowledgeBase"],
                        "dateUpdated" => "updated_at",
                        "locale" => [
                            "column" => "locale",
                            "filter" => [$this, "getSourceLocale"],
                        ],
                        "propertyName" => ["placeholder" => "description"],
                        "translation" => ["column" => "body"],
                        "skip" => [
                            "column" => "body",
                            "filter" => [$this, "nullTranslation"],
                        ],
                    ]);
                    $dest->importKnowledgeBaseTranslations($kbTranslations);
                }
            }
        }

        return $results;
    }

    /**
     * Process: GET zendesk sections, POST/PATCH vanilla knowledge categories
     *
     * @return array
     */
    public function processKnowledgeCategories(): array
    {
        [$pageLimit, $pageFrom, $pageTo] = $this->getPaginationInformation();
        $locale = $this->config["sourceLocale"] ?? self::DEFAULT_SOURCE_LOCALE;
        $results = [];

        /** @var VanillaDestination $dest */
        $dest = $this->getDestination();

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $categories = $this->zendesk->getSections($locale, [
                "page" => $page,
                "per_page" => $pageLimit,
            ]);
            if (empty($categories)) {
                return [];
            }

            $knowledgeCategories = $this->transform($categories, [
                "foreignID" => [
                    "column" => "id",
                    "filter" => [$this, "addPrefix"],
                ],
                "knowledgeBaseID" => [
                    "column" => "category_id",
                    "filter" => [$this, "knowledgeBaseSmartId"],
                ],
                "parentID" => [
                    "column" => "parent_section_id",
                    "filter" => [$this, "calculateParentID"],
                ],
                "name" => "name",
                "dateUpdated" => "updated_at",
            ]);

            $knowledgeCategories = $dest->importKnowledgeCategories(
                $knowledgeCategories
            );
            $translate = $this->config["import"]["translations"] ?? false;
            $this->translateKnowledgeCategories(
                $knowledgeCategories,
                $translate
            );
            foreach ($knowledgeCategories as $knowledgeCategory) {
                $results[] = $knowledgeCategory["foreignID"];
            }
        }
        if ($this->config["import"]["retrySections"] ?? true) {
            $this->rerunProcessKnowledgeCategories($dest);
        }

        return $results;
    }

    /**
     * Retry processing failed knowledge categories.
     *
     * @param $dest
     */
    private function rerunProcessKnowledgeCategories(VanillaDestination $dest)
    {
        $knowledgeCategories = $dest->processFailedImportedKnowledgeCategories();
        $translate = $this->config["import"]["translations"] ?? false;
        $this->translateKnowledgeCategories($knowledgeCategories, $translate);
    }

    /**
     * Process: GET zendesk articles, POST/PATCH vanilla knowledge base articles
     * @return array
     */
    public function processKnowledgeArticles(): array
    {
        [$pageLimit, $pageFrom, $pageTo] = $this->setPageLimits();
        $locale = $this->config["sourceLocale"] ?? self::DEFAULT_SOURCE_LOCALE;
        $skipStatus = [];
        $results = [];

        if (!($this->config["import"]["fetchDraft"] ?? false)) {
            array_push($skipStatus, "draft");
        }

        if (!($this->config["import"]["fetchPrivateArticles"] ?? false)) {
            array_push($skipStatus, "user_segment_id");
        }

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $queryParams = ["page" => $page, "per_page" => $pageLimit];

            $syncFrom = $this->config["syncFrom"] ?? null;
            $syncFrom = strtotime($syncFrom);
            $currentTime = time();

            $syncFrom = $syncFrom >= $currentTime ? false : $syncFrom;
            if ($syncFrom) {
                $queryParams["start_time"] = $syncFrom;
            }

            $articles = $this->zendesk->getArticles($locale, $queryParams);
            if (empty($articles)) {
                return [];
            }
            $knowledgeArticles = $this->transform($articles, [
                "foreignID" => [
                    "column" => "id",
                    "filter" => [$this, "addPrefix"],
                ],
                "userData" => [
                    "column" => "author_id",
                    "filter" => [$this, "getUserData"],
                ],
                "knowledgeCategoryID" => [
                    "column" => "section_id",
                    "filter" => [$this, "addPrefix"],
                ],
                "format" => ["placeholder" => "wysiwyg"],
                "locale" => [
                    "column" => "locale",
                    "filter" => [$this, "getDestinationLocale"],
                ],
                "name" => "name",
                "body" => [
                    "column" => "body",
                    "filter" => [$this, "prepareBody"],
                ],
                "featured" => ["column" => "promoted"],
                "alias" => ["column" => "id", "filter" => [$this, "setAlias"]],
                "skip" => [
                    "columns" => $skipStatus,
                    "filter" => [$this, "setSkipStatus"],
                ],
                "dateUpdated" => "updated_at",
                "dateInserted" => "created_at",
            ]);
            $dest = $this->getDestination();
            $kbArticles = $dest->importKnowledgeArticles($knowledgeArticles);
            $translate = $this->config["import"]["translations"] ?? false;
            foreach ($kbArticles as $kbArticle) {
                $results[] = $kbArticle["foreignID"];
                if ($translate) {
                    if (!$kbArticle) {
                        $this->logger->error(
                            "Skipping foreign article translations because the article failed to be created."
                        );
                        continue;
                    }
                    /** @var iterable $translation */
                    $translation = $this->zendesk->getArticleTranslations(
                        $this->trimPrefix($kbArticle["foreignID"])
                    );
                    $kbTranslations = $this->transform($translation, [
                        "articleID" => [
                            "placeholder" => $kbArticle["articleID"],
                        ],
                        "knowledgeCategoryID" => [
                            "placeholder" => $kbArticle["knowledgeCategoryID"],
                        ],
                        "format" => ["placeholder" => "wysiwyg"],
                        "locale" => [
                            "column" => "locale",
                            "filter" => [$this, "getSourceLocale"],
                        ],
                        "name" => "title",
                        "body" => [
                            "column" => "body",
                            "filter" => [$this, "prepareBody"],
                        ],
                        "skip" => [
                            "columns" => $skipStatus,
                            "filter" => [$this, "setSkipStatus"],
                        ],
                        // Explicitly mapped insert to update.
                        // Translations can only be added with an insert date.
                        "dateUpdated" => "updated_at",
                        "dateInserted" => "updated_at",
                        "userData" => [
                            "columns" => ["updated_by_id", "created_by_id"],
                            "filter" => [$this, "getUserUpdatedData"],
                        ],
                    ]);
                    $dest->importArticleTranslations(
                        $kbArticle["articleID"],
                        $kbTranslations
                    );
                }
                if ($this->config["import"]["helpful"] ?? true) {
                    /** @var iterable $votes */
                    $votes = $this->zendesk->getArticleVotes(
                        $this->trimPrefix($kbArticle["foreignID"]),
                        $locale
                    );
                    $kbVotes = $this->transform($votes, [
                        "userData" => [
                            "column" => "user_id",
                            "filter" => [$this, "getUserData"],
                        ],
                        "foreignID" => [
                            "column" => "id",
                            "filter" => [$this, "addPrefix"],
                        ],
                        "helpful" => [
                            "column" => "value",
                            "filter" => [$this, "getHelpful"],
                        ],
                        "articleID" => [
                            "placeholder" => $kbArticle["articleID"],
                        ],
                    ]);
                    $dest->importArticleVotes($kbVotes);
                }
            }
        }
        return $results;
    }

    /**
     * Prepare knowledge base smart id.
     *
     * @param mixed $str
     * @return string
     */
    protected function knowledgeBaseSmartId($str): string
    {
        $newStr = '$foreignID:' . $this->config["foreignIDPrefix"] . $str;
        return $newStr;
    }

    /**
     * @param $str
     * @return string
     */
    protected function getHelpful($str): string
    {
        $newStr = $str == 1 ? "yes" : "no";
        return $newStr;
    }

    /**
     * @param $userID
     * @return array
     */
    protected function getUserData($userID): array
    {
        $data = [];
        if ($this->config["import"]["authors"] ?? false) {
            if (!empty($userID)) {
                $data = $this->zendesk->getUser($userID);
                $data["password"] =
                    $this->config["foreignIDPrefix"] . $data["name"];
                $data["emailConfirmed"] = true;
                $data["bypassSpam"] = true;
            }
        }
        return $data;
    }

    /**
     * @param array $userFieldNames
     * @return array
     */
    protected function getUserUpdatedData(
        array $userFieldNames,
        array $row
    ): array {
        $data = [];
        if ($this->config["import"]["authors"] ?? false) {
            foreach ($userFieldNames as $userField) {
                $userID = $row[$userField];
                if (!empty($userID)) {
                    $data = $this->zendesk->getUser($userID);
                    $data["password"] =
                        $this->config["foreignIDPrefix"] . $data["name"];
                    $data["emailConfirmed"] = true;
                    $data["bypassSpam"] = true;
                    break;
                }
            }
        }
        return $data;
    }

    /**
     * Add foreignID prefix to string.
     *
     * @param mixed $str
     * @return string
     */
    protected function addPrefix($str): string
    {
        $newStr = $this->config["foreignIDPrefix"] . $str;
        return $newStr;
    }

    /**
     * Add foreignID prefix to string.
     *
     * @param mixed $str
     * @return string
     */
    protected function trimPrefix($str): string
    {
        $newStr = str_replace($this->config["foreignIDPrefix"], "", $str);
        return $newStr;
    }

    /**
     * Extract url slug from zendesk category url.
     *
     * @param mixed $str
     * @return string
     */
    protected function extractUrlSlug($str): string
    {
        $pathInfo = pathinfo($str);
        $slug = $pathInfo["basename"] ?? null;
        $urlCode = strtolower($this->config["foreignIDPrefix"] . $slug);
        return $urlCode;
    }

    /**
     * Set Alias for Zendesk Article.
     *
     * @param mixed $id
     * @return string
     * @todo Make sure to prefix with the prefix like: `<prefix>/<path>`. Hint: `parse_url()`.
     */
    protected function setAlias($id): string
    {
        $prefix =
            $this->config["foreignIDPrefix"] !== ""
                ? "/" . $this->config["foreignIDPrefix"]
                : "";
        $locale = $this->config["sourceLocale"] ?? self::DEFAULT_SOURCE_LOCALE;
        $basePath = "$prefix/hc/$locale/articles/$id";

        return $basePath;
    }

    /**
     * Calculate parentID smart key.
     *
     * @param mixed $str
     * @return string
     */
    protected function calculateParentID($str): string
    {
        if (!is_null($str)) {
            $newStr = '$foreignID:' . $this->config["foreignIDPrefix"] . $str;
        } else {
            $newStr = "null";
        }
        return $newStr;
    }

    /**
     * Check if translation filed is null
     *
     * @param string|null $str
     * @return string
     */
    protected function nullTranslation($str): string
    {
        return is_null($str) ? "true" : "false";
    }

    /**
     * Get source locale
     *
     * @param string $sourceLocale
     * @return string
     */
    protected function getSourceLocale(string $sourceLocale): string
    {
        $configMapping = $this->config["localeMapping"] ?? [];
        $localeMapping = $configMapping + [
            "en-gb" => "en_GB",
            "es-mx" => "es_MX",
            "fr-ca" => "fr_CA",
            "mk-mk" => "mk_MK",
            "ms-my" => "ms_MY",
            "pt-br" => "pt_BR",
            "zh-tw" => "zh_TW",
            "zh-za" => "zu_Za",
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
     * Get destination locale
     *
     *
     * @param string $sourceLocale
     * @return string
     */
    protected function getDestinationLocale(string $sourceLocale): string
    {
        $locale = $this->config["destinationLocale"] ?? $sourceLocale;
        return $this->getSourceLocale($locale);
    }

    /**
     * Parse urls from a string.
     *
     * @param string $body
     * @return string
     */
    protected function parseUrls(string $body = ""): string
    {
        $sourceDomain = $this->config["domain"] ?? null;
        $targetDomain = $this->config["targetDomain"] ?? null;
        $prefix = $this->config["foreignIDPrefix"] ?? null;
        if ($sourceDomain && $targetDomain && $prefix) {
            $body = self::replaceUrls(
                $body,
                $sourceDomain,
                $targetDomain,
                $prefix
            );
        }
        return $body;
    }

    /**
     * Prepare article body: parse urls and parse attachments if needed.
     *
     * @param string|null $body
     * @param array $row
     * @return string
     */
    protected function prepareBody(array $row, ?string $body = null): string
    {
        $returnBody = "";
        if (is_string($body)) {
            $returnBody = $this->parseUrls($body);
            if ($this->config["import"]["attachments"] ?? false) {
                $returnBody = $this->addAttachments($returnBody, $row);
            }
        }

        return $returnBody;
    }

    /**
     * Add html elements with attachment links
     *
     * @param string $body
     * @param array $article
     * @return string
     */
    public function addAttachments(string $body, array $article): string
    {
        $attachments = $this->zendesk->getArticleAttachments($article["id"]);

        foreach ($attachments as $attachment) {
            $url = htmlspecialchars($attachment["content_url"]);
            $name = htmlspecialchars($attachment["display_file_name"]);
            $body .= '<p><a href="' . $url . '" download>' . $name . "</a></p>";
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
    public static function replaceUrls(
        string $body,
        string $sourceDomain,
        string $targetBaseUrl,
        string $prefix
    ) {
        $contentPrefix = <<<HTML
<html><head><meta content="text/html; charset=utf-8" http-equiv="Content-Type"></head>
<body>
HTML;
        $contentSuffix = "</body></html>";
        $dom = new DOMDocument();
        @$dom->loadHTML(
            $contentPrefix . $body . $contentSuffix,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $links = $dom->getElementsByTagName("a");
        foreach ($links as $link) {
            $url = str_replace('\"', "", $link->getAttribute("href"));
            $parseUrl = parse_url($url);
            $host = $parseUrl["host"] ?? null;
            if ($host === $sourceDomain) {
                $newLink = str_replace(
                    $host,
                    $targetBaseUrl . "/kb/articles/aliases/" . $prefix,
                    $link->getAttribute("href")
                );
                $link->setAttribute("href", $newLink);
            }
        }
        $body = $dom->getElementsByTagName("body");

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
    private static function domInnerHTML(DOMNode $element): string
    {
        $innerHTML = "";
        $children = $element->childNodes;

        foreach ($children as $child) {
            $t = $element->ownerDocument->saveHTML($child);
            $innerHTML .= $element->ownerDocument->saveHTML($child);
        }

        return $innerHTML;
    }

    /**
     * Check if record should be be skipped.
     *
     * @param array $columns
     * @param array $row
     * @return string
     */
    public function setSkipStatus(array $columns, array $row): string
    {
        $skip = "false";

        if (in_array("draft", $columns) && ($row["draft"] ?? null)) {
            $this->logger->warning("Skipping item because it is a draft.");
            $skip = "true";
        }

        if (
            in_array("user_segment_id", $columns) &&
            ($row["user_segment_id"] ?? null)
        ) {
            $this->logger->warning(
                "Skipping item because it is has a `user_segment_id`."
            );
            $skip = "true";
        }
        return $skip;
    }

    /**
     * Set config values.
     *
     * @param array $config
     */
    public function setConfig(array $config): void
    {
        /** @var Schema $schema */
        $schema = $this->configSchema();
        $config = $schema->validate($config);
        $this->config = $config;

        $domain = $this->config["domain"];
        $domain = "https://$domain";

        if ($config["api"]["log"]) {
            /** @var HttpLogMiddleware $middleware */
            $middleware = $this->container->get(HttpLogMiddleware::class);
            if ($config["api"]["verbose"]) {
                $middleware->setLogBodies(true);
            }
            $this->zendesk->addMiddleware($middleware);
        }
        if ($config["api"]["cache"] ?? false) {
            $this->zendesk->addMiddleware(
                $this->container->get(HttpCacheMiddleware::class)
            );
        }

        $this->zendesk->setToken($this->config["token"]);
        $this->zendesk->setBaseUrl($domain);
    }

    /**
     * Get schema for config.
     *
     * @return Schema
     */
    private function configSchema(): Schema
    {
        return Schema::parse([
            "type:s?" => ["default" => "zendesk"],
            "foreignIDPrefix:s?" => ["default" => "zd-"],
            "domain:s" => [
                "description" => "Zendesk domain.",
                "minLength" => 5,
            ],
            "targetDomain:s?" => [
                "description" => "Target domain.",
                "minLength" => 5,
            ],
            "token:s" => [
                "description" =>
                    "Zendesk api token. Ex: dev@mail.ru/token:8piiaCXA2ts",
            ],
            "sourceLocale:s?" => [
                "description" => "Zendesk api content source locale. Ex: en-us",
                "default" => self::DEFAULT_SOURCE_LOCALE,
            ],
            "destinationLocale:s?" => [
                "description" =>
                    "Content destination locale. Used only by non-translated categories & articles Ex: en-us",
                "default" => null,
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
                "description" =>
                    "Days or Date from which to start import or sync",
                "allowNull" => true,
                "minLength" => 5,
            ],
            "import:o?" => [
                "description" =>
                    "Import by content type: categories, sections, articles.",
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
            ],
        ]);
    }

    /**
     * Set PageLimits for import.
     *
     * @return array
     */
    private function setPageLimits(): array
    {
        if ($this->config["articleLimit"] ?? false) {
            $pageLimit = $this->config["articleLimit"];
            $pageFrom = $this->config["pageFrom"] ?? self::PAGE_START;
            $pageTo = $pageFrom;
            $this->logger->info(
                "Article limit set to " .
                    $this->config["articleLimit"] .
                    "will be fetched"
            );
        } else {
            $pageLimit = $this->config["pageLimit"] ?? self::LIMIT;
            $pageFrom = $this->config["pageFrom"] ?? self::PAGE_START;
            $pageTo = $this->config["pageTo"] ?? self::PAGE_END;
            $this->logger->info(
                "No Article limit set to all articles will be fetched"
            );
        }

        return [$pageLimit, $pageFrom, $pageTo];
    }

    /**
     * Translations for Knowledge Categories.
     *
     * @param iterable $knowledgeCategories
     * @param bool $translate
     */
    private function translateKnowledgeCategories(
        iterable $knowledgeCategories,
        bool $translate
    ) {
        $dest = $this->getDestination();
        foreach ($knowledgeCategories as $knowledgeCategory) {
            if ($translate) {
                /** @var iterable $translation */
                $translation = $this->zendesk->getSectionTranslations(
                    $this->trimPrefix($knowledgeCategory["foreignID"])
                );
                $kbTranslations = $this->transform($translation, [
                    "recordID" => [
                        "placeholder" =>
                            $knowledgeCategory["knowledgeCategoryID"],
                    ],
                    "recordType" => ["placeholder" => "knowledgeCategory"],
                    "locale" => [
                        "column" => "locale",
                        "filter" => [$this, "getSourceLocale"],
                    ],
                    "propertyName" => ["placeholder" => "name"],
                    "translation" => ["column" => "title"],
                    "dateUpdated" => "updated_at",
                ]);
                $dest->importKnowledgeBaseTranslations($kbTranslations);
                $translation = new \ArrayObject($translation);

                $kbTranslations = $this->transform($translation, [
                    "recordID" => [
                        "placeholder" =>
                            $knowledgeCategory["knowledgeCategoryID"],
                    ],
                    "recordType" => ["placeholder" => "knowledgeBase"],
                    "locale" => [
                        "column" => "locale",
                        "filter" => [$this, "getSourceLocale"],
                    ],
                    "propertyName" => ["placeholder" => "description"],
                    "translation" => ["column" => "body"],
                    "skip" => [
                        "column" => "body",
                        "filter" => [$this, "nullTranslation"],
                    ],
                    "dateUpdated" => "updated_at",
                ]);
                $dest->importKnowledgeBaseTranslations($kbTranslations);
            }
        }
    }

    /**
     * Sync archived ZenDesk content with Vanilla.
     */
    public function syncUpArchivedZenDeskArticles()
    {
        $this->logger->info(
            "Delete mode enabled, all other import modes will not run during this process"
        );

        $locale = $this->config["sourceLocale"] ?? self::DEFAULT_SOURCE_LOCALE;

        // 1. Grab all Zendesk articles.
        $results = $this->zendesk->getArticlesWithPagination($locale);
        $zenDeskArticles = $this->isSyncAbleZendeskArticle($results);

        // 2. Get all the ZenDesk kb's
        $zenDeskKnowledgeBases = $this->zendesk->getCategoriesWithPagination(
            $locale
        );

        $destination = $this->getDestination();
        $count = [];
        $knowledgeBases = [];
        foreach ($zenDeskKnowledgeBases as $zenDeskKnowledgeBase) {
            $id = $zenDeskKnowledgeBase["id"] ?? "";
            $foreignID = $this->addPrefix($id);

            // 3. Grab the Vanilla kb's linked by the foreign id.
            try {
                $knowledgeBase = $destination->getKnowledgeBaseBySmartID(
                    $foreignID
                );
                $count[$foreignID] = $knowledgeBase["countArticles"] ?? 0;
                $knowledgeBases[] = $knowledgeBase;
            } catch (NotFoundException | HttpResponseException $ex) {
                $this->logger->error(
                    "Knowledge-base with foreign ID # $foreignID not found"
                );
            }
        }

        $articleCount = array_sum($count);

        // 4. Check if Vanilla's article count is greater than that of ZenDesk.

        if (count($zenDeskArticles) < $articleCount) {
            $destination->deleteArchivedArticles(
                $knowledgeBases,
                $zenDeskArticles,
                $this->config["foreignIDPrefix"]
            );
        } else {
            $this->logger->info("No articles to delete");
        }
    }

    /**
     * Grab all the pagination information from config
     *
     * @return array
     */
    private function getPaginationInformation(): array
    {
        $pageLimit = $this->config["pageLimit"] ?? self::LIMIT;
        $pageFrom = $this->config["pageFrom"] ?? self::PAGE_START;
        $pageTo = $this->config["pageTo"] ?? self::PAGE_END;

        return [$pageLimit, $pageFrom, $pageTo];
    }

    /**
     * Get all syncable ZenDesk articles.
     *
     * @param $results
     *
     * @return array
     */
    private function isSyncAbleZendeskArticle(&$results): array
    {
        foreach ($results as $key => &$article) {
            $draftStatus = $article["draft"] ?? false;
            $hasUserSegment = $article["user_segment_id"] ?? false;
            if ($draftStatus === true || $hasUserSegment) {
                unset($results[$key]);
            }
        }
        return $results;
    }
}
