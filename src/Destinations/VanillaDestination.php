<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Garden\Http\HttpResponse;
use Garden\Http\HttpResponseException;
use Garden\Schema\Schema;
use Garden\Schema\ValidationException;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\HttpClients\HttpCacheMiddleware;
use Vanilla\KnowledgePorter\HttpClients\HttpLogMiddleware;
use Vanilla\KnowledgePorter\HttpClients\HttpVanillaCloudRateLimitBypassMiddleware;
use Vanilla\KnowledgePorter\HttpClients\NotFoundException;
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;

/**
 * Class VanillaDestination
 *
 * @package Vanilla\KnowledgePorter\Destinations
 */
class VanillaDestination extends AbstractDestination {
    const UPDATE_MODE_ALWAYS = 'always';
    const UPDATE_MODE_ON_CHANGE = 'onChange';
    const UPDATE_MODE_ON_DATE = 'onDate';

    const DATE_UPDATED = 'dateUpdated';

    const ARTICLE_EDIT_FIELDS = [
        ['knowledgeCategoryID', 'resolveKnowledgeCategoryID'],
        'name',
        'body',
        'featured',
    ];

    const KB_EDIT_FIELDS = [
        'name',
        'description',
        'urlCode',
        'sourceLocale',
        'viewType',
        'sortArticles',
    ];

    const KB_TRANSLATION_FIELDS = [
        'translation',
    ];

    const ARTICLE_TRANSLATION_FIELDS = [
        'name',
        'body',
    ];

