<?php

namespace App\Domain\Mail\Webhooks;

use App\Domain\Mail\Enums\EmailEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Mailgun posts one event at a time, with its own HMAC signature.
 */
class MailgunWebhook implements MailWebhook
{
    public function provider(): string
    {
        return 'mailgun';
    }

    public function parse(Request $request): array
    {
        $data = $request->input('event-data');

        if (! is_array($data)) {
            return [];
        }

        $type = $this->typeOf(
            is_string($data['event'] ?? null) ? $data['event'] : '',
            is_string($data['severity'] ?? null) ? $data['severity'] : null,
        );

        if ($type === null) {
            return [];
        }

        $messageId = $data['message']['headers']['message-id'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            return [];
        }

        return [new EmailEventData(
            messageId: trim($messageId, '<>'),
            type: $type,
            occurredAt: Carbon::createFromTimestamp((float) ($data['timestamp'] ?? Carbon::now()->getTimestamp())),
            url: is_string($data['url'] ?? null) ? $data['url'] : null,
            reason: $this->reasonOf($data),
            payload: $data,
            recipient: is_string($data['recipient'] ?? null) ? $data['recipient'] : null,
        )];
    }

    public function verify(Request $request, array $credentials): bool
    {
        $key = $credentials['mailgun_webhook_key'] ?? null;

        // No signing key configured: the URL token is what stands behind this,
        // and saying so is better than returning true as though something had
        // been checked.
        if (! is_string($key) || $key === '') {
            return true;
        }

        $signature = $request->input('signature');

        if (! is_array($signature)) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            (string) ($signature['timestamp'] ?? '').(string) ($signature['token'] ?? ''),
            $key
        );

        return hash_equals($expected, (string) ($signature['signature'] ?? ''));
    }

    /**
     * Mailgun reports every failure as "failed" and puts the difference that
     * matters in the severity: permanent is a dead address, temporary is a
     * mailbox that was full an hour ago.
     */
    private function typeOf(string $event, ?string $severity): ?EmailEventType
    {
        return match ($event) {
            'delivered' => EmailEventType::Delivered,
            'opened' => EmailEventType::Opened,
            'clicked' => EmailEventType::Clicked,
            'complained' => EmailEventType::Complained,
            'rejected' => EmailEventType::Failed,
            'failed' => $severity === 'permanent' ? EmailEventType::Bounced : EmailEventType::Failed,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reasonOf(array $data): ?string
    {
        foreach ([$data['delivery-status']['message'] ?? null, $data['delivery-status']['description'] ?? null, $data['reason'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
