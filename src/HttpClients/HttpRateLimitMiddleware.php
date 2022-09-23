<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpRequest;
use Garden\Http\HttpResponse;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * A middleware that will handle API rate limits for ZenDesk and retry after the cooldown period.
 */
class HttpRateLimitMiddleware implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Call the next resolver, but handle API rate limits.
     *
     * @param HttpRequest $request
     * @param callable $next
     * @return HttpResponse
     */
    public function __invoke(HttpRequest $request, callable $next): HttpResponse
    {
        /** @var HttpResponse $response */
        $response = $next($request);

        if (
            $response->getStatusCode() === 429 &&
            $response->hasHeader("Retry-After")
        ) {
            $sleep = (int) $response->getHeader("Retry-After") + 1;
            $this->logger->info(
                "Rate limit reached, sleeping for {sleep} second(s).",
                ["sleep" => $sleep]
            );
            sleep(max($sleep, 1));
            $response = $next($request);
        }
        return $response;
    }
}
