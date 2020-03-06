<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Cli\TaskLogger;
use Garden\Http\HttpClient;
use Garden\Http\HttpRequest;
use Garden\Http\HttpResponse;
use Psr\Log\LogLevel;

class HttpLogMiddleware {

    /**
     * @var TaskLogger
     */
    private $logger;

    public function __construct(TaskLogger $logger) {
        $this->logger = $logger;
    }

    public function __invoke(HttpRequest $request, callable $next): HttpResponse {
        if ($request->getHeader('X-Log') === 'off') {
            return $next($request);
        }

        try {
            $this->logger->begin($request->getHeader('X-LogLevel') ?: LogLevel::INFO, "{method} {url}", [
                'method' => $request->getMethod(),
                'url' => $request->getUrl()
            ]);
            /* @var HttpResponse $response */
            $response = $next($request);
            $this->logger->endHttpStatus($response->getStatusCode());
            return $response;
        } catch (\Exception $ex) {
            $this->logger->endError($ex->getMessage());
            throw $ex;
        }
    }

    /**
     * Turn off/on logging for an HTTP request.
     *
     * @param HttpRequest $request
     * @param bool $log
     */
    public static function setLogRequest(HttpRequest $request, bool $log): void {
        $request->setHeader('X-Log', $log ? '' : 'off');
    }

    /**
     * Turn off/on logging for an HTTP client.
     *
     * @param HttpClient $client
     * @param bool $log
     */
    public static function setLogClient(HttpClient $client, bool $log): void {
        $client->setDefaultHeader('X-Log', $log ? '' : 'off');
    }
}
