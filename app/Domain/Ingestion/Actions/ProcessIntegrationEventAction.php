<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\IngestionWriters;
use App\Domain\Ingestion\IntegrationHealth;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Ingestion\PayloadMapper;
use App\Domain\Ingestion\PayloadReader;
use App\Domain\Ingestion\Writers\ImportBackedWriter;
use App\Domain\Meta\Webhooks\MetaEventProcessor;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Domain\Notifications\Notifier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Filter → map → transform → dedupe → persist → assign → trigger → log.
 *
 * Runs on the queue, never on the request: a sender waiting on our database is
 * a sender that times out and retries, and a retry storm is how an integration
 * takes an application down.
 *
 * Two things about the shape are load-bearing:
 *
 * - **The persist is in a transaction; the event's own status is not.** If the
 *   write fails, the record must roll back — but the row saying it failed must
 *   survive, or a failure leaves no trace and the event reads as though it were
 *   never processed.
 * - **The payload is data.** It is decoded to an array and read by configured
 *   paths. Nothing in it names a field, a column, a class or a module: the
 *   source's stored `target_module` and its mappings decide all of that, and
 *   both are written by an administrator.
 */
class ProcessIntegrationEventAction
{
    public function __construct(private readonly PayloadMapper $mapper) {}

    public function __invoke(IntegrationEvent $event): IntegrationEvent
    {
        // Already dealt with. The queue can deliver a job twice, and doing the
        // work again would create a second record for one delivery.
        if ($event->isSettled() || $event->status() === IntegrationEventStatus::Processing) {
            return $event;
        }

        $source = $event->dataSource;

        if ($source === null) {
            return $this->fail($event, 'The source this arrived at no longer exists.');
        }

        $event->forceFill([
            'status' => IntegrationEventStatus::Processing->value,
            'attempts' => $event->attempts + 1,
        ])->save();

        try {
            return $this->run($event, $source);
        } catch (Throwable $exception) {
            // Whatever went wrong, the delivery is accounted for. The message
            // is the class and text, never the payload — a failure line that
            // echoed the body would put customer data in the log.
            return $this->fail($event, class_basename($exception).': '.$exception->getMessage());
        }
    }

