<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpClient;
use Garden\Http\HttpHandlerInterface;

/**
 * The Zendesk API.
 */
class ZendeskClient extends HttpClient {
    /**
     * @var string
     */
    private $token;

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

    /**
     * Execute GET /help_center/$locale/categories.json request against zendesk api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getCategories(string $locale, array $query = []): iterable {
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $results = $this->get("/help_center/$locale/categories.json".$queryParams)->getBody();
        $zendeskCategories = [];

        if ($results) {
            $zendeskCategories = $results['categories'];
            $pageCount = $result['page_count'] ?? null;
            if ($pageCount > 1) {
                $count = 2;
                while ($count <= $pageCount) {
                    $results = $this->get("/help_center/$locale/categories.json?page={$count}")->getBody();
                    $zendeskCategories += $results['categories'] ?? null;
                    $count++;
                }
            }
        }

        foreach ($zendeskCategories as &$zendeskCategory) {
            $zendeskCategory["locale"] = "en";
            $zendeskCategory["viewType"] = "help";
            $zendeskCategory["sortArticles"] = "dateInsertedDesc";
            if ($zendeskCategory['description'] === '') {
                $zendeskCategory["description"] = $zendeskCategory['name'].' placeholder description';
            }
        }
        return $zendeskCategories;
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
        $results = $this->get("/help_center/$locale/sections.json".$queryParams)->getBody();

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
        $queryParams = empty($query) ? '' : '?'.http_build_query($query);
        $results = $this->get("/help_center/$locale/articles.json".$queryParams)->getBody();

        foreach ($results['articles'] as &$article) {
            $article['format'] = 'wysiwyg';
            $article['locale'] = 'en';
        }
        return $results['articles'] ?? [];
    }

    /**
     * @return string
     */
    public function getToken(): string {
        return $this->token;
    }
}
