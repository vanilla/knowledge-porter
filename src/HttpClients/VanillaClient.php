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
use Garden\Http\HttpResponseException;
use Vanilla\KnowledgePorter\Utils\ApiPaginationIterator;

/**
 * The Vanilla API.
 */
class VanillaClient extends HttpClient
{
    const DELETED_STATUS = "deleted";
    /**
     * @var string
     */
    private $token;

    /** @var array */
    private $categoryCacheByID;

    /**
     * VanillaClient constructor.
     *
     * @param string $baseUrl
     * @param HttpHandlerInterface|null $handler
     */
    public function __construct(
        string $baseUrl = "",
        HttpHandlerInterface $handler = null
    ) {
        parent::__construct($baseUrl, $handler);
        $this->setThrowExceptions(true);
        $this->setDefaultHeader("Content-Type", "application/json");
    }

    /**
     * Set api access token
     *
     * @param string $token
     */
    public function setToken(string $token)
    {
        $this->token = $token;
        $this->setDefaultHeader("Authorization", "Bearer $token");
    }

    /**
     * Execute GET /api/v2/knowledge-bases request against vanilla api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getKnowledgeBases(string $locale, array $query = []): array
    {
        $result = $this->get(
            "/api/v2/knowledge-bases?locale={$locale}",
            $query
        );
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
    public function getKnowledgeCategories(
        string $locale,
        array $query = []
    ): array {
        $result = $this->get(
            "/api/v2/knowledge-categories?locale={$locale}",
            $query
        );
        $body = $result->getBody();
        return $body;
    }

    /**
     * Execute GET /api/v2/articles request against vanilla api.
     *
     * @param string $locale
     * @param array $query
     * @return array
     */
    public function getArticles(string $locale, array $query = []): array
    {
        $result = $this->get("/api/v2/articles?locale={$locale}", $query);
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
    public function getKnowledgeBaseBySmartID(
        string $paramSmartID,
        array $query = []
    ): array {
        $result = $this->get(
            "/api/v2/knowledge-bases/" .
                rawurlencode('$foreignID:' . $paramSmartID)
        );
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
    public function getKnowledgeCategoryBySmartID(
        string $paramSmartID,
        array $query = []
    ): array {
        $existing = $this->categoryCacheByID[$paramSmartID] ?? null;
        if ($existing) {
            return $existing;
        }
        $result = $this->get(
            "/api/v2/knowledge-categories/" .
                rawurlencode('$foreignID:' . $paramSmartID)
        );
        $body = $result->getBody();
        $this->categoryCacheByID[$paramSmartID] = $body;
        return $body;
    }

    /**
     * GET /api/v2/knowledge-categories/{knowledgeCategoryID} using smartID.
     *
     * @param array $query
     * @return array
     */
    public function getKnowledgeBaseTranslation(array $query = []): array
    {
        $query["validateLocale"] = $query["validateLocale"] ?? false;
        $result = $this->get("/api/v2/translations/kb", $query);
        $body = $result->getBody();
        if (count($body) === 1) {
            $body = $body[0];
        }
        return $body;
    }

    /**
     *  GET /api/v2/knowledge-articles/{articleID} using vanilla api.
     *
     * @param string $paramSmartID
     * @param array $query
     * @return array
     */
    public function getKnowledgeArticleBySmartID(
        string $paramSmartID,
        array $query = []
    ): array {
        $result = $this->get(
            "/api/v2/articles/" .
                rawurlencode('$foreignID:' . $paramSmartID) .
                "/edit"
        );
        $body = $result->getBody();
        return $body;
    }

    /**
     * Get knowledge categories filtered by knowledge-id.
     *
     * @param array $ids
     * @return array
     */
    public function getKnowledgeCategoriesByKnowledgeBaseID(array $ids): array
    {
        $url =
            "/api/v2/knowledge-categories?" .
            http_build_query(["knowledgeBaseIDs" => $ids]);

        /** @var ApiPaginationIterator $iterator */
        $iterator = new ApiPaginationIterator($this, $url);
        $results = [];
        foreach ($iterator as $item) {
            foreach ($item as $i) {
                $results[] = $i;
            }
        }
        return $results;
    }

    /**
     * Get articles by their knowledge category id.
     *
     * @param int $id
     * @return array
     */
    public function getKnowledgeArticlesByKnowledgeCategoryID(int $id): array
    {
        $url =
            "/api/v2/articles?" .
            http_build_query(["knowledgeCategoryID" => $id]);

        /** @var ApiPaginationIterator $iterator */
        $iterator = new ApiPaginationIterator($this, $url);
        $results = [];
        foreach ($iterator as $item) {
            foreach ($item as $i) {
                $results[] = $i;
            }
        }
        return $results;
    }

    /**
     * Update an Article's status.
     *
     * @param int $id
     * @return array
     */
    public function updateKnowledgeArticleStatus(int $id): array
    {
        $result = $this->patch("/api/v2/articles/$id/status", [
            "status" => self::DELETED_STATUS,
        ]);
        $body = $result->getBody();
        return $body;
    }

    /**
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Exceptions and error handling for some specific cases.
     *
     * @param HttpResponse $response
     * @param array $options
     * @throws NotFoundException Throw not found exception when 404 status received.
     * @throws HttpResponseException On error
     */
    public function handleErrorResponse(HttpResponse $response, $options = [])
    {
        if (
            $response->getStatusCode() === 404 &&
            ($options["throw"] ?? $this->throwExceptions)
        ) {
            throw new NotFoundException($response, $response["message"] ?? "");
        } elseif (
            is_array($response->getBody()) &&
            !empty($response->getBody()["errors"])
        ) {
            $message = $this->makeValidationMessage(
                $response->getBody()["errors"]
            );
            if (!empty($message)) {
                throw new HttpResponseException($response, $message);
            }
        } elseif (
            $response->getStatusCode() >= 500 &&
            ($options["throw"] ?? $this->throwExceptions)
        ) {
            throw new HttpResponseException($response, $response->getRawBody());
        } else {
            parent::handleErrorResponse($response, $options);
        }
    }

    /**
     * Make a single error message out of a list of validation error messages.
     *
     * @param array $errors The list of validation errors.
     * @return string Returns the final error message.
     */
    private function makeValidationMessage($errors): string
    {
        if (!is_array($errors)) {
            return "";
        }
        $fieldErrors = [];
        foreach ($errors as $error) {
            $fieldErrors[$error["field"]][] = $error["message"];
        }
        $result = [];
        foreach ($fieldErrors as $field => $errors) {
            $result[] = $field . ": " . implode(", ", $errors);
        }
        $message = implode("; ", $result);
        return $message;
    }
}
