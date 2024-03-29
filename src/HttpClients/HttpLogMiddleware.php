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

/**
 * Class HttpLogMiddleware
 *
 * @package Vanilla\KnowledgePorter\HttpClients
 */
class HttpLogMiddleware {

    /**
     * @var TaskLogger
     */
    private $logger;

    /** @var bool */
    private $logBodies = false;

    /**
     * HttpLogMiddleware constructor.
     *
     * @param TaskLogger $logger
     */
    public function __construct(TaskLogger $logger) {
        $this->logger = $logger;
    }

    /**
     * @param bool $logBodies
     */
    public function setLogBodies(bool $logBodies) {
        $this->logBodies = $logBodies;
    }

    /**
     * Invoke logger to request
     *
     * @param HttpRequest $request
     * @param callable $next
     * @return HttpResponse
     * @throws \Exception Throws an Exception if http request fail.
     */
    public function __invoke(HttpRequest $request, callable $next): HttpResponse {
        if ($request->getHeader('X-Log') === 'off') {
            return $next($request);
        }

        try {
            $this->logger->begin($request->getHeader('X-LogLevel') ?: LogLevel::INFO, "`{method}` {url}", [
                'method' => $request->getMethod(),
                'url' => urldecode($request->getUrl()),
            ]);
            if ($this->logBodies) {
                $body = $request->getBody();
                if ($body) {
                    // Some requests contain an HTML body.
                    // This clutters the logs significantly, and is normally of little use for debugging.
                    // We replace it with a placeholder.
                    if (isset($body['body'])) {
                        $body['body'] = '<BODY />';
                    }
                    $this->logger->info(json_encode($body, JSON_PRETTY_PRINT));
                }
            }
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
