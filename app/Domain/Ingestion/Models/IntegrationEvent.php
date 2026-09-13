<?php

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\IntegrationEventFields;
use Database\Factories\IntegrationEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One delivery, and what became of it.
 *
 * Append-only in spirit: the pipeline moves its status and fills in what it
 * produced, and nothing else edits a row. It is the only account of what an
 * outside system actually sent, so it must stay believable after the source
 * that received it has been reconfigured — which is why `is_sandbox` and
 * `signature_verified` are copied onto the event rather than looked up.
 *
 * The payload is **data**. It is stored as the bytes that arrived, rendered
 * escaped, and never evaluated, unserialised or executed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $data_source_id
 * @property string $status
 * @property string|null $payload
 * @property string|null $body_hash
 * @property string|null $signature_fingerprint
 * @property array<string, mixed>|null $headers
 * @property array<string, mixed>|null $mapped_output
 * @property string|null $external_id
 * @property string|null $record_type
 * @property int|null $record_id
 * @property string|null $outcome
 * @property string|null $ip_address
 * @property bool $signature_verified
 * @property bool $is_sandbox
 * @property int $attempts
 * @property string|null $error
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
class IntegrationEvent extends Model
{
    /** @use HasFactory<IntegrationEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'data_source_id',
        'payload',
        'body_hash',
        'signature_fingerprint',
        'headers',
        'external_id',
        'ip_address',
        'signature_verified',
        'is_sandbox',
        'received_at',
    ];

    /**
     * `status`, `outcome`, `mapped_output`, the record pair, `attempts` and
     * `error` are deliberately absent: the processing pipeline (8.4) owns them,
     * the way the complete and cancel actions own an activity's status. Nothing
     * that captures a delivery may declare what became of it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'received',
        'signature_verified' => false,
        'is_sandbox' => false,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapped_output' => 'array',
            'signature_verified' => 'boolean',
            'is_sandbox' => 'boolean',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            if (! $event->getAttributeValue('uuid')) {
                $event->uuid = (string) Str::uuid();
            }

            // The moment it arrived, not the moment somebody remembered to set
            // it. A capture that forgot this would leave the column at zero and
            // the log ordered by nothing in particular.
            if (! $event->getAttributeValue('received_at')) {
                $event->received_at = now();
            }
        });
    }

    // -- Relations ----------------------------------------------------------

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * What this delivery created or updated, when it produced something.
     *
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo('record');
    }

    // -- Presentation --------------------------------------------------------

    /**
     * getAttributeValue, not $this->status: the method and the column share a
     * name — see .ai/rules/models-name-collisions.md.
     */
    public function status(): IntegrationEventStatus
    {
        return IntegrationEventStatus::tryFrom((string) $this->getAttributeValue('status'))
            ?? IntegrationEventStatus::Received;
    }

    public function isSettled(): bool
    {
        return $this->status()->isSettled();
    }

    /**
     * How big the delivery was, for a log that has to say so without printing
     * a megabyte of it.
     */
    public function payloadBytes(): int
    {
        return strlen((string) $this->payload);
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<IntegrationEvent>  $query
     * @return Builder<IntegrationEvent>
     */
    public function scopeWithStatus(Builder $query, IntegrationEventStatus $status): Builder
    {
        return $query->where($query->qualifyColumn('status'), $status->value);
    }

    /**
     * @param  Builder<IntegrationEvent>  $query
     * @return Builder<IntegrationEvent>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->withStatus(IntegrationEventStatus::Failed);
    }

    /**
     * The same columns the log screen searches.
     *
     * Including the **payload**, because somebody asking "did that come
     * through" has a name or an email address rather than an event id, and the
     * body is the only place either appears. It is a LIKE over a longText
     * column and it is slow — but a log nobody can search is a log nobody uses.
     *
     * Read from the same declaration the screen reads, so a queued export
     * cannot match rows the list never showed — the drift .ai/rules/accounts.md
     * exists to prevent.
     *
     * @param  Builder<IntegrationEvent>  $query
     * @return Builder<IntegrationEvent>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (IntegrationEventFields::searchColumns() as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * Newest first, then by id — two deliveries in the same second otherwise
     * come back in whatever order the engine chose, which makes paging the log
     * repeat or skip rows.
     *
     * @param  Builder<IntegrationEvent>  $query
     * @return Builder<IntegrationEvent>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc($query->qualifyColumn('received_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }
}
