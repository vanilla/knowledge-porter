<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpClient;
use Garden\Http\HttpHandlerInterface;
use Garden\Http\HttpResponse;

/**
 * The Vanilla API.
 */
class VanillaClient extends HttpClient {
    /**
     * @var string
     */
    private $token;

    /**
     * VanillaClient constructor.
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
        $this->setDefaultHeader('Authorization', "Bearer $token");
    }
    /**
     * Execute GET /api/v2/knowledge-bases request against vanilla api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getKnowledgeBases(string $locale, array $query = []): array {
        $result = $this->get("/api/v2/knowledge-bases?locale={$locale}");
        $body = $result->getBody();
        return $body;
    }

    /**
     * Execute GET /api/v2/knowledge-categories request against vanilla api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getKnowledgeCategories(string $locale, array $query = []): array {
        $result = $this->get("/api/v2/knowledge-categories?locale={$locale}");
        $body = $result->getBody();
        return $body;
    }

    /**
     * GET /api/v2/knowledge-bases/{knowledgeCategoryID} using smartID.
     *
     * @param string $paramSmartID
     * @param array $query
     * @return array
     */
    public function getKnowledgeBaseBySmartID(string $paramSmartID, array $query = []): array {
        $result = $this->get("/api/v2/knowledge-bases/".rawurlencode('$foreignID:'.$paramSmartID));
        $body = $result->getBody();
        return $body;
    }

    /**
     * GET /api/v2/knowledge-categories/{knowledgeCategoryID} using smartID.
     *
     * @param string $paramSmartID
     * @param array $query
     * @return array
     */
    public function getKnowledgeCategoryBySmartID(string $paramSmartID, array $query = []): array {
        $result = $this->get("/api/v2/knowledge-categories/".rawurlencode('$foreignID:'.$paramSmartID));
        $body = $result->getBody();
        return $body;
    }

    /**
     *  GET /api/v2/knowledge-articles/{articleID} using vanilla api.
     *
     * @param string $paramSmartID
     * @param array $query
     * @return array
     */
    public function getKnowledgeArticleBySmartID(string $paramSmartID, array $query = []): array {
        $result = $this->get("/api/v2/articles/".rawurlencode('$foreignID:'.$paramSmartID).'/edit');
        $body = $result->getBody();
        return $body;
    }

    /**
     * @return string
     */
    public function getToken(): string {
        return $this->token;
    }

    /**
     * Exceptions and error handling for some specific cases.
     *
     * @param HttpResponse $response
     * @param array $options
     * @throws NotFoundException Throw not found exception when 404 status received.
     */
    public function handleErrorResponse(HttpResponse $response, $options = []) {
        if ($response->getStatusCode() === 404 && $this->getThrowExceptions()) {
            throw new NotFoundException($response, $response['message'] ?? '');
        } else {
            parent::handleErrorResponse($response, $options);
        }
    }
}
