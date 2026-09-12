<?php

namespace App\Domain\Mail\Webhooks;

use App\Domain\Mail\Enums\EmailEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Postmark posts one event at a time and does not sign it — its own advice is
 * to keep the URL secret, which is what the token in the path is for.
 */
class PostmarkWebhook implements MailWebhook
{
    public function provider(): string
    {
        return 'postmark';
    }

    public function parse(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $record = is_string($payload['RecordType'] ?? null) ? $payload['RecordType'] : '';

        $type = match ($record) {
            'Delivery' => EmailEventType::Delivered,
            'Open' => EmailEventType::Opened,
            'Click' => EmailEventType::Clicked,
            'SpamComplaint' => EmailEventType::Complained,
            // A soft bounce is a mailbox that was full, not an address that
            // does not exist, and treating the two the same is how a live
            // customer gets suppressed.
            'Bounce' => ($payload['Type'] ?? null) === 'SoftBounce' ? EmailEventType::Failed : EmailEventType::Bounced,
            default => null,
        };

        $messageId = $payload['MessageID'] ?? null;

        if ($type === null || ! is_string($messageId) || $messageId === '') {
            return [];
        }

        return [new EmailEventData(
            messageId: $messageId,
            type: $type,
            occurredAt: $this->momentOf($payload),
            url: is_string($payload['OriginalLink'] ?? null) ? $payload['OriginalLink'] : null,
            reason: is_string($payload['Description'] ?? null) ? $payload['Description'] : null,
            payload: $payload,
            recipient: $this->recipientOf($payload),
        )];
    }

    public function verify(Request $request, array $credentials): bool
    {
        return true;
    }

    /**
     * Postmark names the recipient differently depending on the record type.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recipientOf(array $payload): ?string
    {
        foreach (['Recipient', 'Email'] as $key) {
            if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function momentOf(array $payload): Carbon
    {
        foreach (['DeliveredAt', 'BouncedAt', 'ReceivedAt'] as $key) {
            if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
                return Carbon::parse($payload[$key]);
            }
        }

        return Carbon::now();
    }
}
