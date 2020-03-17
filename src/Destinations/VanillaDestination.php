<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Garden\Schema\Schema;
use Psr\Container\ContainerInterface;
use Vanilla\KnowledgePorter\HttpClients\HttpCacheMiddleware;
use Vanilla\KnowledgePorter\HttpClients\HttpLogMiddleware;
use Vanilla\KnowledgePorter\HttpClients\NotFoundException;
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;

/**
 * Class VanillaDestination
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
        'body'
    ];

    const KB_EDIT_FIELDS = [
        'name',
        'description',
        'urlCode',
        'sourceLocale',
        'viewType',
        'sortArticles'
    ];

    const KB_CATEGORY_EDIT_FIELDS = [
        'name',
        ['knowledgeBaseID', 'resolveKnowledgeBaseID'],
        ['parentID', 'resolveKnowledgeCategoryID'],
    ];

    /**
     * @var VanillaClient
     */
    private $vanillaApi;

    /** @var ContainerInterface $container */
    protected $container;

    /**
     * VanillaDestination constructor.
     * @param VanillaClient $vanillaApi
     */
    public function __construct(VanillaClient $vanillaApi, ContainerInterface $container) {
        $this->vanillaApi = $vanillaApi;
        $this->container = $container;
    }

    /**
     * @param iterable $rows
     */
    public function importKnowledgeBases(iterable $rows): void {
        foreach ($rows as $row) {
            if (($row['skip'] ?? '') === 'true') {
                continue;
            }
            try {
                $existing = $this->vanillaApi->getKnowledgeBaseBySmartID($row["foreignID"]);
                $patch = $this->updateFields($existing, $row, self::KB_EDIT_FIELDS);
                if (!empty($patch)) {
                    $this->vanillaApi->patch('/api/v2/knowledge-bases/' . $existing['knowledgeBaseID'], $patch);
                }
            } catch (NotFoundException $ex) {
                $kb = $this->vanillaApi->post('/api/v2/knowledge-bases', $row)->getBody();
            }
        }
    }

    /**
     * @param iterable $rows
     */
    public function importKnowledgeCategories(iterable $rows): void {
        foreach ($rows as $row) {
            if (($row['skip'] ?? '') === 'true') {
                continue;
            }
            if (($row['rootCategory'] ?? 'false') === 'true') {
                $result = $this->vanillaApi->get("/api/v2/knowledge-bases/".rawurlencode($row['knowledgeBaseID']));
                $kb = $result->getBody();
                $this->vanillaApi->patch('/api/v2/knowledge-categories/'.$kb['rootCategoryID'].'/root', ['foreignID' => $row["foreignID"]]);
            } else {
                if (($row['parentID'] ?? '') === 'null') {
                    $result = $this->vanillaApi->get("/api/v2/knowledge-bases/".rawurlencode($row['knowledgeBaseID']));
                    $kb = $result->getBody();
                    $row['parentID'] = $kb['rootCategoryID'];
                };
                try {
                    $existing = $this->vanillaApi->getKnowledgeCategoryBySmartID($row["foreignID"]);
                    $patch = $this->updateFields($existing, $row, self::KB_CATEGORY_EDIT_FIELDS);
                    if (!empty($patch)) {
                        $this->vanillaApi->patch(
                            '/api/v2/knowledge-categories/' . $existing['knowledgeCategoryID'],
                            $patch
                        );
                    }
                } catch (NotFoundException $ex) {
                    $this->vanillaApi->post('/api/v2/knowledge-categories', $row);
                }
            }
        }
    }

    /**
     * Import articles.
     *
     * @param iterable $rows An iterator of articles to import.
     */
    public function importKnowledgeArticles(iterable $rows): void {
        try {
            $this->logger->beginInfo("Importing articles");
            $counts = $this->importKnowledgeArticlesInternal($rows);
            $this->logger->end("Done (added: {added}, updated: {updated}, skipped: {skipped})", $counts);
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
    private function importKnowledgeArticlesInternal(iterable $rows): array {
        $added = $updated = $skipped = 0;

        foreach ($rows as $row) {
            if (($row['skip'] ?? '') === 'true') {
                $skipped++;
                continue;
            }

            try {
                $existingCategory = $this->vanillaApi->getKnowledgeCategoryBySmartID($row["knowledgeCategoryID"]);
            } catch (NotFoundException $ex) {
                $this->logger->warning('knowledge category not found');
                $skipped++;
                continue;
            }

            if ($existingCategory) {
                $row['knowledgeCategoryID'] = $existingCategory['knowledgeCategoryID'] ?? null;
                $alias = $row["alias"] ?? null;
                unset($row['alias']);
                try {
                    // This should probably grab from the edit endpoint because that's what you'll be comparing to.
                    $existingArticle = $this->vanillaApi->getKnowledgeArticleBySmartID($row["foreignID"]);
                    $patch = $this->compareFields($existingArticle, $row, self::ARTICLE_EDIT_FIELDS);
                    if (!empty($patch)) {
                        $this->vanillaApi->patch('/api/v2/articles/' . $existingArticle['articleID'], $patch);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (NotFoundException $ex) {
                    $response = $this->vanillaApi->post('/api/v2/articles', $row)->getBody();
                    $this->vanillaApi->put('/api/v2/articles/' . $response['articleID'] . '/aliases', ["aliases" => [$alias]]);
                    $added++;
                }
            }
        }

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
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
     * @param array $config
     */
    public function setConfig(array $config): void {
        /** @var Schema $schema */
        $schema = $this->configSchema();
        $config = $schema->validate($config);
        $this->config = $config;

        $domain = $this->config['domain'] ?? null;
        $domain = "http://$domain";

        if ($config['api']['log'] ?? true) {
            $this->vanillaApi->addMiddleware($this->container->get(HttpLogMiddleware::class));
        }

        if ($config['api']['cache'] ?? true) {
            $this->vanillaApi->addMiddleware($this->container->get(HttpCacheMiddleware::class));
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
            if (isset($new[$fieldKey]) && ($new[$fieldKey] !== $existing[$fieldKey])) {
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
                if ($existing[self::DATE_UPDATED] < $new[self::DATE_UPDATED]) {
                    $res = $new;
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
            "domain:s" => [
                "description" => "Vanilla knowledge base domain.",
                "minLength" => 5
            ],
            "token:s" => [
                "description" => "Vanilla api Bearer token. Ex: 8piiaCXA2ts"
            ],
            "update:s?" => [
                "description" => "Destination update mode.",
                "enum" => [
                    self::UPDATE_MODE_ALWAYS,
                    self::UPDATE_MODE_ON_CHANGE,
                    self::UPDATE_MODE_ON_DATE
                ],
                "default" => self::UPDATE_MODE_ALWAYS
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

            ]
        ]);
    }
}
