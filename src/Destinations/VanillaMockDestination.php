<?php
/**
 * @author Olivier Lamy-Canuel <olamy-canuel@higherlogic.com>
 * @copyright 2009-2023 Higher Logic Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Exception;

/**
 * Mock the return values of a VanillaDestination.
 */
class VanillaMockDestination extends AbstractDestination
{
    const VALID_PAGE = 1;
    const EMPTY_PAGE = 2;
    const INVALID_PAGE = 3;

    /**
     * Mock a call made to VanillaDestination::importKnowledgeBases.
     *
     * @param iterable $rows
     * @return iterable
     * @throws Exception
     */
    public function importKnowledgeBases(iterable $rows): iterable
    {
        $mock = $this->config["pageFrom"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function importUsers(iterable $rows): iterable
    {
        // TODO: Implement importUsers() method if we ever need it.
        return [];
    }

    /**
     * Mock a call made to VanillaDestination::importKnowledgeCategories.
     *
     * @param iterable $rows
     * @return iterable
     * @throws Exception
     */
    public function importKnowledgeCategories(iterable $rows): iterable
    {
        $mock = $this->config["pageFrom"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    /**
     * Mock a call made to VanillaDestination::importKnowledgeArticles.
     *
     * @param iterable $rows
     * @return iterable
     * @throws Exception
     */
    public function importKnowledgeArticles(iterable $rows): iterable
    {
        $mock = $this->config["pageFrom"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getKnowledgeBaseBySmartID(string $foreignID): array
    {
        // TODO: Implement getKnowledgeBaseBySmartID() method if we ever need it.
        return [];
    }

    /**
     * @inheritDoc
     */
    public function deleteArchivedArticles(
        array $knowledgeBases,
        array $articles,
        string $prefix
    ) {
        // TODO: Implement deleteArchivedArticles() method  if we ever need it.
        return [];
    }

    /**
     * @inheritDoc
     */
    public function syncKnowledgeBase(
        array $foreignKnowledgeBaseIDs,
        array $query = []
    ): array {
        $page = $query["page"];
        $result = $this->returnMockRecords($page);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function syncKnowledgeCategories(
        array $foreignKnowledgeCategoryIDs,
        array $query = []
    ): array {
        $page = $query["page"];
        $result = $this->returnMockRecords($page);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function syncArticles(
        array $foreignArticleIDs,
        array $query = []
    ): array {
        $page = $query["page"];
        $result = $this->returnMockRecords($page);
        return $result;
    }

    /**
     * Generate fake output for testing purposes.
     *
     * @param int $mockValue
     * @return array|string[]
     * @throws Exception
     */
    protected function returnMockRecords(int $mockValue): array
    {
        switch ($mockValue) {
            case self::VALID_PAGE:
                return ["fetched" => 3, "vanillaIDs" => [1, 2, 3]];
            case self::EMPTY_PAGE:
                return [];
            case self::INVALID_PAGE:
            default:
                throw new Exception("An error has occurred");
        }
    }
}
