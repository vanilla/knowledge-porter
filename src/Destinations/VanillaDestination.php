<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Exception;
use Garden\Cli\TaskLogger;
use Garden\Http\HttpResponse;
use Garden\Http\HttpResponseException;
use Garden\Schema\Schema;
use Garden\Schema\ValidationException;
use Psr\Container\ContainerInterface;
use Psr\Log\LogLevel;
use Vanilla\KnowledgePorter\HttpClients\HttpCacheMiddleware;
use Vanilla\KnowledgePorter\HttpClients\HttpLogMiddleware;
use Vanilla\KnowledgePorter\HttpClients\HttpVanillaCloudRateLimitBypassMiddleware;
use Vanilla\KnowledgePorter\HttpClients\NotFoundException;
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;
use Vanilla\KnowledgePorter\Utils\ApiPaginationIterator;

/**
 * Class VanillaDestination
 *
 * @package Vanilla\KnowledgePorter\Destinations
 */
class VanillaDestination extends AbstractDestination
{
    const UPDATE_MODE_ALWAYS = "always";
    const UPDATE_MODE_ON_CHANGE = "onChange";
    const UPDATE_MODE_ON_DATE = "onDate";

    const DATE_UPDATED = "dateUpdated";

    const ARTICLE_EDIT_FIELDS = [
        ["knowledgeCategoryID", "resolveKnowledgeCategoryID"],
        "name",
        "body",
        "featured",
        "dateUpdated",
        "dateInserted",
        "format",
    ];

    const KB_EDIT_FIELDS = [
        "name",
        "description",
        "urlCode",
        "sourceLocale",
        "viewType",
        "sortArticles",
    ];

    const KB_TRANSLATION_FIELDS = ["translation"];

    const ARTICLE_TRANSLATION_FIELDS = ["name", "body", "format"];

    const KB_CATEGORY_EDIT_FIELDS = [
        "name",
        ["knowledgeBaseID", "resolveKnowledgeBaseID"],
        ["parentID", "resolveKnowledgeCategoryID"],
    ];

    /** @var array */
    private static $kbcats = [];

    /** @var int $count */
    private static $count;

    /**
     * @var VanillaClient
     */
    private $vanillaApi;

    /** @var ContainerInterface $container */
    protected $container;

    /**
     * VanillaDestination constructor.
     *
     * @param VanillaClient $vanillaApi
     * @param ContainerInterface $container
     */
    public function __construct(
        VanillaClient $vanillaApi,
        ContainerInterface $container
    ) {
        $this->vanillaApi = $vanillaApi;
        $this->container = $container;
    }

    /**
     * @param iterable $rows
     * @return iterable
     */
    public function importKnowledgeBases(iterable $rows): iterable
    {
        foreach ($rows as $row) {
            if (($row["skip"] ?? "") === "true") {
                continue;
            }
            try {
                $existing = $this->vanillaApi->getKnowledgeBaseBySmartID(
                    $row["foreignID"]
                );
                if ($this->config["patchKnowledgeBase"] ?? false) {
                    $patch = $this->updateFields(
                        $existing,
                        $row,
                        self::KB_EDIT_FIELDS,
                        "KB - " . $existing["knowledgeBaseID"]
                    );
                    if (!empty($patch)) {
                        $kb = $this->vanillaApi
                            ->patch(
                                "/api/v2/knowledge-bases/" .
                                    $existing["knowledgeBaseID"],
                                $patch
                            )
                            ->getBody();
                    }
                }
            } catch (HttpResponseException $ex) {
                if ($ex->getCode() === 409) {
                    $this->logger->warning(
                        $ex->getMessage() . " failed to import knowledge-base"
                    );
                    continue;
                }
                $kb = $this->vanillaApi
                    ->post("/api/v2/knowledge-bases", $row)
                    ->getBody();
            }
            $kb = $kb ?? $existing;
            if (($row["generateRootCategoryForeignID"] ?? "false") === "true") {
                $kbCat = $this->vanillaApi
                    ->patch(
                        "/api/v2/knowledge-categories/" .
                            $kb["rootCategoryID"] .
                            "/root",
                        ["foreignID" => $row["foreignID"] . "-root"]
                    )
                    ->getBody();
            }
            yield $kb;
        }
    }

