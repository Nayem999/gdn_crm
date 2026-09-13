<?php

namespace App\Domain\Ingestion\Models;

use Database\Factories\DataSourceMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one field of ours gets its value from.
 *
 * `source_path` reads somebody else's JSON; `target_field` names a field of
 * ours and is checked against the target module's own declaration before
 * anything is written. Nothing in a payload reaches either — an administrator
 * writes both, against a sample.
 *
 * @property int $id
 * @property int $data_source_id
 * @property string $source_path
 * @property string $target_field
 * @property bool $is_custom_field
 * @property string|null $transform
 * @property array<string, mixed>|null $transform_options
 * @property string|null $default_value
 * @property bool $is_required
 * @property int $position
 */
class DataSourceMapping extends Model
{
    /** @use HasFactory<DataSourceMappingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'data_source_id',
        'source_path',
        'target_field',
        'is_custom_field',
        'transform',
        'transform_options',
        'default_value',
        'is_required',
        'position',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_custom_field' => false,
        'is_required' => false,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_custom_field' => 'boolean',
            'is_required' => 'boolean',
            'transform_options' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
