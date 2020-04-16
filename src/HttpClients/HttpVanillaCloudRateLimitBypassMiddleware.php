<?php
/**
 * @author Eduardo Garcia Julia <eduardo.garciajulia@vanillaforums.com>
 * @license Proprietary
 * @copyright 2009-2020 Vanilla Forums Inc.
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpRequest;
use Garden\Http\HttpResponse;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * A middleware that will Bypass Rate Limiting for Vanilla Infrastructure
 */
class HttpVanillaCloudRateLimitBypassMiddleware {

    /* @var string */
    protected $bypassToken = null;

    /**
     * @param string $bypassToken
     * @return HttpVanillaCloudRateLimitBypassMiddleware
     */
    public function setBypassToken($bypassToken) {
        $this->bypassToken = $bypassToken;

        return $this;
    }

    /**
     * Call the next resolver, but handle API rate limits.
     *
     * @param HttpRequest $request
     * @param callable $next
     * @return HttpResponse
     */
    public function __invoke(HttpRequest $request, callable $next): HttpResponse {
        if ($this->bypassToken !== null) {
            $cookie = $request->getHeader("Cookie");
            if (!isset($cookie)) {
                $cookie = '';
            }
            // Note: the space after the semicolon is part of the Standard
            $cookie = "vanilla_ratelimit_bypass={$this->bypassToken}; {$cookie}";
            $request->setHeader("Cookie", $cookie);
        }

        /** @var HttpResponse $response */
        $response = $next($request);

        return $response;
    }
}
