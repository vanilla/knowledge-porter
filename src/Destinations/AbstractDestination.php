<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Vanilla\KnowledgePorter\ConfigurableTrait;
use Vanilla\KnowledgePorter\TaskLoggerAwareInterface;
use Vanilla\KnowledgePorter\TaskLoggerAwareTrait;

/**
 * Class AbstractDestination
 * @package Vanilla\KnowledgePorter\Destinations
 */
abstract class AbstractDestination implements TaskLoggerAwareInterface {
    use ConfigurableTrait, TaskLoggerAwareTrait;

    /** @var string[] */
    protected $rehostHeaders = [];

    /**
     * @param string[] $rehostHeaders
     */
    public function setRehostHeaders(array $rehostHeaders): void {
        $this->rehostHeaders = $rehostHeaders;
    }

    /**
     * Import knowledge bases from source to destination.
     *
     * @param iterable $rows
     */
    abstract public function importKnowledgeBases(iterable $rows): iterable;

    /**
     * Import uesrs from source to destination.
     *
     * @param iterable $rows
     */
    abstract public function importUsers(iterable $rows): iterable;

    /**
     * Import knowledge categories from source to destination.
     *
     * @param iterable $rows
     */
    abstract public function importKnowledgeCategories(iterable $rows): iterable;

    /**
     * Import knowledge articles from source to destination.
     *
     * @param iterable $rows
     */
    abstract public function importKnowledgeArticles(iterable $rows): iterable;

    /**
     *
     * @param string $foreignID
     * @return array
     */
    abstract public function getKnowledgeBaseBySmartID(string $foreignID): array;

    /**
     * Delete archived articles.
     *
     * @param array $knowledgeBases
     * @param array $articles
     * @param string $prefix
     */
    abstract public function deleteArchivedArticles(array $knowledgeBases, array $articles, string $prefix);

}
