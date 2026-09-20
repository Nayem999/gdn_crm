<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use Illuminate\Support\Carbon;

/**
 * Whether an integration is working, answered per source.
 *
 * The question somebody actually opens this screen with is "is it broken", and
 * the honest answer is a run of recent failures rather than a total: a source
 * that has delivered ten thousand records and failed forty times is fine, and
 * one that has failed its last four is not.
 */
final class IntegrationHealth
{
    /**
     * How many failures in a row before anybody is told.
     *
     * Not one. Every integration fails occasionally — a malformed payload, a
     * record somebody deleted at their end — and alerting on each would train
     * people to ignore the alerts.
     */
    public const ALERT_AFTER = 5;

    /**
     * Deliveries in a row that failed, counting back from the most recent.
     *
     * Reads only as far as it needs to: the run ends at the first delivery that
     * did not fail, so a healthy source costs one row.
     */
    public static function consecutiveFailures(DataSource $source): int
    {
        $recent = IntegrationEvent::query()
            ->where('data_source_id', $source->getKey())
            ->whereIn('status', [
                IntegrationEventStatus::Failed->value,
                IntegrationEventStatus::Processed->value,
                IntegrationEventStatus::Skipped->value,
            ])
            ->latestFirst()
            ->limit(self::ALERT_AFTER + 1)
            ->pluck('status');

        $run = 0;

        foreach ($recent as $status) {
            if ($status !== IntegrationEventStatus::Failed->value) {
                break;
            }

            $run++;
        }

        return $run;
    }

    /**
     * A source's recent record, for the dashboard.
     *
     * @return array{received: int, processed: int, skipped: int, failed: int, failing: int, last: Carbon|null}
     */
    public static function summarise(DataSource $source, ?Carbon $since = null): array
    {
        $since ??= now()->subDay();

        $counts = IntegrationEvent::query()
            ->where('data_source_id', $source->getKey())
            ->where('received_at', '>=', $since)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $last = IntegrationEvent::query()
            ->where('data_source_id', $source->getKey())
            ->latestFirst()
            ->value('received_at');

        return [
            'received' => (int) $counts->sum(),
            'processed' => (int) ($counts[IntegrationEventStatus::Processed->value] ?? 0),
            'skipped' => (int) ($counts[IntegrationEventStatus::Skipped->value] ?? 0),
            'failed' => (int) ($counts[IntegrationEventStatus::Failed->value] ?? 0),
            'failing' => self::consecutiveFailures($source),
            'last' => $last === null ? null : Carbon::parse((string) $last),
        ];
    }

    /**
     * How long a delivery may sit unprocessed before something is wrong.
     *
     * Generous, because it is measuring a queue and not a request: a worker
     * that restarts, a burst of a thousand leads, a slow third-party lookup in
     * a job ahead of this one. Five minutes is long past all of those and
     * still short enough to notice within a working day.
     */
    public const STALLED_AFTER_MINUTES = 5;

    /**
     * Deliveries that arrived and were never processed.
     *
     * The failure this exists to name has no error message and no failed row:
     * nothing is *running*. Every delivery is captured, answered, queued — and
     * then sits, while the inbox stays empty in a way indistinguishable from
     * nobody having messaged. The only trace is a `received` row that never
     * became anything, which is precisely what this counts.
     *
     * Both statuses, because both are "not finished": `processing` that never
     * advanced means a worker took the job and died holding it.
     *
     * @return array{count: int, oldest: Carbon|null}
     */
    public static function stalled(): array
    {
        $cutoff = now()->subMinutes(self::STALLED_AFTER_MINUTES);

        $query = IntegrationEvent::query()
            ->whereIn('status', [
                IntegrationEventStatus::Received->value,
                IntegrationEventStatus::Processing->value,
            ])
            ->where('received_at', '<=', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            return ['count' => 0, 'oldest' => null];
        }

        $oldest = $query->min('received_at');

        return [
            'count' => $count,
            // How long it has been stuck is the part that says whether this is
            // a worker catching up or a worker that was never started.
            'oldest' => $oldest === null ? null : Carbon::parse((string) $oldest),
        ];
    }

    /**
     * Whether this failure is the one worth telling somebody about.
     *
     * True **only when the run reaches the threshold**, never past it. A source
     * that is thoroughly broken would otherwise send an alert per delivery, and
     * the first success resets the run so the next outage alerts again.
     */
    public static function shouldAlert(DataSource $source): bool
    {
        return self::consecutiveFailures($source) === self::ALERT_AFTER;
    }
}
