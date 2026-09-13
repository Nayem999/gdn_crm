<?php

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\ChannelManager;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\QuietHours;
use App\Domain\Notifications\Recipient;
use App\Domain\Notifications\UserNotificationPreferences;
use App\Jobs\SendNotification;
use App\Models\User;

/**
 * Works out who gets told what, on which channels, and queues the sending.
 *
 * The order of the gates matters and is deliberate:
 *   1. the event must be in the registry
 *   2. the admin matrix must have the channel on for that recipient type
 *   3. the recipient's own preference can veto it, but never turn it back on
 *   4. an unconfigured channel is skipped and logged, not attempted
 *   5. quiet hours delay an intrusive channel rather than dropping it
 *   6. the per-recipient throttle is applied in the job, at send time
 *
 * Nothing is sent inline; every delivery goes through the queue.
 */
class DispatchNotificationAction
{
    public function __construct(
        private readonly NotificationMatrix $matrix,
        private readonly UserNotificationPreferences $preferences,
        private readonly ChannelManager $channels,
        private readonly QuietHours $quietHours,
    ) {}

    /**
     * @param  array<int, Recipient>  $recipients
     * @param  array<string, mixed>  $data  Merge data for the templates.
     * @return int How many deliveries were queued.
     */
    public function handle(string $eventKey, array $recipients, array $data = [], ?User $actor = null, ?string $url = null): int
    {
        if (! NotificationEventRegistry::has($eventKey)) {
            return 0;
        }

        $queued = 0;
        $seen = [];

        foreach ($recipients as $recipient) {
            // The same person can arrive as both admin and watcher; they should
            // hear about it once.
            $identity = $recipient->identity();

            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;

            // Nobody is notified about something they did themselves.
            if ($actor !== null && $recipient->user !== null && $recipient->user->is($actor)) {
                continue;
            }

            foreach ($this->matrix->channelsFor($eventKey, $recipient->type) as $channel) {
                if ($recipient->user !== null
                    && ! $this->preferences->allows($recipient->user, $eventKey, $channel)) {
                    continue;
                }

                // Before the driver is even asked: an in-app notification has
                // nowhere to live without an account, and a customer contact
                // does not have one. Skipped and logged, the way an
                // unconfigured channel is — not attempted and left to throw.
                if (! $recipient->canReceive($channel)) {
                    $this->logSkipped($eventKey, $recipient, $channel,
                        'No '.$channel->label().' address for this recipient.');

                    continue;
                }

                $driver = $this->channels->driver($channel);

                if (! $driver->isConfigured()) {
                    $this->logSkipped($eventKey, $recipient, $channel,
                        $driver->unavailableReason() ?? 'Channel not configured.');

                    continue;
                }

                $log = NotificationLog::query()->create([
                    'event' => $eventKey,
                    'channel' => $channel->value,
                    'recipient_type' => $recipient->type->value,
                    'user_id' => $recipient->user?->id,
                    // Per channel, not one address for all of them: a customer
                    // contact's email is not their mobile number.
                    'recipient' => $recipient->addressFor($channel),
                    'status' => NotificationStatus::Queued->value,
                ]);

                SendNotification::dispatch($log->id, $data, $url)
                    ->delay($this->quietHours->delayFor($channel));

                $queued++;
            }
        }

        return $queued;
    }

    private function logSkipped(string $eventKey, Recipient $recipient, NotificationChannel $channel, string $reason): void
    {
        NotificationLog::query()->create([
            'event' => $eventKey,
            'channel' => $channel->value,
            'recipient_type' => $recipient->type->value,
            'user_id' => $recipient->user?->id,
            'recipient' => $recipient->addressFor($channel),
            'status' => NotificationStatus::Skipped->value,
            'error' => $reason,
        ]);
    }
}