    /**
     * POST media with file body attached as a multipart form data.
     *
     * @param array $attachment
     * @return array
     */
    public function postMedia(array $attachment): array
    {
        // form field separator
        $delimiter = "-------------" . uniqid();
        $data = "";

        $data .= "--" . $delimiter . "\r\n";
        $data .=
            'Content-Disposition: form-data; name="file";' .
            ' filename="' .
            $attachment["filename"] .
            '"' .
            "\r\n";
        $data .= "Content-Type: " . $attachment["filetype"] . "\r\n";
        $data .= "\r\n";
        $data .= base64_decode($attachment["contents"]) . "\r\n";
        $data .= "--" . $delimiter . "--\r\n";

        $headers = [
            "Content-Type" => "multipart/form-data; boundary=" . $delimiter,
        ];

        $media = $this->vanillaApi
            ->post("/api/v2/media", $data, $headers)
            ->getBody();

        return $media;
    }

    /**
     * @param array $attachment
     * @return array
     */
    public function getOrCreateMedia(array $attachment): array
    {
        $media = [];
        if (!empty($attachment["content_url"])) {
            try {
                $response = $this->vanillaApi->get("/api/v2/media/by-url", [
                    "url" => $attachment["content_url"],
                ]);
                if ($response->getStatus() === "200 OK") {
                    $media = $response->getBody();
                }
            } catch (NotFoundException $e) {
            }
        }
        if (empty($media)) {
            $media = $this->postMedia($attachment);
        }
        return $media;
    }

    /**
     * @param iterable $rows
     */
    public function importKnowledgeBaseTranslations(iterable $rows)
    {
        foreach ($rows as $row) {
            if (($row["skip"] ?? "false") === "true") {
                continue;
            }
            $lookup = $row;
            $lookup["recordIDs"] = [$row["recordID"]];
            $existing = $this->vanillaApi->getKnowledgeBaseTranslation($lookup);
            $patch = $this->updateFields(
                $existing,
                $row,
                self::KB_TRANSLATION_FIELDS
            );
            if (!empty($patch)) {
                // $row contains all fields needed for translation api
                // $patch has only 'translation' field if
                // we use $patch as trigger, but $row as a body for translation
                try {
                    $res = $this->vanillaApi->patch("/api/v2/translations/kb", [
                        $row,
                    ]);
                } catch (HttpResponseException $ex) {
                    $this->logger->info($ex->getMessage());
                    continue;
                }
            }
        }
    }

    /**
     * Import Knowledge Articles.
     *
     * @param int $articleID The article ID to import the translations for.
     * @param iterable $rows The translated records to insert.
     */
    public function importArticleTranslations(
        int $articleID,
        iterable $rows
    ): void {
        try {
            $this->logger->beginInfo(
                "Importing article translations for Article $articleID"
            );
            [
                $added,
                $updated,
                $skipped,
                $errors,
            ] = $this->importArticleTranslationsInternal($articleID, $rows);
            $this->logger->end(
                "Article Translations - Done (added: {added}, updated: {updated}, skipped: {skipped}, errors: {errors})",
                [
                    "added" => $added,
                    "updated" => $updated,
                    "skipped" => $skipped,
                    "errors" => $errors,
                ]
            );
        } catch (\Exception $ex) {
            $this->logger->endError($ex->getMessage());
        }
    }

