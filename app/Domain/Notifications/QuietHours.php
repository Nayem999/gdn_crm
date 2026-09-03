<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The overnight window in which intrusive channels are held rather than sent.
 *
 * Held, not dropped: a notification suppressed at 2am is still wanted at 7am,
 * and silently discarding it would lose real information.
 */
class QuietHours
{
    public function isEnabled(): bool
    {
        return (bool) settings('notifications.quiet_hours_enabled', false);
    }

    /**
     * In-app notifications are not intrusive — they sit in the bell until the
     * person looks. Only the channels that reach out get held.
     */
    public function applyTo(NotificationChannel $channel): bool
    {
        return $channel !== NotificationChannel::InApp;
    }

    public function covers(?CarbonInterface $moment = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $moment = $moment ? $moment->copy() : now();
        $start = $this->at('notifications.quiet_hours_start', '21:00', $moment);
        $end = $this->at('notifications.quiet_hours_end', '07:00', $moment);

        // A window that ends before it starts runs through midnight.
        return $start->lessThanOrEqualTo($end)
            ? $moment->betweenIncluded($start, $end)
            : $moment->greaterThanOrEqualTo($start) || $moment->lessThanOrEqualTo($end);
    }

    /**
     * When a held notification should go out.
     */
    public function endsAfter(?CarbonInterface $moment = null): Carbon
    {
        $moment = $moment ? $moment->copy() : now();
        $end = $this->at('notifications.quiet_hours_end', '07:00', $moment);

        return $end->greaterThan($moment) ? $end : $end->addDay();
    }

    /**
     * How long to hold a message sent now, in seconds; zero when it may go.
     */
    public function delayFor(NotificationChannel $channel, ?CarbonInterface $moment = null): int
    {
        if (! $this->applyTo($channel) || ! $this->covers($moment)) {
            return 0;
        }

        $from = $moment ? $moment->copy() : now();

        return max(0, $from->diffInSeconds($this->endsAfter($from), false));
    }

    private function at(string $setting, string $fallback, CarbonInterface $reference): Carbon
    {
        $time = (string) settings($setting, $fallback);

        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return Carbon::parse($reference)->setTime(
            max(0, min(23, $hour)),
            max(0, min(59, $minute)),
            0
        );
    }
}
