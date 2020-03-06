<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Vanilla\KnowledgePorter\ConfigurableTrait;
use Vanilla\KnowledgePorter\Destinations\AbstractDestination;

abstract class AbstractSource implements LoggerAwareInterface {
    use LoggerAwareTrait, ConfigurableTrait;

    /**
     * @var AbstractDestination
     */
    private $destination;

    /**
     * @return AbstractDestination
     */
    public function getDestination(): AbstractDestination {
        return $this->destination;
    }

    /**
     * @param AbstractDestination $destination
     */
    public function setDestination(AbstractDestination $destination): void {
        $this->destination = $destination;
    }

    public abstract function import(): void;
}
