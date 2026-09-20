<?php

namespace App\Domain\Ingestion;

use Illuminate\Http\Request;

/**
 * What kind of thing a delivery was, in the sender's own words.
 *
 * The delivery log could already say who sent something, when, and whether it
 * worked. It could not say **what it was**, and that is the column somebody
 * actually scans: a page of thirty identical rows is unreadable when twenty-six
 * of them are delivery receipts and the four that matter are incoming messages.
 *
 * Answered from the payload rather than configured per source, because every
 * sender already says this and none of them say it the same way. Meta puts it
 * in `entry[].changes[].field`; a plain JSON webhook usually has a top-level
 * `event` or `type`; GitHub and Shopify put it in a header and nowhere else.
 *
 * **The sender's word is copied, not translated.** "messages" is not renamed to
 * "Message received", and an unrecognised name is kept as it was sent. Somebody
 * comparing this screen against Meta's own webhook page has to see the same
 * string on both, and a friendlier vocabulary is one that matches nothing in
 * their documentation.
 */
final class WebhookEventName
{
    /**
     * Matches the column width. A name longer than this is not a name.
     */
    public const MAX = 64;

    /**
     * The headers senders announce an event type in.
     *
     * Kept in the order they are checked, most specific first. A vendor header
     * beats the body: where both exist they agree, and where they disagree the
     * header is the one the vendor's own documentation talks about.
     *
     * @var array<int, string>
     */
    public const HEADERS = [
        'x-github-event',
        'x-shopify-topic',
        'x-gitlab-event',
        'x-event-type',
        'x-webhook-event',
        'x-event-name',
    ];

    /**
     * The body keys a JSON webhook names its event in.
     *
     * `object` is last and deliberately so: Meta sends `"object": "page"`, which
     * is the thing the event is about rather than the event, and it is only
     * worth using when the more specific shapes below found nothing.
     *
     * @var array<int, string>
     */
    private const KEYS = ['event', 'event_type', 'eventType', 'type', 'topic', 'action', 'object'];

    /**
     * From a live request: a header if the sender used one, else the body.
     */
    public static function for(Request $request, string $body): ?string
    {
        foreach (self::HEADERS as $header) {
            $value = $request->header($header);

            if (is_string($value) && trim($value) !== '') {
                return self::clean($value);
            }
        }

        return self::fromPayload($body);
    }

    /**
     * From the stored bytes alone.
     *
     * Its own entry point because the log has rows that predate this column, and
     * because a replay re-reads what was stored rather than what arrived.
     */
    public static function fromPayload(?string $body): ?string
    {
        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        return self::fromMeta($decoded) ?? self::fromCommonKeys($decoded);
    }

    /**
     * Meta's shape, which nothing else shares.
     *
     * Two arrangements under one envelope. WhatsApp, Lead Ads and page comments
     * send `entry[].changes[]`, each change naming its own `field` — `messages`,
     * `leadgen`, `feed`. Messenger instead sends `entry[].messaging[]`, where
     * the kind is the key inside the item: `message`, `postback`, `delivery`,
     * `read`. Both are read here because both arrive at the same endpoint.
     *
     * @param  array<mixed>  $payload
     */
    private static function fromMeta(array $payload): ?string
    {
        $entries = $payload['entry'] ?? null;

        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $changes = $entry['changes'] ?? null;

            if (is_array($changes)) {
                foreach ($changes as $change) {
                    if (is_array($change) && isset($change['field']) && is_string($change['field'])) {
                        return self::clean($change['field']);
                    }
                }
            }

            $messaging = $entry['messaging'] ?? null;

            if (is_array($messaging)) {
                foreach ($messaging as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    // The kind is whichever key is present beside the routing
                    // ones, so the routing ones are what gets skipped.
                    foreach ($item as $key => $value) {
                        if (is_string($key) && ! in_array($key, ['sender', 'recipient', 'timestamp'], true)) {
                            return self::clean($key);
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function fromCommonKeys(array $payload): ?string
    {
        foreach (self::KEYS as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return self::clean($value);
            }
        }

        return null;
    }

    /**
     * A name fit to put in a column and a filter.
     *
     * Control characters are stripped rather than escaped: this string is
     * written by whoever sent the request, and it ends up in a table, an export
     * and a filter dropdown. Blade escapes it too — this is the layer that keeps
     * a newline from breaking a CSV row.
     */
    private static function clean(string $value): ?string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX);
    }
}
