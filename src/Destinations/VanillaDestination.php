<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Vanilla\KnowledgePorter\HttpClients\NotFoundException;
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;

/**
 * Class VanillaDestination
 * @package Vanilla\KnowledgePorter\Destinations
 */
class VanillaDestination extends AbstractDestination {

    /**
     * @var VanillaClient
     */
    private $vanillaApi;

    /**
     * VanillaDestination constructor.
     * @param VanillaClient $vanillaApi
     */
    public function __construct(VanillaClient $vanillaApi) {
        $this->vanillaApi = $vanillaApi;
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
                $this->vanillaApi->patch('/api/v2/knowledge-bases/'.$existing['knowledgeBaseID'], $row);
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
                    $this->vanillaApi->patch('/api/v2/knowledge-categories/' . $existing['knowledgeCategoryID'], $row);
                } catch (NotFoundException $ex) {
                    $this->vanillaApi->post('/api/v2/knowledge-categories', $row);
                }
            }
        }
    }

    /**
     * @param iterable $rows
     */
    public function importKnowledgeArticles(iterable $rows): void {
        foreach ($rows as $row) {
            if (($row['skip'] ?? '') === 'true') {
                continue;
            }

            try {
                $existingCategory = $this->vanillaApi->getKnowledgeCategoryBySmartID($row["knowledgeCategoryID"]);
            } catch (NotFoundException $ex) {
                $this->logger->warning('knowledge category not found');
                continue;
            }

            if ($existingCategory) {
                $row['knowledgeCategoryID'] = $existingCategory['knowledgeCategoryID'] ?? null;
                $alias = $row["alias"] ?? null;
                unset($row['alias']);
                try {
                    // This should probably grab from the edit endpoint because that's what you'll be comparing to.
                    $existingArticle = $this->vanillaApi->getKnowledgeArticleBySmartID($row["foreignID"]);
                    $patch = $this->compareFields($existingArticle, $row);
                    if (!empty($patch)) {
                        $this->vanillaApi->patch('/api/v2/articles/' . $existingArticle['articleID'], $patch);
                    }
                } catch (NotFoundException $ex) {
                    $response = $this->vanillaApi->post('/api/v2/articles', $row)->getBody();
                    $this->vanillaApi->put('/api/v2/articles/'.$response['articleID'].'/aliases', ["aliases" => [$alias]]);
                }
            }
        }
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config): void {
        $this->config = $config;

        $this->vanillaApi->setToken($this->config['token']);
        $this->vanillaApi->setBaseUrl($this->config['baseUrl']);
    }

    /**
     * Compare an existing row with a new one to see what's changed.
     *
     * @param array $existing
     * @param array $new
     * @return array
     * @todo Fill this in.
     */
    private function compareFields(array $existing, array $new): array {
        return $new;
    }
}
