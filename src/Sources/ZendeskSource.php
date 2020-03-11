<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\Sources;

use Vanilla\KnowledgePorter\HttpClients\ZendeskClient;

class ZendeskSource extends AbstractSource {
    /**
     * @var ZendeskClient
     */
    private $zendesk;

    public function __construct(ZendeskClient $zendesk) {
        $this->zendesk = $zendesk;
    }

    public function setBasePath(string $basePath) {
        $this->basePath = $basePath;
    }

    /**
     * Execute import content actions
     */
    public function import(): void {
        $kbIDs = $this->processKnowledgeBases();
        //$kbCatIDs = $this->processKnowledgeCategories($kbIDs);
        //$this->processArticles($kbCatIDs);
    }

    private function processKnowledgeBases(): array {
        $dest = $this->getDestination();
        $knowledgeBases = $this->zendesk->getCategories('en-us');

        $kbs = $this->transform($knowledgeBases, [
            'foreignID' => ['column' =>'id', 'filter' => [$this, 'addPrefix']],
            'name' => 'name',
            'description' => 'description',
            'urlCode' => ['column' => 'html_url', 'filter' => [$this, 'extractUrlSlug']],
            'sourceLocale' => 'source_locale',
        ]);

        $dest->importKnowledgeBases($kbs);
        $array = [];
        array_push($array, ...$kbs);
        return array_column($array, 'foreignID');
    }

    protected function addPrefix($str): string {
        $newStr = $this->config["prefix"].$str;
        return $newStr;
    }

    /**
 * @param $str
 * @return string
 */
    protected function extractUrlSlug($str): string {
        $pathInfo = pathinfo($str);
        $slug = $pathInfo['basename'] ?? null;
        $urlCode = $this->config["prefix"].$slug;
        return $urlCode;
    }

    /**
     * @param $str
     * @return string
     */
    protected function getSourceLocale($str): string {
        $locale = $str;
        return $locale;
    }

    public function setConfig(array $config): void {
        $this->config = $config;
        $this->zendesk->setToken($this->config['token']);
        $this->zendesk->setBaseUrl($this->config['baseUrl']);
    }
}
