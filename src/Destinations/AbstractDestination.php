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

    /**
     * Import knowledge bases from source to destination.
     *
     * @param iterable $rows
     */
    abstract public function importKnowledgeBases(iterable $rows): void;

    /**
     * Import knowledge categories from source to destination.
     *
     * @param iterable $rows
     */
    abstract public function importKnowledgeCategories(iterable $rows): void;

    /**
     * Import knowledge articles from source to destination.
     *
     * @param iterable $rows
     */
    abstract public function importKnowledgeArticles(iterable $rows): void;
}
