<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialMessage;
use Illuminate\Support\Carbon;

/**
 * Moving an outbound message along as Meta reports what became of it.
 *
 * **Only ever forward.** Meta sends sent, delivered and read as three separate
 * webhooks, and they overtake each other routinely — a `delivered` arriving
 * after a `read` is normal, not a correction. Writing whichever arrived last
 * would make a message somebody has already read show as merely delivered, so
 * each status is written only when it is further on than the one stored.
 *
 * The exception is failure, which always wins: it is the one thing that can
 * happen after a send and has to stay visible even when a stale receipt turns up
 * behind it.
 *
 * A receipt for a message this application does not have is **ignored, not an
 * error**. Meta reports on everything the number sent, including messages sent
 * from the Business Manager by somebody's phone, and those are not ours to
 * account for.
 */
class RecordDeliveryReceiptAction
{
    public function __invoke(
        SocialChannel $channel,
        string $externalMessageId,
        string $status,
        ?Carbon $occurredAt = null,
        ?string $error = null,
    ): bool {
        $target = MessageStatus::fromMeta($status);

        if ($target === null) {
            return false;
        }

        $message = SocialMessage::query()
            ->where('channel', $channel->value)
            ->where('external_message_id', $externalMessageId)
            ->first();

        if ($message === null) {
            return false;
        }

        if (! $target->isProgressFrom($message->status())) {
            return false;
        }

        $at = $occurredAt ?? Carbon::now();

        $message->forceFill([
            'status' => $target->value,
            // Each stamp is written once, by the receipt that means it. A read
            // receipt does not imply the delivery time, and inventing one would
            // put a moment in the record that nothing observed.
            ...match ($target) {
                MessageStatus::Sent => ['sent_at' => $message->sent_at ?? $at],
                MessageStatus::Delivered => ['delivered_at' => $at],
                MessageStatus::Read => ['read_at' => $at],
                default => [],
            },
            'error' => $target === MessageStatus::Failed
                ? mb_substr($error ?? 'Meta could not deliver this message.', 0, 1000)
                : $message->error,
        ])->save();

        return true;
    }
}
