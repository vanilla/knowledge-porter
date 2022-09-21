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
class RateLimiterMiddleware
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
        $this->lastRequestTime = $this->lastRequestTime ?? time();

        while (time() < $this->lastRequestTime + 1) {
            sleep(1);
        }

        $this->lastRequestTime = time();

        return $next($request);
    }
}
