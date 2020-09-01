<?php

namespace Vanilla\KnowledgePorter\Utils;

use Garden\Http\HttpClient;
use IteratorAggregate;


/**
 * An iterator the fetches API results and iterates using it's pagination headers.
 */
class ApiPaginationIterator implements IteratorAggregate {

    /** @var HttpClient */
    private $httpClient;

    /** @var string */
    private $initialUrl;

    /** @var string|null */
    private $currentUrl;

    /**
     * Constructor.
     *
     * @param HttpClient $httpClient
     * @param string $initialUrl
     */
    public function __construct(HttpClient $httpClient, string $initialUrl) {
        $this->httpClient = $httpClient;
        $this->initialUrl = $initialUrl;
        $this->currentUrl = $initialUrl;
    }

    /**
     * Internal generator function. This is our iterator.
     *
     * @return \Generator
     */
    protected function internalGenerator(): \Generator {
        if ($this->currentUrl === null) {
            $this->currentUrl = $this->initialUrl;
        }

        while ($this->currentUrl !== null) {
            $result = $this->httpClient->get($this->currentUrl);
            $body = $result->getBody();
            yield $body;

            $nextPage = $body['next_page'] ?? false;
            if ($nextPage) {
                $next = $nextPage;
            } else {
                $linkHeaders = WebLinking::parseLinkHeaders($result->getHeader(WebLinking::HEADER_NAME));
                $next = $linkHeaders['next'] ?? null;
            }

            $this->currentUrl = $next;
        }
    }

    /**
     * @inheritdoc
     */
    public function getIterator() {
        return $this->internalGenerator();
    }
}
