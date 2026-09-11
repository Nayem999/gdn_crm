<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Enums\ViewMode;
use App\Models\User;
use Database\Factories\UserViewPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one user has arranged one list screen: which view they last used, which
 * columns they show and in what order, and how many rows per page.
 *
 * @property int $id
 * @property int $user_id
 * @property string $module
 * @property string $view_mode
 * @property array<int, string>|null $columns
 * @property array<int, string>|null $pinned_columns
 * @property int $per_page
 */
class UserViewPreference extends Model
{
    /** @use HasFactory<UserViewPreferenceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'module',
        'default_saved_view_id',
        'view_mode',
        'columns',
        'pinned_columns',
        'per_page',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'pinned_columns' => 'array',
            'per_page' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function viewMode(): ViewMode
    {
        return ViewMode::tryFrom($this->view_mode) ?? ViewMode::Table;
    }

    /**
     * Read a user's stored preference for one module, if any.
     */
    public static function lookup(User $user, string $module): ?self
    {
        return static::query()
            ->where('user_id', $user->id)
            ->where('module', $module)
            ->first();
    }

    /**
     * Persist part of a user's arrangement, leaving the rest untouched.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function remember(User $user, string $module, array $attributes): self
    {
        return static::query()->updateOrCreate(
            ['user_id' => $user->id, 'module' => $module],
            $attributes
        );
    }
}
