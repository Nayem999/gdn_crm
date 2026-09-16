<?php

namespace App\Domain\Meta\Conversions\Actions;

use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Meta\Conversions\ConversionPayload;
use App\Domain\Meta\Conversions\Enums\ConversionOutcome;
use App\Domain\Meta\Conversions\Enums\ConversionStatus;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\Models\MetaConversionEvent;
use App\Jobs\SendMetaConversion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Deciding whether an outcome is worth telling Meta about, and queuing it once.
 *
 * Four gates, in order, and each one exists because of what happens without it:
 *
 * 1. **No dataset id, nothing to send.** The Conversions API is optional; an
 *    installation that never configured one is not misconfigured, and filling
 *    its log with failures would say it was.
 * 2. **No Meta attribution, nothing to send.** A lead somebody typed in has no
 *    advertisement behind it; reporting its win would credit Meta with business
 *    it did not bring.
 * 3. **Nothing to match by, recorded and not sent.** Kept as a row so the
 *    silence is explainable — "skipped" rather than a gap somebody has to guess
 *    about.
 * 4. **Once per outcome per record, enforced by the database.** The unique
 *    index is the guarantee, not the check before it: two queue workers
 *    processing a stage change race each other, and the loser catching a
 *    constraint violation is the only version of this that is actually safe.
 */
class QueueConversionAction
{
    /**
     * @param  array{email?: string|null, phone?: string|null}  $identifiers
     */
    public function __invoke(
        Model $subject,
        ConversionOutcome $outcome,
        MarketingAttribution $attribution,
        array $identifiers = [],
        ?float $value = null,
        ?string $currency = null,
        ?Carbon $occurredAt = null,
    ): ?MetaConversionEvent {
        $dataset = app(MetaConfiguration::class)->datasetId();

        if ($dataset === null || ! $attribution->isFromMeta()) {
            return null;
        }

        $occurredAt ??= Carbon::now();
        $eventId = $this->eventId($subject, $outcome);

        // Asked before writing, because the ordinary case is a record saved
        // many times after its outcome was already reported: relying on the
        // unique index alone would mean a failed insert — and a logged database
        // error — on every one of those saves.
        if (MetaConversionEvent::query()->where('event_id', $eventId)->exists()) {
            return null;
        }

        $matchable = ConversionPayload::isMatchable($attribution, $identifiers);

        $payload = $matchable
            ? ConversionPayload::build($outcome, $eventId, $attribution, $identifiers, $occurredAt, $value, $currency)
            : null;

        try {
            $event = MetaConversionEvent::query()->create([
                'event_id' => $eventId,
                'event_name' => $outcome->eventName(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'dataset_id' => $dataset,
                'action_source' => $payload['action_source'] ?? 'system_generated',
                'value' => $outcome->carriesValue() ? $value : null,
                'currency' => $outcome->carriesValue() ? $currency : null,
                'payload' => $payload,
                'status' => $matchable ? ConversionStatus::Pending->value : ConversionStatus::Skipped->value,
                'occurred_at' => $occurredAt,
            ]);
        } catch (QueryException $exception) {
            // Already reported. The unique index did its job; this is the
            // expected outcome of a race, not an error worth raising.
            return null;
        }

        if ($matchable) {
            $event->refresh();

            // afterCommit, because the outcome that caused this is usually
            // being written in a transaction of its own: a job that started
            // first would read a row that is not there yet.
            SendMetaConversion::dispatch($event->id)->afterCommit();
        }

        return $event;
    }

    /**
     * The idempotency key: this record, this outcome, once.
     *
     * Deterministic rather than random, so it is the same key a second time —
     * which is the whole point. Meta also de-duplicates on it, which covers the
     * case where our row was deleted and the outcome reported again.
     */
    private function eventId(Model $subject, ConversionOutcome $outcome): string
    {
        return substr(hash('sha256', implode('|', [
            $subject->getMorphClass(),
            (string) $subject->getKey(),
            $outcome->value,
        ])), 0, 48);
    }
}
