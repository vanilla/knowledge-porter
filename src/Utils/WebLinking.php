<?php

namespace Vanilla\KnowledgePorter\Utils;


class WebLinking {

    const WEB_LINK_REGEX = '/<(?<link>[0-9a-zA-Z$-_.+!*\'(),:?=&%#]+)>;\s+rel="(?<rel>next|prev)"/i';
    const HEADER_NAME = 'Link';
    /**
     * Parse a link
     *
     * @param string $header The link header value.
     *
     * @return array
     * @example
     * [
     *     'previous' => 'https://something.com/page/1
     *     'next' => 'https://something.com/page/3
     * ]
     */
    public static function parseLinkHeaders(string $header): array {
        $segments = explode(',', $header);
        $result = [
            'prev' => null,
            'next' => null,
        ];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            preg_match(self::WEB_LINK_REGEX, $segment, $matches);
            $link = $matches['link'] ?? null;
            $rel = $matches['rel'] ?? null;

            if (!$link) {
                // Badly formed.
                continue;
            }

            if ($rel === 'next') {
                $result['next'] = $link;
            } elseif ($rel === 'prev') {
                $result['prev'] = $link;
            }
        }

        return $result;
    }
}
