<?php

namespace App\Domain\Shared\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Shared\SavedViews\SavedViewState;
use App\Models\User;
use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named arrangement of one list screen.
 *
 * @property int $id
 * @property string $module
 * @property string $name
 * @property int $owner_id
 * @property bool $is_shared
 * @property array<string, mixed> $state
 */
class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['module', 'name', 'owner_id', 'is_shared', 'state'];

    /**
     * Defaults for the columns that share a name with a method on this model.
     *
     * `state()` reads the `state` column, so on an instance where the attribute
     * is missing Laravel would take the property read for a relation, call the
     * method and fail. Raw JSON here, because $attributes holds what the cast
     * receives rather than what it produces. See
     * .ai/rules/models-name-collisions.md — and the discovery test in
     * LeadConversionTest, which caught exactly this.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_shared' => false,
        'state' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'state' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        // Not `state`: it is a blob nobody reads in an audit entry, and logging
        // it would put every filter value somebody ever used into the trail.
        return ['module', 'name', 'owner_id', 'is_shared'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Saved view';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The arrangement, as a value object rather than a raw array.
     */
    public function state(): SavedViewState
    {
        return SavedViewState::fromArray($this->getAttributeValue('state'));
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /**
     * The views one person may open on a module: their own, and anything
     * shared.
     *
     * Deliberately not the module's access level. A saved view holds no
     * records — it holds a filter — and scoping it by record visibility would
     * mean a shared view vanishing for the people it was shared with.
     *
     * @param  Builder<SavedView>  $query
     * @return Builder<SavedView>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user) {
            $inner->where($inner->qualifyColumn('owner_id'), $user->id)
                ->orWhere($inner->qualifyColumn('is_shared'), true);
        });
    }

    /**
     * @param  Builder<SavedView>  $query
     * @return Builder<SavedView>
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where($query->qualifyColumn('module'), $module);
    }

    /**
     * @param  Builder<SavedView>  $query
     * @return Builder<SavedView>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
