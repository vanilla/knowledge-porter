<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpClient;

/**
 * The Zendesk API.
 */
class ZendeskClient extends HttpClient {
    /**
     * @var string
     */
    private $token;

    public function __construct(string $baseUrl, string $token = '') {
        parent::__construct($baseUrl);
        $this->setToken($token);
        $this->setThrowExceptions(true);
    }

    public function setToken(string $token) {
        $this->token = $token;
        $this->setDefaultHeader('Authorization', "Bearer $token");
    }

    public function getCategories(string $locale, array $query): array {
        $result = $this->get("/api/v2/help_center/$locale/categories.json");
        return $result->getBody();
    }

    /**
     * @return string
     */
    public function getToken(): string {
        return $this->token;
    }
}
