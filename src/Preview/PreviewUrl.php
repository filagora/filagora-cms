<?php

declare(strict_types=1);

namespace Filagora\Cms\Preview;

/**
 * Puts a preview link's token into an address, and tells whether an address already has one.
 */
final class PreviewUrl
{
    public static function parameter(): string
    {
        return (string) config('filagora-cms.preview.query_parameter', 'preview');
    }

    /**
     * The address with the token in its query, ahead of any fragment. An address that already
     * carries the parameter is returned as it is.
     */
    public static function with(string $url, string $token): string
    {
        if (self::has($url)) {
            return $url;
        }

        $fragment = '';
        $hash = strpos($url, '#');

        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').rawurlencode(self::parameter()).'='.rawurlencode($token).$fragment;
    }

    public static function has(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return false;
        }

        parse_str(html_entity_decode($query), $parameters);

        return array_key_exists(self::parameter(), $parameters);
    }
}