    /**
     * @param int $articleID The article ID to import the translations for.
     * @param iterable $rows The translated records to insert.
     *
     * @return array [$added, $updated, $skipped]
     */
    private function importArticleTranslationsInternal(
        int $articleID,
        iterable $rows
    ): array {
        $added = $updated = $skipped = $errors = 0;
        $existingTranslations = $this->vanillaApi
            ->get("/api/v2/articles/$articleID/translations", [])
            ->getBody();
        // Check our source locale so we don't insert it again.
        $sourceLocale = $existingTranslations[0]["sourceLocale"] ?? null;
        if (!$sourceLocale) {
            return [$added, $updated, $skipped, count($rows)];
        }

        $existingTranslationsByLocale = array_column(
            $existingTranslations,
            null,
            "locale"
        );
        foreach ($rows as $row) {
            if (($row["skip"] ?? "false") === "true") {
                $skipped++;
                continue;
            }
            if ($row["locale"] === $sourceLocale) {
                $skipped++;
                continue;
            }

            $existingTranslation =
                $existingTranslationsByLocale[$row["locale"]] ?? null;
            if ($existingTranslation === null) {
                // Try to fetch it from the API.
                try {
                    $existingTranslation = $this->vanillaApi
                        ->get("/api/v2/articles/" . $articleID, [
                            "only-translated" => true,
                            "locale" => $row["locale"],
                        ])
                        ->getBody();
                } catch (NotFoundException $e) {
                    $this->logger->warning(
                        'Tried to check for an existing translation, but it wasn\'t found. Proceeding to import translation.'
                    );
                    $existingTranslation = [];
                }
            }

            if (
                ($existingTranslation["translationStatus"] ?? null) ===
                    "not-translated" ||
                !is_array($existingTranslation)
            ) {
                $existingTranslation = [];
            }

            $patch = $this->updateFields(
                $existingTranslation,
                $row,
                self::ARTICLE_TRANSLATION_FIELDS,
                "Article - $articleID - " . $row["locale"]
            );
            if (empty($patch)) {
                $skipped++;
                continue;
            }

            // $row contains all fields needed for translation api
            // $patch has only 'translation' field if
            // we use $patch as trigger, but $row as a body for translation
            if (!empty($row["userData"])) {
                $user = $this->getOrCreateUser($row["userData"]);
                $row["updateUserID"] = $user["userID"];
                unset($row["userData"]);
            }
            $row["validateLocale"] = false;
            $row["fileRehosting"] = [
                "enabled" => true,
                "requestHeaders" => $this->rehostHeaders,
            ];

            $res = $this->vanillaApi->patch(
                "/api/v2/articles/" . $row["articleID"],
                $row
            );
            $this->logRehostHeaders($res);

            if (!empty($existingTranslation)) {
                $updated++;
            } else {
                $added++;
            }
        }
        return [$added, $updated, $skipped, $errors];
    }

    /**
     * @param iterable $rows
     */
    public function importArticleVotes(iterable $rows)
    {
        foreach ($rows as $row) {
            if (empty($row["userData"])) {
                $row["insertUserID"] = -1;
            } else {
                $user = $this->getOrCreateUser($row["userData"]);
                $row["insertUserID"] = $user["userID"];
                unset($row["userData"]);
            }

            try {
                $res = $this->vanillaApi->put(
                    "/api/v2/articles/" . $row["articleID"] . "/react",
                    $row
                );
            } catch (HttpResponseException $ex) {
                $this->logger->info($ex->getMessage());
            }
        }
    }

    /**
     * @param array $userData
     * @param bool $update Update user if exists already
     * @return array
     */
    private function getOrCreateUser(
        array $userData,
        bool $update = false
    ): array {
        try {
            $user = $this->vanillaApi
                ->get('/api/v2/users/$email:' . $userData["email"])
                ->getBody();
            if ($update) {
                $user = $this->vanillaApi
                    ->patch("/api/v2/users/" . $user["userID"], $userData)
                    ->getBody();
            }
        } catch (NotFoundException $ex) {
            if (!$this->config["syncUserByEmailOnly"]) {
                try {
                    $user = $this->vanillaApi
                        ->get('/api/v2/users/$name:' . $userData["name"])
                        ->getBody();
                } catch (NotFoundException $ex) {
                    $user = $this->vanillaApi
                        ->post("/api/v2/users/", $userData)
                        ->getBody();
                }
            } else {
                $user = $this->vanillaApi
                    ->post("/api/v2/users/", $userData)
                    ->getBody();
            }
        }
        return $user;
    }

    /**
     * Import Knowledge Categories.
     *
     * @param iterable $rows An iterator of articles to import.
     * @return iterable
     */
    public function importKnowledgeCategories(iterable $rows): iterable
    {
        try {
            $this->logger->beginInfo("Importing knowledge categories");
            return $this->importKnowledgeCategoriesInternal($rows);
        } catch (\Exception $ex) {
            $this->logger->endError($ex->getMessage());
        }
    }

