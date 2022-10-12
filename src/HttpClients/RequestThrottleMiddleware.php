<?php
/**
 * @author Olivier Lamy-Canuel <olamy-canuel@higherlogic.com>
 * @copyright 2009-2022 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpRequest;
use Garden\Http\HttpResponse;

/**
 * A middleware that will handle API rate limits for ZenDesk and retry after the cooldown period.
 */
class RequestThrottleMiddleware
{
    protected $lastRequestTime = null;

    /**
     * Make sure at least one second has past between each call.
     *
     * @param HttpRequest $request
     * @param callable $next
     * @return HttpResponse
     */
    public function __invoke(HttpRequest $request, callable $next): HttpResponse
    {
        $minRequestMicroTime = 1000 * 1000;
        $this->lastRequestMicrotime =
            $this->lastRequestMicrotime ?? microtime(true);
        $diff = microtime(true) - $this->lastRequestMicrotime;
        if ($diff < $minRequestMicroTime) {
            usleep($minRequestMicroTime - $diff);
        }
        $this->lastRequestMicrotime = microtime(true);
        return $next($request);
    }
}
