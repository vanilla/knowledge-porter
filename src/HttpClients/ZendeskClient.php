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

    public function __construct(string $baseUrl = '',  HttpHandlerInterface $handler = null) {
        parent::__construct($baseUrl, $handler);
        $this->setThrowExceptions(true);
        $this->setDefaultHeader('Content-Type', 'application/json');
    }

    public function setToken(string $token) {
        $this->token = $token;
        $this->setDefaultHeader('Authorization', "Basic ".base64_encode($token));
    }

    /**
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getCategories(string $locale, array $query = []): iterable {
        $results = $this->get("/help_center/$locale/categories.json")->getBody();
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
            $zendeskCategory["description"] = ($zendeskCategory['description'] === '') ? $zendeskCategory['name'].' placeholder description' : $zendeskCategory['description'];
        }

        return $zendeskCategories;
    }

    /**
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
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getArticles(string $locale, array $query = []): array {
        $results = $this->get("/help_center/$locale/articles.json")->getBody();

        foreach ($results['articles'] as &$article) {
            $article['format'] = 'html';
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
