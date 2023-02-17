<?php
/**
 * @author Olivier Lamy-Canuel <olamy-canuel@higherlogic.com>
 * @copyright 2009-2023 Higher Logic Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Exception;

class ZendeskMockSource extends ZendeskSource
{
    const VALID_PAGE = 1;
    const INVALID_PAGE = -1;
    const EMPTY_PAGE = 0;

    /**
     * Mock a call made to ZendeskSource::processKnowledgeBases().
     *
     * @return array
     */
    public function processKnowledgeBases(): array
    {
        $mock = $this->config["mock"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    /**
     * Mock a call made to ZendeskSource::processKnowledgeCategories().
     *
     * @return array
     */
    public function processKnowledgeCategories(): array
    {
        $mock = $this->config["mock"];
        $result = $this->returnMockRecords($mock);
        return $result;
    }

    /**
     * Mock a call made to ZendeskSource::processKnowledgeArticles.
     *
     * @return array
     */
    public function processKnowledgeArticles(): array
    {
        $mock = $this->config["mock"];
        $result = $this->returnMockRecords($mock);
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
