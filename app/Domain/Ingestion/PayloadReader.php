<?php

namespace App\Domain\Ingestion;

/**
 * Reads a value out of somebody else's JSON by a dotted path.
 *
 * `task.assignee.email`, `items.0.sku`. The path is **configuration**, written
 * by an administrator against a sample payload — never a value from the payload
 * itself, and never a column name. What it produces is a scalar or null; a path
 * landing on an array or an object yields null rather than something that would
 * later be cast to "Array".
 *
 * Deliberately not `data_get()`: that resolves `*` into a collection walk, and a
 * path with a star in it from a mapping screen would quietly turn one field into
 * many. This only ever walks named keys.
 */
final class PayloadReader
{
    /**
     * Decode a body into an array, or null when it is not usable.
     *
     * Associative, depth-limited, and **never** `unserialize` — the payload is
     * data from a stranger and nothing in it may become an object.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);

        if (! is_array($decoded)) {
            return null;
        }

        // A bare scalar or a top-level **list** is valid JSON but not a record,
        // and every mapping path assumes an object to walk into. `{}` decodes
        // to an empty array and is a record with no fields, which is different
        // from `[1,2,3]` and is allowed through — the mappings will simply find
        // nothing, and a required one will say so.
        return $decoded !== [] && array_is_list($decoded) ? null : $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function value(array $payload, string $path): string|int|float|bool|null
    {
        $current = $payload;

        foreach (explode('.', trim($path)) as $segment) {
            if ($segment === '' || ! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        // A branch, not a leaf. Returning it would produce "Array" in a column.
        return is_scalar($current) ? $current : null;
    }

    /**
     * Whether the path exists at all, which is a different question from
     * whether it holds anything — `{"email": null}` has an email key and no
     * email, and a filter asking "does this have an email" should say no.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function has(array $payload, string $path): bool
    {
        $current = $payload;

        foreach (explode('.', trim($path)) as $segment) {
            if ($segment === '' || ! is_array($current) || ! array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return $current !== null && $current !== '';
    }

    /**
     * Every dotted path in a payload, for the mapping screen to offer as
     * something clickable rather than something to type.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public static function paths(array $payload, string $prefix = '', int $depth = 0): array
    {
        // A sample payload is somebody else's, so it is bounded rather than
        // trusted to be shallow.
        if ($depth > 12) {
            return [];
        }

        $paths = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $paths = [...$paths, ...self::paths($value, $path, $depth + 1)];

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }
}
