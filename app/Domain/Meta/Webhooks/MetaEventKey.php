<?php

namespace App\Domain\Meta\Webhooks;

use App\Domain\Ingestion\PayloadReader;
use App\Domain\Meta\Enums\MetaChannel;

/**
 * One delivery's identity, for refusing the same one twice.
 *
 * Meta retries anything it did not hear a 200 for, and its retries carry the
 * same ids — so without this, one lead-ads submission becomes three leads and
 * one WhatsApp message becomes three conversations. Meta does not send a
 * delivery id of its own, so the key is built from what is inside the payload:
 *
 *   - a lead-ads event has a `leadgen_id`, which is the lead itself;
 *   - a message has an `id`, which is that message;
 *   - a status change has a message id plus the status it changed to, because
 *     the same message legitimately reports sent, then delivered, then read.
 *
 * When none of those is present the key is a hash of the whole body. That is
 * deliberately conservative: it makes a genuine repeat of an identical payload
 * look like a duplicate, which for a webhook that carries no id is the right
 * guess — two byte-identical deliveries a moment apart are Meta retrying, not a
 * customer doing the same thing twice.
 */
final class MetaEventKey
{
    public static function for(MetaChannel $channel, string $body): ?string
    {
        $payload = PayloadReader::decode($body);

        if ($payload === null) {
            return null;
        }

        $identity = match ($channel) {
            MetaChannel::LeadGen => self::leadGen($payload),
            MetaChannel::Messenger => self::messenger($payload),
            MetaChannel::WhatsApp => self::whatsApp($payload),
        };

        return $identity ?? 'body:'.hash('sha256', $body);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function leadGen(array $payload): ?string
    {
        foreach (self::changes($payload) as $change) {
            $value = is_array($change['value'] ?? null) ? $change['value'] : [];

            $id = $value['leadgen_id'] ?? null;

            if (is_string($id) || is_int($id)) {
                return 'leadgen:'.$id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function messenger(array $payload): ?string
    {
        foreach (self::entries($payload) as $entry) {
            $messaging = is_array($entry['messaging'] ?? null) ? $entry['messaging'] : [];

            foreach ($messaging as $item) {
                $message = is_array($item) && is_array($item['message'] ?? null) ? $item['message'] : [];
                $id = $message['mid'] ?? null;

                if (is_string($id) && $id !== '') {
                    return 'mid:'.$id;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function whatsApp(array $payload): ?string
    {
        foreach (self::changes($payload) as $change) {
            $value = is_array($change['value'] ?? null) ? $change['value'] : [];

            foreach (is_array($value['messages'] ?? null) ? $value['messages'] : [] as $message) {
                $id = is_array($message) ? ($message['id'] ?? null) : null;

                if (is_string($id) && $id !== '') {
                    return 'wamid:'.$id;
                }
            }

            // A status is not the message: the same message reports sent, then
            // delivered, then read, and all three are deliveries worth keeping.
            foreach (is_array($value['statuses'] ?? null) ? $value['statuses'] : [] as $status) {
                $id = is_array($status) ? ($status['id'] ?? null) : null;
                $state = is_array($status) ? ($status['status'] ?? null) : null;

                if (is_string($id) && $id !== '') {
                    return 'wastatus:'.$id.':'.(is_string($state) ? $state : 'unknown');
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private static function entries(array $payload): array
    {
        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

        return array_values(array_filter($entries, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private static function changes(array $payload): array
    {
        $changes = [];

        foreach (self::entries($payload) as $entry) {
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if (is_array($change)) {
                    $changes[] = $change;
                }
            }
        }

        return $changes;
    }
}