    const KB_CATEGORY_EDIT_FIELDS = [
        'name',
        ['knowledgeBaseID', 'resolveKnowledgeBaseID'],
        ['parentID', 'resolveKnowledgeCategoryID'],
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
    public function __construct(VanillaClient $vanillaApi, ContainerInterface $container) {
        $this->vanillaApi = $vanillaApi;
        $this->container = $container;
    }

    /**
     * @param iterable $rows
     * @return iterable
     */
    public function importKnowledgeBases(iterable $rows): iterable {
        foreach ($rows as $row) {
            if (($row['skip'] ?? '') === 'true') {
                continue;
            }
            try {
                $existing = $this->vanillaApi->getKnowledgeBaseBySmartID($row["foreignID"]);
                if ($this->config['patchKnowledgeBase'] ?? false) {
                    $patch = $this->updateFields($existing, $row, self::KB_EDIT_FIELDS);
                    if (!empty($patch)) {
                        $kb = $this->vanillaApi->patch('/api/v2/knowledge-bases/'.$existing['knowledgeBaseID'], $patch)->getBody();
                    }
                }
            } catch (NotFoundException $ex) {
                $kb = $this->vanillaApi->post('/api/v2/knowledge-bases', $row)->getBody();
            }
            $kb = $kb ?? $existing;
            if (($row['generateRootCategoryForeignID'] ?? 'false') === 'true') {
                $kbCat = $this->vanillaApi->patch(
                    '/api/v2/knowledge-categories/'.$kb['rootCategoryID'].'/root',
                    ['foreignID' => $row["foreignID"].'-root']
                )->getBody();
            }
            yield $kb;
        }
    }

    /**
     * @param iterable $rows
     */
    public function importKnowledgeBaseTranslations(iterable $rows) {
        foreach ($rows as $row) {
            if (($row['skip'] ?? 'false') === 'true') {
                continue;
            }
            $lookup = $row;
            $lookup['recordIDs'] = [$row['recordID']];
            $existing = $this->vanillaApi->getKnowledgeBaseTranslation($lookup);
            $patch = $this->updateFields($existing, $row, self::KB_TRANSLATION_FIELDS);
            if (!empty($patch)) {
                // $row contains all fields needed for translation api
                // $patch has only 'translation' field if
                // we use $patch as trigger, but $row as a body for translation
                $res = $this->vanillaApi->patch('/api/v2/translations/kb', [$row]);
            }
        }
    }

    /**
     * @param iterable $rows
     */
    public function importArticleTranslations(iterable $rows) {
        foreach ($rows as $row) {
            if (($row['skip'] ?? 'false') === 'true') {
                continue;
            }
            $existing = $this->vanillaApi->get('/api/v2/articles/'.$row['articleID'].'?'.http_build_query(['locale' => $row['locale']]))->getBody();
            $patch = $this->updateFields($existing, $row, self::ARTICLE_TRANSLATION_FIELDS);
            if (!empty($patch)) {
                // $row contains all fields needed for translation api
                // $patch has only 'translation' field if
                // we use $patch as trigger, but $row as a body for translation
                if (!empty($row['userData'])) {
                    $user = $this->getOrCreateUser($row['userData']);
                    $row['updateUserID'] = $user['userID'];
                    unset($row['userData']);
                }
                $row['validateLocale'] = false;
                $rehostFileParams = [
                    'fileRehosting' => [
                        'enabled' => true,
                        'requestHeaders' => $this->rehostHeaders,
                    ],
                ];

                $res = $this->vanillaApi->patch('/api/v2/articles/'.$row['articleID'], array_merge($row, $rehostFileParams));
                $this->logRehostHeaders($res);
            }
        }
    }

    /**
     * @param iterable $rows
     */
    public function importArticleVotes(iterable $rows) {
        foreach ($rows as $row) {
            if (empty($row['userData'])) {
                $row['insertUserID'] = -1;
            } else {
                $user = $this->getOrCreateUser($row['userData']);
                $row['insertUserID'] = $user['userID'];
                unset($row['userData']);
            }

            try {
                $res = $this->vanillaApi->put('/api/v2/articles/'.$row['articleID'].'/react', $row);
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
    private function getOrCreateUser(array $userData, bool $update = false): array {
        try {
            $user = $this->vanillaApi->get('/api/v2/users/$email:'.$userData['email'])->getBody();
            if ($update) {
                $user = $this->vanillaApi->patch('/api/v2/users/'.$user['userID'], $userData)->getBody();
            }
        } catch (NotFoundException $ex) {
            if (!$this->config['syncUserByEmailOnly']) {
                try {
                    $user = $this->vanillaApi->get('/api/v2/users/$name:' . $userData['name'])->getBody();
                } catch (NotFoundException $ex) {
                    $user = $this->vanillaApi->post('/api/v2/users/', $userData)->getBody();
                }
            } else {
                $user = $this->vanillaApi->post('/api/v2/users/', $userData)->getBody();
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
    public function importKnowledgeCategories(iterable $rows): iterable {
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
    public function importKnowledgeCategoriesInternal(iterable $rows, bool $retry = false): iterable {
        $added = $updated = $skipped = $failures = 0;
        foreach ($rows as $row) {
            if (($row['skip'] ?? '') === 'true') {
                continue;
            }

            if (($row['rootCategory'] ?? 'false') === 'true') {
                $result = $this->vanillaApi->get("/api/v2/knowledge-bases/".rawurlencode($row['knowledgeBaseID']));
                $kb = $result->getBody();
                $kbCat = $this->vanillaApi->patch('/api/v2/knowledge-categories/'.$kb['rootCategoryID'].'/root', ['foreignID' => $row["foreignID"]])->getBody();
                $updated++;
            } else {
                if (($row['parentID'] ?? '') === 'null') {
                    try {
                        $result = $this->vanillaApi->get("/api/v2/knowledge-bases/".rawurlencode($row['knowledgeBaseID']));
                        $kb = $result->getBody();
                        $row['parentID'] = $kb['rootCategoryID'];
                    } catch (HttpResponseException $exception) {
                        $row['failed'] = true;
                        self::$kbcats[] = $row;
                        $failures++;
                        continue;
                    }
                };
                try {
                    $existing = $this->vanillaApi->getKnowledgeCategoryBySmartID($row["foreignID"]);
                    $patch = $this->updateFields($existing, $row, self::KB_CATEGORY_EDIT_FIELDS);
                    $updated++;
                    if (!empty($patch)) {
                        $kbCat = $this->vanillaApi->patch(
                            '/api/v2/knowledge-categories/'.$existing['knowledgeCategoryID'],
                            $patch
                        )->getBody()
                        ;
                    }
                } catch (NotFoundException | HttpResponseException $ex) {
                    if ($ex->getCode() === 500) {
                        $row['failed'] = true;
                        self::$kbcats[] = $row;
                        $failures++;
                        continue;
                    } else {
                        try {
                            $kbCat = $this->vanillaApi->post('/api/v2/knowledge-categories', $row)->getBody();
                            $added++;
                        } catch (NotFoundException | HttpResponseException $ex) {
                            if ($ex->getCode() === 404) {
                                $row['failed'] = true;
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
                ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'failures' => $failures]
            );
        }
    }

    /**
     * Try to reprocess failed knowledge categories that failed to import.
     *
     * @return iterable
     */
    public function processFailedImportedKnowledgeCategories(): iterable {
        $initialCount = count(self::$kbcats);
        if ($initialCount > 0) {
            $retryLimit = $this->config['retryLimit'] ?? 1;
            $count = $initialCount;
            for ($i = 0; $i <= $retryLimit; $i++) {
                $retry = false;
                $this->logger->beginInfo("Retry importing knowledge categories");
                $originalFailedKBCategories = new \ArrayObject(self::$kbcats);
                self::$kbcats = [];
                $kbCategories = $this->importKnowledgeCategoriesInternal($originalFailedKBCategories, true);
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

                if (!$retry && (count(self::$kbcats) > 0)) {
                    $this->logger->info('Error importing to '.count(self::$kbcats).' categories');
                    die();
                }

                $this->logger->end("Done(successful:{successful}, failed:{failed})",
                    [
                        'successful' => ($initialCount - self::$count),
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
    public function importKnowledgeArticles(iterable $rows): iterable {
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
    private function importKnowledgeArticlesInternal(iterable $rows): iterable {
        $added = $updated = $skipped = $deleted = $undeleted = $failed = 0;

        foreach ($rows as $row) {
            if (($row['skip'] ?? '') === 'true') {
                try {
                    $existingArticle = $this->vanillaApi->getKnowledgeArticleBySmartID($row["foreignID"]);
                    $article = $this->vanillaApi->patch(
                        '/api/v2/articles/'.$existingArticle['articleID'].'/status',
                        ['status' => 'deleted']
                    )->getBody()
                    ;
                    $deleted++;
                } catch (NotFoundException $ex) {
                    $skipped++;
                }
                continue;
            }

            try {
                $existingCategory = $this->vanillaApi->getKnowledgeCategoryBySmartID($row["knowledgeCategoryID"]);
            } catch (NotFoundException $ex) {
                $this->logger->warning('knowledge category not found');
                $skipped++;
                continue;
            }
            $article = $existingArticle = null;
            if ($existingCategory) {
                $row['knowledgeCategoryID'] = $existingCategory['knowledgeCategoryID'] ?? null;
                $alias = $row["alias"] ?? null;
                unset($row['alias']);

                $rehostFileParams = [
                    'fileRehosting' => [
                        'enabled' => true,
                        'requestHeaders' => $this->rehostHeaders,
                    ],
                ];

                try {
                    $user = empty($row['userData']) ? [] : $this->getOrCreateUser($row['userData']);
                    // This should probably grab from the edit endpoint because that's what you'll be comparing to.
                    $existingArticle = $this->vanillaApi->getKnowledgeArticleBySmartID($row["foreignID"]);
                    if ($existingArticle['status'] === 'deleted') {
                        $this->vanillaApi->patch(
                            '/api/v2/articles/'.$existingArticle['articleID'].'/status',
                            ['status' => "published"]
                        );
                        if (isset($row['featured'])) {
                            $this->putFeaturedArticle($existingArticle['articleID'], $row['featured']);
                        }

                        $undeleted++;
                    }
                    $patch = $this->updateFields($existingArticle, $row, self::ARTICLE_EDIT_FIELDS);
                    if (!empty($patch)) {
                        if (!empty($user)) {
                            $patch['updateUserID'] = $user['userID'];
                        }
                        $response = $this->vanillaApi->patch('/api/v2/articles/' . $existingArticle['articleID'], array_merge($patch, $rehostFileParams));
                        $this->logRehostHeaders($response);
                        $article = $response->getBody();
                        if (isset($row['featured'])) {
                            $this->putFeaturedArticle($existingArticle['articleID'], $row['featured']);
                        }
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (NotFoundException $ex) {
                    if (!empty($row['userData'])) {
                        $user = $this->getOrCreateUser($row['userData']);
                        $row['updateUserID'] = $user['userID'];
                        $row['insertUserID'] = $user['userID'];
                    }
                    try {
                        $response = $this->vanillaApi->post('/api/v2/articles', array_merge($row, $rehostFileParams));
                        $this->logRehostHeaders($response);
                        $article = $response->getBody();
                        if (!is_null($alias)) {
                            $this->vanillaApi->put('/api/v2/articles/' . $article['articleID'] . '/aliases', ["aliases" => [$alias]]);
                        }
                        if (isset($row['featured']) && $row['featured']) {
                            $this->putFeaturedArticle($article['articleID'], $row['featured']);
                        }
                        $added++;
                    } catch (\Throwable $t) {
                        $this->logger->info('Failed to post article :'.json_encode($article));
                        $failed++;
                    }
                }
            }
            yield $article ?? $existingArticle;
        }
        $this->logger->end(
            "Done (added: {added}, updated: {updated}, skipped: {skipped}, deleted: {deleted}, undeleted: {undeleted}, failed: {failed})",
            ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'deleted' => $deleted, 'undeleted' => $undeleted, 'failed' => $failed]
        );
    }

    /**
     * @param iterable $rows
     * @return iterable
     */
    public function importUsers(iterable $rows): iterable {
        foreach ($rows as $row) {
            $user = $this->getOrCreateUser($row, true);
            yield $user;
        }
    }

    /**
     * Log information related to file rehosting headers.
     *
     * @param HttpResponse $response The response to check.
     */
    private function logRehostHeaders(HttpResponse $response) {
        $successCount = (int) $response->getHeader('x-file-rehosted-success-count', 0);
        $failedCount = (int) $response->getHeader('x-file-rehosted-failed-count', 0);

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
    public function resolveKnowledgeBaseID(string $smartID): int {
        $kb = $this->vanillaApi->get("/api/v2/knowledge-bases/".rawurlencode($smartID));

        return $kb["knowledgeBaseID"];
    }

    /**
     * Get kb translations.
     *
     * @param array $query
     * @return int
     */
    public function getKnowledgeBaseTranslation(array $query): int {
        $translations = $this->vanillaApi->get("/api/v2/translations/kb/".'?'.http_build_query($query))->getBody();

        return $translations;
    }

    /**
     * Resolve knowledge category ID from smartID.
     *
     * @param string $smartID
     * @return int
     */
    public function resolveKnowledgeCategoryID(string $smartID): int {
        $kb = $this->vanillaApi->get("/api/v2/knowledge-categories/".rawurlencode($smartID));

        return $kb["knowledgeCategoryID"];
    }

    /**
     * Put a featured article.
     *
     * @param int $id
     * @param bool $featured
     */
    public function putFeaturedArticle(int $id, bool $featured) {
        $this->vanillaApi->put('/api/v2/articles/' . $id . '/featured', ["featured" => $featured]);
    }

    /**
     * @param array $config
     * @throws ValidationException
     */
    public function setConfig(array $config): void {
        /** @var Schema $schema */
        $schema = $this->configSchema();
        $config = $schema->validate($config);
        $this->config = $config;

        $domain = $this->config['domain'] ?? null;
        $protocol = $this->config['protocol'] ?? 'https';
        $domain = $protocol."://$domain";

        if ($config['api']['log'] ?? true) {
            $this->vanillaApi->addMiddleware($this->container->get(HttpLogMiddleware::class));
        }

        if ($config['api']['cache'] ?? true) {
            $this->vanillaApi->addMiddleware($this->container->get(HttpCacheMiddleware::class));
        }

        if ($config['rate_limit_bypass_token'] ?? false) {
            /* @var HttpVanillaCloudRateLimitBypassMiddleware $rateLimitBypass */
            $rateLimitBypass = $this->container->get(HttpVanillaCloudRateLimitBypassMiddleware::class);
            $rateLimitBypass->setBypassToken($config['rate_limit_bypass_token']);
            $this->vanillaApi->addMiddleware($rateLimitBypass);
        }

        $this->vanillaApi->setToken($this->config['token']);
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
    private function compareFields(array $existing, array $new, array $allowed): array {
        $res = [];
        foreach ($allowed as $field) {
            if (is_array($field)) {
                $fieldKey = $field[0];
                $new[$fieldKey] = $this->{$field[1]}($new[$fieldKey]);
            } else {
                $fieldKey = $field;
            }
            if (isset($new[$fieldKey]) && ($new[$fieldKey] !== ($existing[$fieldKey] ?? null))) {
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
     * @return array
     */
    private function updateFields(array $existing, array $new, array $extra): array {
        $res = [];
        $updateMode = $this->config['update'] ?? self::UPDATE_MODE_ON_CHANGE;
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
                    $res = ($existingDate < $newDate) ? $new : [];
                }
                break;
        }

        return $res;
    }

    /**
     * Get schema for config.
     *
     * @return Schema
     */
    private function configSchema(): Schema {
        return Schema::parse([
            "type:s?" => ["default" => 'vanilla'],
            "protocol:s?" => [
                "description" => "Protocol to use for to access domain. (http || https)",
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
                "description" => "Vanilla Cloud rate limiting bypass token. Ex: fgc60lt90412yOUMJ8gRC1VXxmE0k",
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
                "description" => "Sync user by email only mode. When `false` allows 2nd lookup by username.",
                "default" => false
            ],
        ]);
    }
}
