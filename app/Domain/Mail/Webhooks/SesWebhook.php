<?php

namespace App\Domain\Mail\Webhooks;

use App\Domain\Mail\Enums\EmailEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SES reports through SNS, which wraps the real payload in a JSON string.
 *
 * Two details are worth knowing:
 *
 * **The message id to match on is ours, not theirs.** SES assigns its own id,
 * but we send over SMTP, so the id we recorded is the Message-ID header we
 * generated — which SNS reports as `mail.commonHeaders.messageId`. Matching on
 * `mail.messageId` finds nothing.
 *
 * **Subscription confirmation is not automated here.** SNS asks an endpoint to
 * confirm by fetching a URL it supplies, and fetching a URL because a request
 * told us to is exactly the shape of a server-side request forgery. An
 * administrator confirms the subscription from the SNS console instead; the
 * confirmation request is acknowledged and ignored.
 */
class SesWebhook implements MailWebhook
{
    public function provider(): string
    {
        return 'ses';
    }

    public function parse(Request $request): array
    {
        /** @var array<string, mixed> $envelope */
        $envelope = $request->all();

        $body = $envelope['Message'] ?? null;

        if (! is_string($body)) {
            return [];
        }

        $notification = json_decode($body, true);

        if (! is_array($notification)) {
            return [];
        }

        $kind = $notification['eventType'] ?? $notification['notificationType'] ?? null;

        $type = match (is_string($kind) ? $kind : '') {
            'Delivery' => EmailEventType::Delivered,
            'Open' => EmailEventType::Opened,
            'Click' => EmailEventType::Clicked,
            'Complaint' => EmailEventType::Complained,
            'Bounce' => ($notification['bounce']['bounceType'] ?? null) === 'Transient'
                ? EmailEventType::Failed
                : EmailEventType::Bounced,
            default => null,
        };

        $messageId = $notification['mail']['commonHeaders']['messageId'] ?? null;

        if ($type === null || ! is_string($messageId) || $messageId === '') {
            return [];
        }

        return [new EmailEventData(
            messageId: trim($messageId, '<>'),
            type: $type,
            occurredAt: $this->momentOf($notification),
            url: is_string($notification['click']['link'] ?? null) ? $notification['click']['link'] : null,
            reason: $this->reasonOf($notification),
            payload: $notification,
            recipient: $this->recipientOf($notification),
        )];
    }

    public function verify(Request $request, array $credentials): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function recipientOf(array $notification): ?string
    {
        $bounced = $notification['bounce']['bouncedRecipients'][0]['emailAddress'] ?? null;

        if (is_string($bounced) && $bounced !== '') {
            return $bounced;
        }

        $complained = $notification['complaint']['complainedRecipients'][0]['emailAddress'] ?? null;

        if (is_string($complained) && $complained !== '') {
            return $complained;
        }

        $delivered = $notification['delivery']['recipients'][0] ?? null;

        return is_string($delivered) && $delivered !== '' ? $delivered : null;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function momentOf(array $notification): Carbon
    {
        foreach (['delivery', 'bounce', 'complaint'] as $section) {
            $timestamp = $notification[$section]['timestamp'] ?? null;

            if (is_string($timestamp) && $timestamp !== '') {
                return Carbon::parse($timestamp);
            }
        }

        $sent = $notification['mail']['timestamp'] ?? null;

        return is_string($sent) && $sent !== '' ? Carbon::parse($sent) : Carbon::now();
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function reasonOf(array $notification): ?string
    {
        $diagnostic = $notification['bounce']['bouncedRecipients'][0]['diagnosticCode'] ?? null;

        if (is_string($diagnostic) && $diagnostic !== '') {
            return $diagnostic;
        }

        $subtype = $notification['bounce']['bounceSubType'] ?? $notification['complaint']['complaintFeedbackType'] ?? null;

        return is_string($subtype) && $subtype !== '' ? $subtype : null;
    }
}
