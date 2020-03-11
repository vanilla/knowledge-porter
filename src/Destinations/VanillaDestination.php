<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Destinations;


use Vanilla\KnowledgePorter\HttpClients\NotFoundException;
use Vanilla\KnowledgePorter\HttpClients\VanillaClient;

class VanillaDestination extends AbstractDestination {

    /**
     * @var VanillaClient
     */
    private $vanillaApi;


    public function __construct(VanillaClient $vanillaApi) {
        $this->vanillaApi = $vanillaApi;
    }

    /**
     * @param iterable $rows
     */
    public function importKnowledgeBases(iterable $rows): void {
        foreach ($rows as $row) {
            try {
                $existing = $this->vanillaApi->getKnowledgeBaseBySmartID($row["foreignID"]);
                $this->vanillaApi->patch('/api/v2/knowledge-bases/'.$existing['knowledgeBaseID'], $row);
            } catch (NotFoundException $ex) {
                $this->vanillaApi->post('/api/v2/knowledge-bases', $row);
            }

            return;
        }
    }

    public function importKnowledgeCategories(iterable $rows): void {
        foreach ($rows as $row) {
            try {
                $existing = $this->vanillaApi->getKnowledgeCategoryBySmartID($row["foreignID"]);
                $this->vanillaApi->patch('/api/v2/knowledge-categories/'.$existing['knowledgeBaseID'], $row);
            } catch (NotFoundException $ex) {
                $this->vanillaApi->post('/api/v2/knowledge-categories', $row);
            }

            return;
        }
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config): void {
        $this->config = $config;

        $this->vanillaApi->setToken($this->config['token']);
        $this->vanillaApi->setBaseUrl($this->config['baseUrl']);
    }
}