    private function run(IntegrationEvent $event, DataSource $source): IntegrationEvent
    {
        // A provider owns its own processing. Meta's deliveries cannot go
        // through the mapper below: a lead-ads webhook carries an id and
        // nothing else, so there is nothing to map until the lead has been
        // fetched from Graph. What they do share is this table, and with it the
        // delivery log, the replay and the health panel.
        $channel = MetaSources::channelFor($source);

        if ($channel !== null) {
            $result = app(MetaEventProcessor::class)($event, $channel);

            return $this->settle($event, $result['status'], $result['outcome'], $result['record'] ?? null);
        }

        $payload = PayloadReader::decode((string) $event->payload);

        if ($payload === null) {
            return $this->fail($event, 'The body was not a JSON object.');
        }

        // -- Filter ----------------------------------------------------------
        foreach ($source->filters as $filter) {
            if (! $filter->matches($payload)) {
                // Not a failure. A source that keeps only one event type
                // discards most of what it is sent, and calling that an error
                // would bury the real ones.
                return $this->settle($event, IntegrationEventStatus::Skipped, 'filtered');
            }
        }

        $writer = IngestionWriters::for($source->target_module);

        if ($writer === null) {
            return $this->fail($event, 'Nothing can write into '.$source->target_module.'.');
        }

        // -- Map and transform -----------------------------------------------
        // The same mapper the dry run on the mapping screen uses, so a preview
        // and the real thing cannot disagree.
        $mapped = $this->mapper->map($source, $payload, $writer);
        $row = $mapped->row;

        if (! $mapped->isComplete()) {
            return $this->fail($event, 'Required by the mapping but absent: '.implode(', ', $mapped->missing).'.');
        }

        $event->forceFill(['mapped_output' => $mapped->all()])->save();

        $external = $this->externalId($source, $payload);

        if ($external !== null) {
            $event->forceFill(['external_id' => $external])->save();
        }

        // -- Validate --------------------------------------------------------
        // The module's own rules, the same ones an import obeys. A payload that
        // would make a record the application refuses to accept by hand is
        // refused here too.
        $validator = Validator::make($row, $writer->rulesFor(array_keys($row)));

        if ($validator->fails()) {
            return $this->fail($event, implode(' ', $validator->errors()->all()));
        }

        // -- Dedupe ----------------------------------------------------------
        $existing = $this->findExisting($source, $writer, $event, $row, $external);

        if ($existing !== null && $source->dedupeAction() === DedupeAction::Skip) {
            return $this->settle($event, IntegrationEventStatus::Skipped, 'skipped', $existing);
        }

        // -- Sandbox ---------------------------------------------------------
        if (! $source->writesRecords()) {
            // Everything above has run, so the mapped output is real and the
            // operator can see exactly what would have been written. Nothing
            // below does.
            return $this->settle($event, IntegrationEventStatus::Skipped, 'sandbox');
        }

        // -- Assign, persist, trigger -----------------------------------------
        $owner = $this->owner($source);

        if ($owner === null) {
            return $this->fail($event, 'This source has nobody to own what it creates.');
        }

        // The write is the only thing in the transaction. Creating a record
        // fires the observers that run workflows, so "trigger" needs no stage
        // of its own — an ingested record starts automations exactly as a
        // typed-in one does.
        $record = DB::transaction(function () use ($existing, $source, $writer, $row, $mapped, $owner) {
            $record = $existing !== null && $source->dedupeAction() === DedupeAction::Update
                ? $writer->update($existing, $row, $owner)
                : $writer->create($row, $owner);

            // Inside the transaction with the record: a lead that exists with
            // half its custom fields written is worse than one that does not
            // exist at all.
            $writer->writeCustomFields($record, $mapped->customFields);

            return $record;
        });

        return $this->settle(
            $event,
            IntegrationEventStatus::Processed,
            $existing !== null && $source->dedupeAction() === DedupeAction::Update ? 'updated' : 'created',
            $record
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function externalId(DataSource $source, array $payload): ?string
    {
        if ($source->external_id_path === null || $source->external_id_path === '') {
            return null;
        }

        $value = PayloadReader::value($payload, $source->external_id_path);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * The record this delivery is about, if we have already seen it.
     *
     * The sender's own id first, and it is looked up **through the event log**
     * rather than through a column on the business table. That keeps the
     * idempotency key where it belongs — a fact about a delivery, not a field
     * on a lead — and means no module has to grow a column to be ingestible.
     *
     * @param  array<string, string|null>  $row
     */
    private function findExisting(
        DataSource $source,
        ImportBackedWriter $writer,
        IntegrationEvent $event,
        array $row,
        ?string $external,
    ): ?Model {
        // A delivery that has already produced a record — which is what a
        // replay is — belongs to that record. Without this it would look past
        // itself (the query below excludes the event being processed, so a
        // first run cannot match itself) and create a second copy of exactly
        // the thing the original made.
        if ($event->record_id !== null && $event->record_type === $writer->modelClass()) {
            $own = $writer->matchQuery()->whereKey($event->record_id)->first();

            if ($own !== null) {
                return $own;
            }
        }

        if ($external !== null) {
            $previous = IntegrationEvent::query()
                ->where('data_source_id', $source->getKey())
                ->where('external_id', $external)
                ->whereNotNull('record_id')
                ->where('record_type', $writer->modelClass())
                ->whereKeyNot($event->getKey())
                ->latest('id')
                ->first();

            if ($previous !== null) {
                $found = $writer->matchQuery()->whereKey($previous->record_id)->first();

                if ($found !== null) {
                    return $found;
                }
            }
        }

        $fields = $source->dedupe_fields;

        if (! is_array($fields) || $fields === []) {
            return null;
        }

        $query = $writer->matchQuery();

        foreach ($fields as $field) {
            $field = (string) $field;
            $value = $row[$field] ?? null;

            // A blank match value would match every record with a blank in that
            // column, which is how one delivery updates somebody else's record.
            if ($value === null || $value === '') {
                return null;
            }

            $query->where($field, $value);
        }

        return $query->first();
    }

    /**
     * Who owns what this source creates.
     *
     * The configured owner, or whoever set the source up. A record with no
     * owner is how the visibility scope springs a leak, so a source with
     * neither fails loudly rather than creating one.
     */
    private function owner(DataSource $source): ?User
    {
        return $source->defaultOwner ?? $source->createdBy;
    }

    private function settle(
        IntegrationEvent $event,
        IntegrationEventStatus $status,
        string $outcome,
        ?Model $record = null,
    ): IntegrationEvent {
        $event->forceFill([
            'status' => $status->value,
            'outcome' => $outcome,
            'record_type' => $record?->getMorphClass(),
            'record_id' => $record?->getKey(),
            'processed_at' => now(),
            'error' => null,
        ])->save();

        return $event->refresh();
    }

    /**
     * Written outside any transaction the persist opened, so the account of the
     * failure survives the rollback that caused it.
     */
    private function fail(IntegrationEvent $event, string $error): IntegrationEvent
    {
        $event->forceFill([
            'status' => IntegrationEventStatus::Failed->value,
            'outcome' => 'failed',
            'processed_at' => now(),
            // Truncated: a database error can echo an entire query, and this
            // column is read on a screen.
            'error' => mb_substr($error, 0, 1000),
        ])->save();

        $this->alertIfFailing($event, $error);

        return $event->refresh();
    }

    /**
     * Tell the administrators when a source starts failing in a run.
     *
     * Fired from here rather than from a sweep, because the moment worth
     * noticing is the moment it happens — a nightly check would tell somebody
     * about an outage the morning after it started.
     *
     * `shouldAlert` is true only at the threshold, never past it, so a
     * thoroughly broken source sends one alert rather than one per delivery.
     * Never fatal: an integration that is already failing must not also fail
     * because nobody could be told about it.
     */
    private function alertIfFailing(IntegrationEvent $event, string $error): void
    {
        $source = $event->dataSource;

        if ($source === null || ! IntegrationHealth::shouldAlert($source)) {
            return;
        }

        try {
            app(Notifier::class)->sendToAdmins('integration.failing', 'integrations.view', [
                'source' => [
                    'name' => $source->name,
                    'failures' => IntegrationHealth::ALERT_AFTER,
                    // Truncated again here: this one is read in an email.
                    'error' => mb_substr($error, 0, 200),
                ],
            ], null, route('settings.integration-log', ['source' => $source->id]));
        } catch (Throwable) {
            // Deliberately swallowed. See above.
        }
    }
}
