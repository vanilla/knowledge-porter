<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpRequest;
use Garden\Http\HttpResponse;
use Psr\SimpleCache\CacheInterface;

class HttpCacheMiddleware {
    const NO_CACHE = '-1';

    /**
     * @var CacheInterface
     */
    private $cache;

    public function __construct(CacheInterface $cache) {
        $this->cache = $cache;
    }

    private function makeCacheKey(HttpRequest $request) {
        $cacheKey = $request->getUrl();
        $cacheKey = sha1($cacheKey);

        return $cacheKey;
    }

    public function __invoke(HttpRequest $request, callable $next): HttpResponse {
        $ttl = (int)$request->getHeader('X-Cache');

        // Only cache GET requests.
        if ($request->getMethod() !== HttpRequest::METHOD_GET || $ttl < 0) { // || !$request->getHeader('X-Cache')) {
            return $next($request);
        }

        $cacheKey = $this->makeCacheKey($request);

        if (!$this->cache->has($cacheKey)) {
            /* @var HttpResponse $response */
            $response = $next($request);

            if ($ttl === 1 || $ttl === 0) {
                $ttl = strtotime('12 hours', 0);
            }

            if ($response->isSuccessful()) {
                $r = $this->cache->set($cacheKey, $response, $ttl);
            }
        } else {
            $response = $this->cache->get($cacheKey);
        }

        return $response;
    }
}
