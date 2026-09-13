<?php

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\PayloadFilterOperator;
use Database\Factories\DataSourceFilterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One condition a delivery has to satisfy before this source acts on it.
 *
 * All of a source's filters must pass — AND, with no OR and no nesting. A
 * source that needs a truth table is a source whose sender should be posting
 * fewer event types, and a rule language grown one operator at a time is a
 * language nobody ends up documenting.
 *
 * @property int $id
 * @property int $data_source_id
 * @property string $path
 * @property string $operator
 * @property string|null $value
 * @property int $position
 */
class DataSourceFilter extends Model
{
    /** @use HasFactory<DataSourceFilterFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['data_source_id', 'path', 'operator', 'value', 'position'];

    /**
     * `operator` shares its name with the method below — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'operator' => 'equals',
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function operator(): PayloadFilterOperator
    {
        return PayloadFilterOperator::tryFrom((string) $this->getAttributeValue('operator'))
            ?? PayloadFilterOperator::Equals;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function matches(array $payload): bool
    {
        return $this->operator()->matches($payload, $this->path, $this->value);
    }

    public function describe(): string
    {
        return $this->path.' '.$this->operator()->label()
            .($this->operator()->needsValue() ? ' '.(string) $this->value : '');
    }
}
