<?php
/**
 * @author Olivier Lamy-Canuel <olamy-canuel@higherlogic.com>
 * @copyright 2009-2023 Higher Logic Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Exception;

class VanillaMockDestination extends AbstractDestination
{
    const VALID_PAGE = 1;
    const EMPTY_PAGE = 2;
    const INVALID_PAGE = 3;
    public function importKnowledgeBases(iterable $rows): iterable
    {
        $mock = $this->config["pageFrom"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    public function importUsers(iterable $rows): iterable
    {
        $mock = $this->config["pageFrom"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    public function importKnowledgeCategories(iterable $rows): iterable
    {
        $mock = $this->config["pageFrom"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    public function importKnowledgeArticles(iterable $rows): iterable
    {
        $mock = $this->config["pageFrom"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    public function getKnowledgeBaseBySmartID(string $foreignID): array
    {
        // TODO: Implement getKnowledgeBaseBySmartID() method.
        return [];
    }

    public function deleteArchivedArticles(
        array $knowledgeBases,
        array $articles,
        string $prefix
    ) {
        // TODO: Implement deleteArchivedArticles() method.
        return [];
    }

    /**
     * @inheritDoc
     */
    public function syncKnowledgeBase(
        array $foreignKnowledgeBaseIDs,
        array $query = []
    ) {
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
    ) {
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
    ) {
        $page = $query["page"];
        $result = $this->returnMockRecords($page);
        return $result;
    }

    /**
     * @param int $mockValue
     * @return array|string[]
     * @throws Exception
     */
    protected function returnMockRecords(int $mockValue): array
    {
        return match ($mockValue) {
            self::VALID_PAGE => ["zd1", "zd2", "zd3"],
            self::EMPTY_PAGE => self::NO_DATA,
            default => throw new Exception("An error has occurred"),
        };
    }
}
