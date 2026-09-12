<?php

namespace App\Domain\Mail\Webhooks;

use App\Domain\Mail\Enums\EmailEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SendGrid posts a batch of events, and signs it with ECDSA when the signed
 * event webhook is switched on.
 *
 * The id it reports is not quite the one it gave us: `sg_message_id` is the
 * X-Message-Id with a routing suffix appended after a full stop. Matching on
 * the whole string finds nothing, which is a quiet and extremely confusing way
 * for a delivery log to stay empty.
 */
class SendGridWebhook implements MailWebhook
{
    public function provider(): string
    {
        return 'sendgrid';
    }

    public function parse(Request $request): array
    {
        $events = [];

        foreach ($request->json()->all() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = match (is_string($entry['event'] ?? null) ? $entry['event'] : '') {
                'delivered' => EmailEventType::Delivered,
                'open' => EmailEventType::Opened,
                'click' => EmailEventType::Clicked,
                'bounce', 'blocked' => EmailEventType::Bounced,
                'dropped' => EmailEventType::Failed,
                'spamreport' => EmailEventType::Complained,
                default => null,
            };

            $id = $this->messageIdOf($entry);

            if ($type === null || $id === null) {
                continue;
            }

            $events[] = new EmailEventData(
                messageId: $id,
                type: $type,
                occurredAt: Carbon::createFromTimestamp((float) ($entry['timestamp'] ?? Carbon::now()->getTimestamp())),
                url: is_string($entry['url'] ?? null) ? $entry['url'] : null,
                reason: is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
                payload: $entry,
                recipient: is_string($entry['email'] ?? null) ? $entry['email'] : null,
            );
        }

        return $events;
    }

    public function verify(Request $request, array $credentials): bool
    {
        $key = $credentials['sendgrid_webhook_key'] ?? null;

        if (! is_string($key) || $key === '') {
            return true;
        }

        $signature = $request->header('X-Twilio-Email-Event-Webhook-Signature');
        $timestamp = $request->header('X-Twilio-Email-Event-Webhook-Timestamp');

        if (! is_string($signature) || ! is_string($timestamp)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public(
            "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n")."-----END PUBLIC KEY-----\n"
        );

        if ($publicKey === false) {
            return false;
        }

        // The signed payload is the timestamp followed by the raw body, so the
        // body has to be the bytes that arrived — re-encoding the parsed JSON
        // would change the whitespace and fail every time.
        return openssl_verify(
            $timestamp.$request->getContent(),
            (string) base64_decode($signature, true),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function messageIdOf(array $entry): ?string
    {
        $id = $entry['sg_message_id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $dot = strpos($id, '.');

        return $dot === false ? $id : substr($id, 0, $dot);
    }
}
