<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter;

/**
 * Trait ConfigurableTrait
 * @package Vanilla\KnowledgePorter
 */
trait ConfigurableTrait {
    /**
     * @var array
     */
    protected $config;

    /**
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config): void {
        $this->config = $config;
    }
}
