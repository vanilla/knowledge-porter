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

abstract class AbstractDestination implements LoggerAwareInterface {
    use ConfigurableTrait, LoggerAwareTrait;

    public function importKnowledgeBases(iterable $rows): void {
        foreach ($rows as $row) {
            $this->logger->info("importing kb: {name}", $row);
        }
    }

    public function importCategories(iterable $row): void {

    }

    public function importArticles(iterable $row): void {

    }
}
