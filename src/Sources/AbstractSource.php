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

    /** @var array $params */
    protected $params;

    /**
     * @return AbstractDestination
     */
    public function getDestination(): AbstractDestination {
        return $this->destination;
    }

    /**
     * @param array $params
     */
    public function setParams(array $params) {
        $this->params = $params;
    }

    /**
     * @param AbstractDestination $destination
     */
    public function setDestination(AbstractDestination $destination): void {
        $this->destination = $destination;
    }

    public abstract function import(): void;

    public function transform(iterable $rows, array $transformer): iterable {
        foreach ($rows as $row) {
            yield $this->transformRow($row, $transformer);
        }
    }

    protected function transformRow($row, array $tranformer) {
        $result = [];
        foreach ($tranformer as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $row[$value];
            } elseif (is_array($value)) {
                $column = $value['column'];
                $rowValue = $row[$column];
                if (isset($value['filter'])) {
                    $rowValue = call_user_func($value['filter'], $rowValue);
                }
                $result[$key] = $rowValue;
            }
        }
        return $result;
    }
}
