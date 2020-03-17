<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter;

use Garden\Cli\TaskLogger;

/**
 * Basic Implementation of LoggerAwareInterface.
 */
trait TaskLoggerAwareTrait {
    /**
     * The logger instance.
     *
     * @var TaskLogger
     */
    protected $logger;

    /**
     * Sets a logger.
     *
     * @param TaskLogger $logger
     */
    public function setLogger(TaskLogger $logger) {
        $this->logger = $logger;
    }
}
