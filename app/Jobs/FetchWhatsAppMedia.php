<?php

namespace App\Jobs;

use App\Domain\Social\Actions\FetchWhatsAppMediaAction;
use App\Domain\Social\Models\SocialMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pulls one attachment down after the message is already threaded.
 *
 * Off the webhook deliberately. Meta gives a webhook a few seconds and retries
 * anything slower, and a customer's twelve-megabyte video would otherwise make
 * the delivery time out — producing a retry, and a second copy of a message that
 * had already arrived. The thread is readable the moment the message lands; the
 * photograph catches up behind it.
 *
 * Carries the id rather than the model, like every other job here: the action
 * writes to the row this would be holding a stale copy of.
 *
 * Retried, because what makes a fetch fail is almost always Meta — but the
 * action swallows its own failures and records them, so a retry only happens for
 * something thrown past it.
 */
class FetchWhatsAppMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $messageId) {}

    public function handle(FetchWhatsAppMediaAction $fetch): void
    {
        $message = SocialMessage::query()->with('conversation')->find($this->messageId);

        if ($message === null) {
            return;
        }

        $fetch($message);
    }
}
