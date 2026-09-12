<?php

namespace App\Domain\Mail\Webhooks;

use App\Domain\Mail\Enums\EmailEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Brevo posts one event at a time and does not sign it.
 */
class BrevoWebhook implements MailWebhook
{
    public function provider(): string
    {
        return 'brevo';
    }

    public function parse(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $type = match (is_string($payload['event'] ?? null) ? $payload['event'] : '') {
            'delivered' => EmailEventType::Delivered,
            'opened', 'unique_opened' => EmailEventType::Opened,
            'click' => EmailEventType::Clicked,
            'hard_bounce', 'blocked', 'invalid_email' => EmailEventType::Bounced,
            'soft_bounce', 'deferred', 'error' => EmailEventType::Failed,
            'spam' => EmailEventType::Complained,
            default => null,
        };

        $messageId = $payload['message-id'] ?? null;

        if ($type === null || ! is_string($messageId) || $messageId === '') {
            return [];
        }

        return [new EmailEventData(
            messageId: trim($messageId, '<>'),
            type: $type,
            occurredAt: $this->momentOf($payload),
            url: is_string($payload['link'] ?? null) ? $payload['link'] : null,
            reason: is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
            payload: $payload,
            recipient: is_string($payload['email'] ?? null) ? $payload['email'] : null,
        )];
    }

    public function verify(Request $request, array $credentials): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function momentOf(array $payload): Carbon
    {
        if (is_numeric($payload['ts_event'] ?? null)) {
            return Carbon::createFromTimestamp((float) $payload['ts_event']);
        }

        if (is_string($payload['date'] ?? null) && $payload['date'] !== '') {
            return Carbon::parse($payload['date']);
        }

        return Carbon::now();
    }
}
