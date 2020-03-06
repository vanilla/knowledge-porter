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

    protected function tranform(iterable $rows, array $tranformer): iterable {
        foreach ($rows as $row) {
            yield $this->transformRow($row, $tranformer);
        }
    }

    private function transformRow($row, array $tranformer) {
        $result = [];
        foreach ($tranformer as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $row[$value];
            } elseif (is_array($value)) {
                $column = $value['column'];
                $rowValue = $value[$column];
                if (isset($value['filter'])) {
                    $rowValue = call_user_func($value['filter']);
                }
                $result[$key] = $rowValue;
            }
        }
    }
}