    /**
     * Import Knowledge Categories.
     *
     * @param iterable $rows
     * @param boolean $retry
     * @return iterable
     */
    public function importKnowledgeCategoriesInternal(
        iterable $rows,
        bool $retry = false
    ): iterable {
        $added = $updated = $skipped = $failures = 0;
        foreach ($rows as $row) {
            if (($row["skip"] ?? "") === "true") {
                continue;
            }

            if (($row["rootCategory"] ?? "false") === "true") {
                $result = $this->vanillaApi->get(
                    "/api/v2/knowledge-bases/" .
                        rawurlencode($row["knowledgeBaseID"])
                );
                $kb = $result->getBody();
                $kbCat = $this->vanillaApi
                    ->patch(
                        "/api/v2/knowledge-categories/" .
                            $kb["rootCategoryID"] .
                            "/root",
                        ["foreignID" => $row["foreignID"]]
                    )
                    ->getBody();
                $updated++;
            } else {
                if (($row["parentID"] ?? "") === "null") {
                    try {
                        $result = $this->vanillaApi->get(
                            "/api/v2/knowledge-bases/" .
                                rawurlencode($row["knowledgeBaseID"])
                        );
                        $kb = $result->getBody();
                        $row["parentID"] = $kb["rootCategoryID"];
                    } catch (HttpResponseException $exception) {
                        $row["failed"] = true;
                        self::$kbcats[] = $row;
                        $failures++;
                        continue;
                    }
                }
                try {
                    $existing = $this->vanillaApi->getKnowledgeCategoryBySmartID(
                        $row["foreignID"]
                    );
                    $patch = $this->updateFields(
                        $existing,
                        $row,
                        self::KB_CATEGORY_EDIT_FIELDS,
                        "knowledgeCategory - " .
                            $existing["knowledgeCategoryID"]
                    );
                    $updated++;
                    if (!empty($patch)) {
                        $kbCat = $this->vanillaApi
                            ->patch(
                                "/api/v2/knowledge-categories/" .
                                    $existing["knowledgeCategoryID"],
                                $patch
                            )
                            ->getBody();
                    }
                } catch (HttpResponseException $ex) {
                    if ($ex->getCode() === 500 || $ex->getCode() === 409) {
                        $row["failed"] = true;
                        self::$kbcats[] = $row;
                        $failures++;
                        $this->logger->warning(
                            $ex->getMessage() .
                                " failed to import knowledge-category."
                        );
                        continue;
                    } else {
                        try {
                            // Try to create the missing knowledgeCategory.
                            $kbCat = $this->vanillaApi
                                ->post("/api/v2/knowledge-categories", $row)
                                ->getBody();
                            $added++;
                        } catch (HttpResponseException $ex) {
                            if ($ex->getCode() === 404) {
                                $row["failed"] = true;
                                self::$kbcats[] = $row;
                                $failures++;
                                continue;
                            }
                        }
                    }
                }
            }
            yield $kbCat ?? $existing;
        }
        if (!$retry) {
            $this->logger->end(
                "Done (added: {added}, updated: {updated}, skipped: {skipped}, failed: {failures})",
                [
                    "added" => $added,
                    "updated" => $updated,
                    "skipped" => $skipped,
                    "failures" => $failures,
                ]
            );
        }
    }

    /**
     * Try to reprocess failed knowledge categories that failed to import.
     *
     * @return iterable
     */
    public function processFailedImportedKnowledgeCategories(): iterable
    {
        $initialCount = count(self::$kbcats);
        if ($initialCount > 0) {
            $retryLimit = $this->config["retryLimit"] ?? 1;
            $count = $initialCount;
            for ($i = 0; $i <= $retryLimit; $i++) {
                $retry = false;
                $this->logger->beginInfo(
                    "Retry importing knowledge categories"
                );
                $originalFailedKBCategories = new \ArrayObject(self::$kbcats);
                self::$kbcats = [];
                $kbCategories = $this->importKnowledgeCategoriesInternal(
                    $originalFailedKBCategories,
                    true
                );
                foreach ($kbCategories as $kbCategory) {
                    $count--;
                    yield $kbCategory;
                }

                if ($count === 0) {
                    $retry = false;
                } elseif ($count < $initialCount) {
                    $retry = true;
                } elseif ($count === $initialCount) {
                    $retry = false;
                }

                if (!$retry && count(self::$kbcats) > 0) {
                    $this->logger->info(
                        "Error importing to " .
                            count(self::$kbcats) .
                            " categories"
                    );
                    die();
                }

                $this->logger->end(
                    "Done(successful:{successful}, failed:{failed})",
                    [
                        "successful" => $initialCount - self::$count,
                        "failed" => count(self::$kbcats),
                    ]
                );
            }
        }
    }

