<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * A ceiling on how much one person can be sent in an hour, so a loop or a bulk
 * import cannot turn into a notification storm.
 *
 * The counter is per recipient, not global: one busy record must not silence
 * everybody else's notifications.
 */
class NotificationThrottle
{
    public const CACHE_PREFIX = 'notifications:throttle:';

    public function limit(): int
    {
        return max(1, (int) settings('notifications.rate_limit_per_hour', 60));
    }

    /**
     * Count this send against the recipient's hourly allowance.
     *
     * @return bool True when it may go, false when the ceiling is reached.
     */
    public function attempt(?User $user, ?string $address, NotificationChannel $channel): bool
    {
        $key = $this->keyFor($user, $address, $channel);

        if ($key === null) {
            return true;
        }

        $used = (int) Cache::get($key, 0);

        if ($used >= $this->limit()) {
            return false;
        }

        // put() rather than increment(): the database cache store has no atomic
        // increment that also sets a TTL on first write.
        Cache::put($key, $used + 1, now()->addHour());

        return true;
    }

    public function used(?User $user, ?string $address, NotificationChannel $channel): int
    {
        $key = $this->keyFor($user, $address, $channel);

        return $key === null ? 0 : (int) Cache::get($key, 0);
    }

    public function clear(?User $user, ?string $address, NotificationChannel $channel): void
    {
        $key = $this->keyFor($user, $address, $channel);

        if ($key !== null) {
            Cache::forget($key);
        }
    }

    private function keyFor(?User $user, ?string $address, NotificationChannel $channel): ?string
    {
        $who = $user !== null ? 'user:'.$user->id : ($address !== null ? 'to:'.sha1($address) : null);

        return $who === null ? null : self::CACHE_PREFIX.$who.':'.$channel->value;
    }
}
