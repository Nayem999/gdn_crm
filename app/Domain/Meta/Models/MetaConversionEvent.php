<?php

namespace App\Domain\Meta\Models;

use App\Domain\Meta\Conversions\Enums\ConversionOutcome;
use App\Domain\Meta\Conversions\Enums\ConversionStatus;
use Database\Factories\MetaConversionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One outcome reported back to Meta.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_name
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $dataset_id
 * @property string $action_source
 * @property float|null $value
 * @property string|null $currency
 * @property array<string, mixed>|null $payload
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $response
 * @property string|null $error
 * @property Carbon $occurred_at
 * @property Carbon|null $sent_at
 */
class MetaConversionEvent extends Model
{
    /** @use HasFactory<MetaConversionEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_name',
        'subject_type',
        'subject_id',
        'dataset_id',
        'action_source',
        'value',
        'currency',
        'payload',
        'status',
        'attempts',
        'response_status',
        'response',
        'error',
        'occurred_at',
        'sent_at',
    ];

    /**
     * `status()` is named after its column — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ConversionStatus::Pending->value,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'value' => 'decimal:2',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function status(): ConversionStatus
    {
        return ConversionStatus::tryFrom((string) $this->getAttributeValue('status')) ?? ConversionStatus::Pending;
    }

    public function outcome(): ?ConversionOutcome
    {
        foreach (ConversionOutcome::cases() as $case) {
            if ($case->eventName() === $this->event_name) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Whether Meta is too late to accept this.
     *
     * Meta refuses an event whose `event_time` is more than seven days old, so
     * a row that has been failing all week is finished rather than merely
     * unlucky — and saying so beats a retry button that cannot work.
     */
    public function isTooOldToSend(): bool
    {
        return $this->occurred_at->lt(Carbon::now()->subDays(7));
    }
}
