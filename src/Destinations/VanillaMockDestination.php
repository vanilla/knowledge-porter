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

    public function syncArticles(
        array $zendeskArticles,
        int $limit,
        int $page,
        array $knowledgeBaseIDs = []
    ): array {
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
        switch ($mockValue) {
            case self::VALID_PAGE:
                return ["zd1", "zd2", "zd3"];
                break;
            case self::EMPTY_PAGE:
                return [];
            case self::INVALID_PAGE:
            default:
                throw new Exception("An error has occurred");
        }
    }
}
