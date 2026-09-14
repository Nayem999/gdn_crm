<?php

namespace App\Domain\Meta;

/**
 * The Graph API version, and the only thing allowed to decide it.
 *
 * A version is not decoration: Meta changes field names and response shapes
 * between them and retires old ones outright, so a call made against a version
 * nobody chose is a call that will fail on a date nobody wrote down.
 *
 * The shape is checked rather than trusted. `21.0`, `v21`, `latest` and an empty
 * string are all things somebody will type into the settings form, and each of
 * them would produce a URL Meta answers with an error that says nothing about
 * the real cause.
 */
final class MetaApiVersion
{
    /**
     * `v` then major.minor, which is every version Meta has ever published.
     */
    public const PATTERN = '/^v\d{1,3}\.\d{1,2}$/';

    /**
     * The validation rule the settings field uses, so the form refuses what
     * this class would.
     */
    public const RULE = 'regex:'.self::PATTERN;

    public static function isValid(?string $version): bool
    {
        return $version !== null && preg_match(self::PATTERN, $version) === 1;
    }

    /**
     * The configured version, or the packaged default when what is configured
     * is not a version at all.
     */
    public static function resolve(?string $configured = null): string
    {
        if (self::isValid($configured)) {
            return (string) $configured;
        }

        $fallback = config('meta.graph_version');

        return self::isValid(is_string($fallback) ? $fallback : null)
            ? (string) $fallback
            : 'v21.0';
    }

    /**
     * The base every Graph call is built on, with no trailing slash.
     */
    public static function baseUrl(?string $configured = null): string
    {
        $host = config('meta.graph_url');

        return rtrim(is_string($host) && $host !== '' ? $host : 'https://graph.facebook.com', '/')
            .'/'.self::resolve($configured);
    }
}
