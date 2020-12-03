<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpClient;
use Garden\Http\HttpHandlerInterface;
use Vanilla\KnowledgePorter\Utils\ApiPaginationIterator;

/**
 * The Zendesk API.
 */
class ZendeskClient extends HttpClient {
    /**
     * @var string
     */
    private $token;

    /**
     * @var resource
     */
    private $streamContext;

    /**
     * ZendeskClient constructor.
     *
     * @param string $baseUrl
     * @param HttpHandlerInterface|null $handler
     */
    public function __construct(string $baseUrl = '', HttpHandlerInterface $handler = null) {
        parent::__construct($baseUrl, $handler);
        $this->setThrowExceptions(true);
        $this->setDefaultHeader('Content-Type', 'application/json');
    }

    /**
     * Set api access token
     *
     * @param string $token
     */
    public function setToken(string $token) {
        $this->token = $token;
        $this->setDefaultHeader('Authorization', "Basic ".base64_encode($token));
    }

    public function setStreamContext(string $token) {
        $this->streamContext = stream_context_create([
            "http" => [
                "header" => "Authorization: Basic ".base64_encode($token),
                "protocol_version" => 1.1,
            ]
        ]);
    }

    /**
     * Execute GET /help_center/$locale/categories.json request against zendesk api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getCategories(string $locale, array $query = []): iterable {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $results = $this->get("/api/v2/help_center/$locale/categories.json".$queryParams)->getBody();
        $zendeskCategories = $results['categories'] ?? null;

        foreach ($zendeskCategories as &$zendeskCategory) {
            $zendeskCategory["viewType"] = "help";
            $zendeskCategory["sortArticles"] = "dateInsertedDesc";
            if ($zendeskCategory['description'] === '') {
                $zendeskCategory["description"] = $zendeskCategory['name'].' placeholder description';
            }
        }
        return $zendeskCategories;
    }

    /**
     * Execute GET /help_center/categories/{id}/translations.json request against zendesk api.
     *
     * @param string|int $categoryID
     * @return array
     */
    public function getCategoryTranslations($categoryID): iterable {
        $results = $this->get('/api/v2/help_center/categories/'.$categoryID.'/translations.json')->getBody();
        $zendeskCategoryTranslations = $results['translations'] ?? null;
        return $zendeskCategoryTranslations;
    }

    /**
     * Execute GET /help_center/sections/{id}/translations.json request against zendesk api.
     *
     * @param string|int $sectionID
     * @return array
     */
    public function getSectionTranslations($sectionID): iterable {
        $results = $this->get('/api/v2/help_center/sections/'.$sectionID.'/translations.json')->getBody();
        $zendeskSectionTranslations = $results['translations'] ?? null;
        return $zendeskSectionTranslations;
    }

    /**
     * Execute GET /help_center/articles/{id}/translations.json request against zendesk api.
     *
     * @param string|int $articleID
     * @return array
     */
    public function getArticleTranslations($articleID): iterable {
        $results = $this->get('/api/v2/help_center/articles/'.$articleID.'/translations.json')->getBody();
        $zendeskArticleTranslations = $results['translations'] ?? null;
        return $zendeskArticleTranslations;
    }

    /**
     * Execute GET /help_center/articles/{id}/attachments.json request against zendesk api.
     *
     * @param string|int $articleID
     * @return array
     */
    public function getArticleAttachments($articleID): iterable {
        $results = $this->get('/api/v2/help_center/articles/'.$articleID.'/attachments.json')->getBody();
        $zendeskArticleAttachments = $results['article_attachments'] ?? null;
        return $zendeskArticleAttachments;
    }

    /**
     * Execute GET /users/{id}.json request against zendesk api.
     *
     * @param string|int $userID
     * @return array
     */
    public function getUser($userID): array {
        $results = $this->get('/api/v2/users/'.$userID.'.json')->getBody();
        $zendeskUser = $results['user'] ?? [];
        return $zendeskUser;
    }

    /**
     * Execute GET /help_center/{locale}/articles/{id}/votes.json request against zendesk api.
     *
     * @param string|int $articleID
     *  @param string $locale
     * @return array
     */
    public function getArticleVotes($articleID, string $locale): iterable {
        $results = $this->get('/api/v2/help_center/'.$locale.'/articles/'.$articleID.'/votes.json')->getBody();
        $zendeskArticleVotes = $results['votes'] ?? null;
        return $zendeskArticleVotes;
    }

    /**
     * Execute GET /help_center/$locale/sections.json request against zendesk api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getSections(string $locale, array $query = []): array {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $results = $this->get("/api/v2/help_center/$locale/sections.json".$queryParams)->getBody();

        return $results['sections'] ?? [];
    }

    /**
     * Execute GET /help_center/$locale/articles.json request against zendesk api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getArticles(string $locale, array $query = []): iterable {
        $uri = "/api/v2/help_center/$locale/articles.json";

        if ($query['start_time'] ?? false) {
            $uri = "/api/v2/help_center/incremental/articles.json";
        }

        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $results = $this->get($uri.$queryParams)->getBody();

        foreach ($results['articles'] as &$article) {
            if (($article['locale'] ?? null) !== $locale) {
                // ensure that articles are created in the source-locale first.
                $article['locale'] = $locale;
            }
            $article['format'] = 'wysiwyg';
            $article['body'] = $article['body'] ?? 'content-place-holder';
        }
        return $results['articles'] ?? [];
    }

    /**
     * Get ZenDesk articles with pagination.
     *
     * @param string $locale
     * @param array $query
     * @return iterable
     */
    public function getArticlesWithPagination(string $locale, array $query = []): iterable {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $uri = "/api/v2/help_center/$locale/articles.json".$queryParams;

        /** @var ApiPaginationIterator $iterator */
        $iterator = new ApiPaginationIterator($this, $uri.$queryParams);
        $results = [];
        foreach ($iterator as $item) {
            $articles = $item['articles'] ?? [];
            foreach ($articles as $article) {
                $results[] = $article;
            }
        }

        return $results ?? [];
    }

    /**
     * Get ZenDesk categories with pagination.
     *
     * @param string $locale
     * @param array $query
     * @return iterable
     */
    public function getCategoriesWithPagination(string $locale, array $query = []): iterable {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $uri = "/api/v2/help_center/$locale/categories.json".$queryParams;

        /** @var ApiPaginationIterator $iterator */
        $iterator = new ApiPaginationIterator($this, $uri);
        $results = [];
        foreach ($iterator as $item) {
            $categories = $item['categories'] ?? [];
            foreach ($categories as $category) {
                $results[] = $category;
            }
        }

        return $results ?? [];
    }

    /**
     * @return string
     */
    public function getToken(): string {
        return $this->token;
    }

    /**
     * @return mixed
     */
    public function getStreamContext() {
        return $this->streamContext;
    }
}
