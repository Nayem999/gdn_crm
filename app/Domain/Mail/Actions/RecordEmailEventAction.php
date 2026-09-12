<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Enums\EmailEventType;
use App\Domain\Mail\Models\EmailEvent;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Webhooks\EmailEventData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Apply one provider event to the message it concerns.
 *
 * Two invariants live here and nowhere else.
 *
 * **An event is recorded once.** Providers retry — Mailgun for eight hours,
 * SendGrid for a day — and every retry is a delivery a naive handler counts
 * again. The guard is a unique index on the event's fingerprint, claimed by the
 * insert itself, because checking first and inserting second is a race two
 * simultaneous retries both win.
 *
 * **The status only moves forward, and failure wins.** Webhooks arrive out of
 * order, so a "delivered" landing after an "opened" must not walk the message
 * back; and a complaint after a click is a complaint. EmailStatus::then() owns
 * that comparison.
 */
class RecordEmailEventAction
{
    /**
     * @return int How many log rows the event was applied to. Zero means a
     *             duplicate, or a message this application never sent.
     */
    public function handle(string $provider, EmailEventData $event): int
    {
        $messages = EmailMessage::query()
            ->fromProvider($provider, $event->messageId, $event->recipient)
            ->get();

        $applied = 0;

        foreach ($messages as $message) {
            if ($this->record($provider, $event, $message)) {
                $applied++;
            }
        }

        return $applied;
    }

    private function record(string $provider, EmailEventData $event, EmailMessage $message): bool
    {
        return DB::transaction(function () use ($provider, $event, $message): bool {
            try {
                EmailEvent::query()->create([
                    'email_message_id' => $message->id,
                    'type' => $event->type,
                    'occurred_at' => $event->occurredAt,
                    'url' => $event->url,
                    'reason' => $event->reason,
                    'payload' => $event->payload,
                    'signature' => $event->signature($provider, $message->to_email),
                ]);
            } catch (QueryException $failure) {
                if ($this->isDuplicate($failure)) {
                    return false;
                }

                throw $failure;
            }

            $this->apply($message, $event);

            return true;
        });
    }

    private function apply(EmailMessage $message, EmailEventData $event): void
    {
        // Locked, because two events for one message can arrive at the same
        // moment and the counts are read-modify-write.
        $fresh = EmailMessage::query()->lockForUpdate()->findOrFail($message->id);

        $changes = ['status' => $fresh->status->then($event->type->status())];

        // First occurrence wins for the timestamps: "when was this opened" means
        // the first time, and every later open is in the count and the events.
        $column = match ($event->type) {
            EmailEventType::Delivered => 'delivered_at',
            EmailEventType::Opened => 'opened_at',
            EmailEventType::Clicked => 'clicked_at',
            EmailEventType::Bounced, EmailEventType::Complained, EmailEventType::Failed => 'failed_at',
        };

        if ($fresh->{$column} === null) {
            $changes[$column] = $event->occurredAt;
        }

        if ($event->type === EmailEventType::Opened) {
            $changes['open_count'] = $fresh->open_count + 1;
        }

        if ($event->type === EmailEventType::Clicked) {
            $changes['click_count'] = $fresh->click_count + 1;
        }

        if ($event->reason !== null && $event->type->status()->isFailure()) {
            $changes['reason'] = $event->reason;
        }

        $fresh->forceFill($changes)->save();

        $message->refresh();
    }

    private function isDuplicate(QueryException $failure): bool
    {
        return ($failure->errorInfo[0] ?? null) === '23000';
    }
}
