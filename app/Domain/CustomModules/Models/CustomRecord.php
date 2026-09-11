<?php

namespace App\Domain\CustomModules\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\CustomRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One record in a generated module.
 *
 * Every generated module shares this table, discriminated by
 * `custom_module_id`. A query must therefore **always** be scoped to one
 * module: `forModule()` is how, and every path that builds a query here goes
 * through it. A query that forgot would mix two modules' records into one list.
 *
 * @property int $id
 * @property int $custom_module_id
 * @property string $name
 * @property int $owner_id
 */
class CustomRecord extends Model
{
    use HasCustomFields;

    /** @use HasFactory<CustomRecordFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['custom_module_id', 'name', 'owner_id'];

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'owner_id'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Custom record';
    }

    /**
     * @return BelongsTo<CustomModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CustomModule::class, 'custom_module_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Which module's fields this record carries.
     *
     * Overrides the trait's registry lookup, because every generated module is
     * the same class — the discriminator is the row, not the type.
     */
    public function customFieldModule(): string
    {
        $module = $this->module;

        return $module instanceof CustomModule
            ? $module->moduleKey()
            : CustomModule::PREFIX.'unknown';
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @return Builder<CustomRecord>
     */
    public function scopeForModule(Builder $query, CustomModule|int $module): Builder
    {
        return $query->where(
            $query->qualifyColumn('custom_module_id'),
            $module instanceof CustomModule ? $module->getKey() : $module,
        );
    }

    /**
     * The list's search, on this table's own columns only — the same rule
     * .ai/rules/accounts.md sets, so a queued export cannot match rows the list
     * never showed.
     *
     * @param  Builder<CustomRecord>  $query
     * @return Builder<CustomRecord>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where($query->qualifyColumn('name'), 'like', '%'.$term.'%');
    }
}
