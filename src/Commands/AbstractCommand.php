<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Commands;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

abstract class AbstractCommand implements LoggerAwareInterface {
    use LoggerAwareTrait;

    public abstract function run();


}
