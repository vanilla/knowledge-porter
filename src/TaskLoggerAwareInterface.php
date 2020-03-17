<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter;

use Garden\Cli\TaskLogger;

/**
 * For classes that want to have a `TaskLogger` automatically set.
 */
interface TaskLoggerAwareInterface {
    /**
     * Set the logger.
     *
     * @param TaskLogger $logger
     * @return mixed
     */
    public function setLogger(TaskLogger $logger);
}
