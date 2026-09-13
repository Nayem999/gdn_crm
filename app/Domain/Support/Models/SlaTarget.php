<?php

namespace App\Domain\Support\Models;

use App\Domain\Support\Enums\TicketPriority;
use Database\Factories\SlaTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One promise, at one priority.
 *
 * Either target may be null, which means "no promise of that kind here" rather
 * than zero minutes — a desk may guarantee a first reply on everything but a
 * resolution time only on the urgent ones.
 *
 * @property int $id
 * @property int $sla_policy_id
 * @property int $priority
 * @property int|null $first_response_minutes
 * @property int|null $resolution_minutes
 */
class SlaTarget extends Model
{
    /** @use HasFactory<SlaTargetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['sla_policy_id', 'priority', 'first_response_minutes', 'resolution_minutes'];

    /**
     * `priority` shares its name with the method below.
     *
     * Without a default here, reading the property on an instance that was
     * never given the column turns the read into a relation lookup and calls
     * the method — see .ai/rules/models-name-collisions.md. An enum case is a
     * valid constant expression, so the default cannot drift from the enum.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['priority' => TicketPriority::Normal->value];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SlaPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /**
     * getAttributeValue, not $this->priority: the method and the column share a
     * name — see .ai/rules/models-name-collisions.md.
     */
    public function priority(): TicketPriority
    {
        return TicketPriority::tryFrom((int) $this->getAttributeValue('priority')) ?? TicketPriority::Normal;
    }

    public function promisesAnything(): bool
    {
        return $this->first_response_minutes !== null || $this->resolution_minutes !== null;
    }
}
