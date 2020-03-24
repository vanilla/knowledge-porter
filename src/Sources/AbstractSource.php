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

/**
 * Class AbstractSource
 * @package Vanilla\KnowledgePorter\Sources
 */
abstract class AbstractSource implements LoggerAwareInterface {
    use LoggerAwareTrait, ConfigurableTrait;

    /**
     * @var AbstractDestination
     */
    private $destination;

    /** @var array $params */
    protected $params;

    /**
     * Get command destination
     *
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

    /**
     * Import data from source to destination
     */
    abstract public function import(): void;

    /**
     * Apply transformation rules to row set.
     *
     * @param iterable $rows
     * @param array $transformer
     * @return iterable
     */
    public function transform(iterable $rows, array $transformer): iterable {
        foreach ($rows as $row) {
            yield $this->transformRow($row, $transformer);
        }
    }

    /**
     * Apply transformation rules to row.
     *
     * @param array $row
     * @param array $tranformer
     * @return array
     */
    protected function transformRow($row, array $tranformer) {
        $result = [];
        foreach ($tranformer as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $row[$value];
            } elseif (is_array($value)) {
                if (array_key_exists('placeholder', $value)) {
                    $result[$key] = $value['placeholder'];
                } else {
                    $column = $value['column'];
                    $rowValue = $row[$column];
                    if (isset($value['filter'])) {
                        $rowValue = call_user_func($value['filter'], $rowValue, $row);
                    }
                    $result[$key] = $rowValue;
                }
            }
        }
        return $result;
    }
}
