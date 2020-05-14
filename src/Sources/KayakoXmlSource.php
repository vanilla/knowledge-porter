<?php
/**
 * @author Alexander Kim <alexander.k@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use DOMDocument;
use DOMNode;
use Garden\Schema\Schema;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\Destinations\VanillaDestination;
use Vanilla\KnowledgePorter\Adapters\KayakoXmlAdapter;

/**
 * Class KayakoXmlSource
 * @package Vanilla\KnowledgePorter\Sources
 */
class KayakoXmlSource extends AbstractSource {
    const DEFAULT_SOURCE_LOCALE = 'en';
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

        if ($this->config['import']['users'] ?? true) {
            $this->processUsers();
        }

        if ($this->config['import']['articles'] ?? true) {
            $this->processKnowledgeArticles();
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
            'generateRootCategoryForeignID' => ['placeholder' => 'true'],
        ]);
        $dest = $this->getDestination();
        foreach ($dest->importKnowledgeBases($kbs) as $kb) {
            $this->logger->info('Knowledge base "'.$kb['name'].'" imported successfully');
        }
    }

    /**
     * Process: import users.xml, POST/PATCH vanilla users
     */
    private function processUsers() {
        $usersFrom = $this->kayakoXml->getUsers();

        $users = $this->transform($usersFrom, [
            'name' => 'username',
            'title' => 'fullname',
            'email' => 'email',
            'verified' => 'isenabled',
            'password' => ['placeholder' => $this->config["foreignIDPrefix"] . 'password'],
            'emailConfirmed' => ['placeholder' => true],
            'bypassSpam' => ['placeholder' => true],
            'roleID' => ['placeholder' => [8]],
        ]);

        $dest = $this->getDestination();
        foreach ($dest->importUsers($users) as $user) {
            $this->logger->info('User "'.$user['name'].'" imported successfully');
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
     * Process: import articles.xml, POST/PATCH vanilla knowledge base articles
     */
    private function processKnowledgeArticles() {
        $locale = $this->config['sourceLocale'] ?? self::DEFAULT_SOURCE_LOCALE;

        $articles = $this->kayakoXml->getArticles();
        $knowledgeArticles = $this->transform($articles, [
            'foreignID' => ["column" => 'kbarticleid', "filter" => [$this, "addPrefix"]],
            'userData' => ['column' => 'editedstaffid', 'filter' => [$this, 'getUserData']],
            'knowledgeCategoryID' => ["column" => 'categoryid', "filter" => [$this, "calculateCategoryID"]],
            'format' => ['placeholder' => 'wysiwyg'],
            'locale' => ['placeholder' => $locale],
            'name' => 'subject',
            'body' => ['column' => 'contents', 'filter' => [$this, 'prepareBody']],
            'featured' => ['column' => 'isfeatured'],
            'alias' => ['column' => 'kayakoArticleID', 'filter' => [$this, 'setAlias']],
            'dateUpdated' => ['column' => 'editeddateline', 'filter' => [$this, 'dateFormat']],
            'dateInserted' => ['column' => 'dateline', 'filter' => [$this, 'dateFormat']],
        ]);
        $dest = $this->getDestination();
        $kbArticles = $dest->importKnowledgeArticles($knowledgeArticles);
        $i = 0;
        foreach ($kbArticles as $article) {
            $i++;
        }
    }

    /**
     * Set Alias for Kayako Article.
     *
     * @param mixed $id
     * @return string
     * @todo Make sure to prefix with the prefix like: `<prefix>/<path>`. Hint: `parse_url()`.
     */
    protected function setAlias($articleID): string {
        $prefix = ($this->config["foreignIDPrefix"] !== '') ?  '/'.$this->config["foreignIDPrefix"] : '';

        $basePath = "$prefix/index.php%3F/Knowledgebase/Article/View/$articleID";
        return $basePath;
    }

    /**
     * @param $userID
     * @return array
     */
    protected function getUserData($userID): array {
        $data = [];
        if ($this->config['import']['authors'] ?? false) {
            if (!empty($userID)) {
                $data = $this->kayakoXml->getUser($userID);
            }
        }
        return $data;
    }

    /**
     * Prepeare article body: parse uerls and parse attachments if neeeded
     *
     * @param string $body
     * @param array $row
     * @return string
     */
    protected function prepareBody(string $body, array $row): string {
        $body = $this->parseUrls($body);
        if ($this->config['import']['attachments'] ?? false) {
            $body = $this->addAttachments($body, $row);
        }
        return $body;
    }

    /**
     * Parse urls from a string.
     *
     * @param string $body
     * @return string
     */
    protected function parseUrls(string $body = ''): string {
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
     * Add html elements with attachment links
     *
     * @param string $body
     * @param array $article
     * @return string
     */
    public function addAttachments(string $body, array $article): string {
        $attachments = $this->kayakoXml->getArticleAttachments($article);

        $dest = $this->getDestination();
        foreach ($attachments as $attachment) {
            $media = $dest->getOrCreateMedia($attachment);
            $this->kayakoXml->saveAttachmentMedia('attachments/'.$attachment['kbarticleid'].'/'.$attachment['id'].'.json', json_encode($media), true);

            $url = htmlspecialchars($media['url']);
            $name = htmlspecialchars($media['name']);
            $body .= '<p><a href="'.$url.'" download>'.$name.'</a></p>';
        }

        return $body;
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
     * Prepare article category ID.
     *
     * @param mixed $str
     * @return string
     */
    protected function calculateCategoryID($str): string {
        if (!empty($str)) {
            $newStr = $this->config["foreignIDPrefix"] . $str;
        } else {
            $newStr = $this->config["foreignIDPrefix"] . '1-root';
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
                    "users" => [
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