    /**
     * Import articles.
     *
     * @param iterable $rows An iterator of articles to import.
     * @return iterable
     */
    public function importKnowledgeArticles(iterable $rows): iterable
    {
        try {
            $this->logger->beginInfo("Importing articles");

            return $this->importKnowledgeArticlesInternal($rows);
        } catch (\Exception $ex) {
            $this->logger->endError($ex->getMessage());
        }
    }

    /**
     * Internal implementation of article import.
     *
     * @param iterable $rows
     * @return array Returns an array in the format: `['added' => int, 'updated' => int, 'skipped' => int]`.
     */
    private function importKnowledgeArticlesInternal(iterable $rows): iterable
    {
        $added = $updated = $skipped = $deleted = $undeleted = $failed = 0;

        foreach ($rows as $row) {
            if (($row["skip"] ?? "") === "true") {
                try {
                    $existingArticle = $this->vanillaApi->getKnowledgeArticleBySmartID(
                        $row["foreignID"]
                    );
                    if ($existingArticle["status"] === "published") {
                        $this->vanillaApi
                            ->patch(
                                "/api/v2/articles/" .
                                    $existingArticle["articleID"] .
                                    "/status",
                                ["status" => "deleted"]
                            )
                            ->getBody();
                    }
                    $deleted++;
                } catch (HttpResponseException $ex) {
                    $this->logger->info(
                        "Failed to delete foreign draft article. It was likely never imported."
                    );
                    $skipped++;
                }
                continue;
            }

            try {
                $existingCategory = $this->vanillaApi->getKnowledgeCategoryBySmartID(
                    $row["knowledgeCategoryID"]
                );
            } catch (HttpResponseException $ex) {
                $this->logger->warning(
                    $ex->getMessage() . " failed to import knowledge-category"
                );
                $skipped++;
                continue;
            }
            $article = $existingArticle = null;
            if ($existingCategory) {
                $row["knowledgeCategoryID"] =
                    $existingCategory["knowledgeCategoryID"] ?? null;
                $alias = $row["alias"] ?? null;
                unset($row["alias"]);

                $rehostFileParams = [
                    "fileRehosting" => [
                        "enabled" => true,
                        "requestHeaders" => $this->rehostHeaders,
                    ],
                ];

                try {
                    $user = empty($row["userData"])
                        ? []
                        : $this->getOrCreateUser($row["userData"]);
                    // This should probably grab from the edit endpoint because that's what you'll be comparing to.
                    $existingArticle = $this->vanillaApi->getKnowledgeArticleBySmartID(
                        $row["foreignID"]
                    );
                    if ($existingArticle["status"] === "deleted") {
                        $this->vanillaApi->patch(
                            "/api/v2/articles/" .
                                $existingArticle["articleID"] .
                                "/status",
                            ["status" => "published"]
                        );
                        if (isset($row["featured"])) {
                            $this->putFeaturedArticle(
                                $existingArticle["articleID"],
                                $row["featured"]
                            );
                        }

                        $undeleted++;
                    }
                    $patch = $this->updateFields(
                        $existingArticle,
                        $row,
                        self::ARTICLE_EDIT_FIELDS,
                        "Article - " . $existingArticle["articleID"]
                    );
                    if (!empty($patch)) {
                        if (!empty($user)) {
                            $patch["updateUserID"] = $user["userID"];
                        }
                        $response = $this->vanillaApi->patch(
                            "/api/v2/articles/" . $existingArticle["articleID"],
                            array_merge($patch, $rehostFileParams)
                        );
                        $this->logRehostHeaders($response);
                        $article = $response->getBody();
                        if (isset($row["featured"])) {
                            $this->putFeaturedArticle(
                                $existingArticle["articleID"],
                                $row["featured"]
                            );
                        }
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (HttpResponseException $ex) {
                    if ($ex->getCode() != 404) {
                        $this->logger->warning(
                            $ex->getMessage() . " failed to import article."
                        );
                        continue;
                    }
                    if (!empty($row["userData"])) {
                        $user = $this->getOrCreateUser($row["userData"]);
                        $row["updateUserID"] = $user["userID"];
                        $row["insertUserID"] = $user["userID"];
                    }
                    try {
                        $response = $this->vanillaApi->post(
                            "/api/v2/articles",
                            array_merge($row, $rehostFileParams)
                        );
                        $this->logRehostHeaders($response);
                        $article = $response->getBody();
                        if (!is_null($alias)) {
                            if (!is_array($alias)) {
                                $alias = [$alias];
                            }
                            $this->vanillaApi->put(
                                "/api/v2/articles/" .
                                    $article["articleID"] .
                                    "/aliases",
                                ["aliases" => $alias]
                            );
                        }
                        if (isset($row["featured"]) && $row["featured"]) {
                            $this->putFeaturedArticle(
                                $article["articleID"],
                                $row["featured"]
                            );
                        }
                        $added++;
                    } catch (\Throwable $t) {
                        $this->logger->info(
                            "Failed to post article :" . json_encode($row)
                        );
                        $this->logger->info($t->getMessage());
                        $failed++;
                    }
                }
            }
            yield $article ?? $existingArticle;
        }
        $this->logger->end(
            "Done (added: {added}, updated: {updated}, skipped: {skipped}, deleted: {deleted}, undeleted: {undeleted}, failed: {failed})",
            [
                "added" => $added,
                "updated" => $updated,
                "skipped" => $skipped,
                "deleted" => $deleted,
                "undeleted" => $undeleted,
                "failed" => $failed,
            ]
        );
    }

    /**
     * @param iterable $rows
     * @return iterable
     */
    public function importUsers(iterable $rows): iterable
    {
        foreach ($rows as $row) {
            $user = $this->getOrCreateUser($row, true);
            yield $user;
        }
    }

    /**
     * Log information related to file re-hosting headers.
     *
     * @param HttpResponse $response The response to check.
     */
    private function logRehostHeaders(HttpResponse $response)
    {
        $successCount = (int) $response->getHeader(
            "x-file-rehosted-success-count",
            0
        );
        $failedCount = (int) $response->getHeader(
            "x-file-rehosted-failed-count",
            0
        );

        if ($successCount > 0) {
            $this->logger->info("Successfully rehosted $successCount files.");
        }

        if ($failedCount > 0) {
            $this->logger->warning("Failed to rehost $successCount files.");
        }
    }

    /**
     * Resolve knowledge base ID from smartID.
     *
     * @param string $smartID
     * @return int
     */
    public function resolveKnowledgeBaseID(string $smartID): int
    {
        $kb = $this->vanillaApi->get(
            "/api/v2/knowledge-bases/" . rawurlencode($smartID)
        );

        return $kb["knowledgeBaseID"];
    }

    /**
     * Get kb translations.
     *
     * @param array $query
     * @return int
     */
    public function getKnowledgeBaseTranslation(array $query): int
    {
        $translations = $this->vanillaApi
            ->get("/api/v2/translations/kb/" . "?" . http_build_query($query))
            ->getBody();

        return $translations;
    }

    /**
     * Resolve knowledge category ID from smartID.
     *
     * @param string $smartID
     * @return int
     */
    public function resolveKnowledgeCategoryID(string $smartID): int
    {
        $kb = $this->vanillaApi->get(
            "/api/v2/knowledge-categories/" . rawurlencode($smartID)
        );

        return $kb["knowledgeCategoryID"];
    }

    /**
     * Put a featured article.
     *
     * @param int $id
     * @param bool $featured
     */
    public function putFeaturedArticle(int $id, bool $featured)
    {
        $this->vanillaApi->put("/api/v2/articles/" . $id . "/featured", [
            "featured" => $featured,
        ]);
    }

    /**
     * @param array $config
     * @throws ValidationException
     */
    public function setConfig(array $config): void
    {
        /** @var Schema $schema */
        $schema = $this->configSchema();
        $config = $schema->validate($config);
        $this->config = $config;

        $domain = $this->config["domain"] ?? null;
        $protocol = $this->config["protocol"] ?? "https";
        $domain = $protocol . "://$domain";

        $isVerbose = $config["api"]["verbose"];
        if ($isVerbose) {
            $this->logger->setMinLevel(LogLevel::DEBUG);
        }

        if ($config["api"]["log"]) {
            /** @var HttpLogMiddleware $middleware */
            $middleware = $this->container->get(HttpLogMiddleware::class);
            if ($isVerbose) {
                $middleware->setLogBodies(true);
            }
            $this->vanillaApi->addMiddleware($middleware);
        }

        if ($config["api"]["cache"] ?? true) {
            $this->vanillaApi->addMiddleware(
                $this->container->get(HttpCacheMiddleware::class)
            );
        }

        if ($config["rate_limit_bypass_token"] ?? false) {
            /* @var HttpVanillaCloudRateLimitBypassMiddleware $rateLimitBypass */
            $rateLimitBypass = $this->container->get(
                HttpVanillaCloudRateLimitBypassMiddleware::class
            );
            $rateLimitBypass->setBypassToken(
                $config["rate_limit_bypass_token"]
            );
            $this->vanillaApi->addMiddleware($rateLimitBypass);
        }

        $this->vanillaApi->setToken($this->config["token"]);
        $this->vanillaApi->setBaseUrl($domain);
    }

    /**
     * Compare an existing row with a new one to see what's changed.
     *
     * @param array $existing
     * @param array $new
     * @param array $allowed
     * @return array
     */
    private function compareFields(
        array $existing,
        array $new,
        array $allowed
    ): array {
        $res = [];
        foreach ($allowed as $field) {
            if (is_array($field)) {
                $fieldKey = $field[0];
                $new[$fieldKey] = $this->{$field[1]}($new[$fieldKey]);
            } else {
                $fieldKey = $field;
            }
            if (
                isset($new[$fieldKey]) &&
                $new[$fieldKey] !== ($existing[$fieldKey] ?? null)
            ) {
                $res[$fieldKey] = $new[$fieldKey];
            }
        }

        return $res;
    }

    /**
     * Check if record fields need to be updated or not.
     *
     * @param array $existing
     * @param array $new
     * @param array $extra
     * @param string|null $skipLogName
     * @return array
     */
    private function updateFields(
        array $existing,
        array $new,
        array $extra,
        ?string $skipLogName = null
    ): array {
        $res = [];
        $updateMode = $this->config["update"] ?? self::UPDATE_MODE_ON_CHANGE;
        switch ($updateMode) {
            case self::UPDATE_MODE_ALWAYS:
                $res = $new;
                break;
            case self::UPDATE_MODE_ON_CHANGE:
                $res = $this->compareFields($existing, $new, $extra);
                break;
            case self::UPDATE_MODE_ON_DATE:
                if ($existing[self::DATE_UPDATED] ?? 0) {
                    $existingDate = strtotime($existing[self::DATE_UPDATED]);
                    $newDate = strtotime($new[self::DATE_UPDATED]);
                    $res =
                        $existingDate < $newDate
                            ? $this->compareFields($existing, $new, $extra)
                            : [];
                } else {
                    $res = $this->compareFields($existing, $new, $extra);
                }
                break;
            default:
                die("Improper update mode. This should not occur");
        }

        if (empty($res) && $skipLogName) {
            $this->logger->debug(
                "Skipping update to item '$skipLogName' because no changes were detected."
            );
        }

        return $res;
    }

    /**
     * @inheritDoc
     */
    public function getKnowledgeBaseBySmartID($foreignID): array
    {
        return $this->vanillaApi->getKnowledgeBaseBySmartID($foreignID);
    }

    /**
     * Get schema for config.
     *
     * @return Schema
     */
    private function configSchema(): Schema
    {
        return Schema::parse([
            "type:s?" => ["default" => "vanilla"],
            "protocol:s?" => [
                "description" =>
                    "Protocol to use for to access domain. (http || https)",
                "minLength" => 4,
                "default" => "https",
            ],
            "domain:s" => [
                "description" => "Vanilla knowledge base domain.",
                "minLength" => 5,
            ],
            "token:s" => [
                "description" => "Vanilla api Bearer token. Ex: 8piiaCXA2ts",
            ],
            "rate_limit_bypass_token:s?" => [
                "description" =>
                    "Vanilla Cloud rate limiting bypass token. Ex: fgc60lt90412yOUMJ8gRC1VXxmE0k",
            ],
            "update:s?" => [
                "description" => "Destination update mode.",
                "enum" => [
                    self::UPDATE_MODE_ALWAYS,
                    self::UPDATE_MODE_ON_CHANGE,
                    self::UPDATE_MODE_ON_DATE,
                ],
                "default" => self::UPDATE_MODE_ALWAYS,
            ],
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
            "retryLimit:i?" => [
                "description" => "Limit for retries",
                "default" => 1,
            ],
            "patchKnowledgeBase:b?" => [
                "description" => "Patch knowledge base if it exists already.",
                "default" => false,
            ],
            "syncUserByEmailOnly:b?" => [
                "description" =>
                    "Sync user by email only mode. When `false` allows 2nd lookup by username.",
                "default" => false,
            ],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function deleteArchivedArticles(
        array $knowledgeBases,
        array $zenDeskArticles,
        string $prefix = ""
    ) {
        // 5. Grab all the knowledge categories associated with the ZenDesk kbs.
        $knowledgeBaseIDs = array_column($knowledgeBases, "knowledgeBaseID");
        try {
            $knowledgeCategories = $this->vanillaApi->getKnowledgeCategoriesByKnowledgeBaseID(
                $knowledgeBaseIDs
            );
        } catch (HttpResponseException $ex) {
            $this->logger->error("Unable to find knowledge categories");
            die(
                "Can't proceed with clean up, not matching knowledge-categories found."
            );
        }

        // 6. Retrieve all the articles from each kb category.
        $articles = [];
        foreach ($knowledgeCategories as $knowledgeCategory) {
            $articleCount = $knowledgeCategory["articleCount"] ?? 0;

            if ($articleCount === 0) {
                continue;
            }

            $knowledgeCategoryID =
                $knowledgeCategory["knowledgeCategoryID"] ?? null;

            $results = [];
            try {
                $results = $this->vanillaApi->getKnowledgeArticlesByKnowledgeCategoryID(
                    $knowledgeCategoryID
                );
            } catch (HttpResponseException $ex) {
                $this->logger->info(
                    "Couldn't find articles matching knowledgeCategoryID # $knowledgeCategoryID"
                );
            }

            foreach ($results as $result) {
                $articles[] = $result;
            }
        }
        // 7. Compare the vanilla articles against the ZenDesk articles.
        $vanillaArticles = [];
        if ($articles) {
            foreach ($articles as &$article) {
                $id = str_replace($prefix, "", $article["foreignID"]);
                $vanillaArticles[$id] = $article["articleID"];
            }
            $zenDeskIDs = array_column($zenDeskArticles, "id", "id");

            $diff = [];
            foreach ($vanillaArticles as $key => $vanillaArticle) {
                $exists = $zenDeskIDs[$key] ?? false;

                // 8.  If no article exists on ZenDesk, update the status on Vanilla.
                if (!$exists) {
                    try {
                        $this->vanillaApi->updateKnowledgeArticleStatus(
                            $vanillaArticle
                        );
                    } catch (HttpResponseException $ex) {
                        $this->logger->info(
                            "Failed to set deleted status on article # $vanillaArticle"
                        );
                    }
                    $diff[] = $vanillaArticle;
                    $this->logger->info(
                        "Article # $vanillaArticle status was set to deleted"
                    );
                }
            }

            $numberOfArticlesDeleted = count($diff);
            $this->logger->info(
                "$numberOfArticlesDeleted articles were deleted."
            );
        }
    }
}
