<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;

use Vanilla\KnowledgePorter\ConfigurableTrait;

abstract class AbstractDestination {
    use ConfigurableTrait;

    public function importKnowledgeBases(iterable $rows): void {

    }

    public function importCategories(iterable $row): void {

    }

    public function importArticles(iterable $row): void {

    }
}
