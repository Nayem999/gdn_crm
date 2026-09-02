<?php

namespace App\Domain\Users\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Models\Team;
use App\Models\User;
use Database\Factories\UserInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property int|null $role_id
 * @property int|null $team_id
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class UserInvitation extends Model
{
    /** @use HasFactory<UserInvitationFactory> */
    use HasFactory, RecordsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'name',
        'token',
        'role_id',
        'team_id',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    /**
     * The token column holds a hash, never the value that was emailed out.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * The token is a credential, so it never reaches the audit trail.
     *
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['email', 'name', 'role_id', 'team_id', 'invited_by', 'expires_at', 'accepted_at'];
    }

    /**
     * Hash a plain-text invitation token for storage and lookup.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Find a pending (unaccepted, unexpired) invitation for a plain-text token.
     */
    public static function findPendingByToken(string $plainToken): ?self
    {
        return static::query()
            ->pending()
            ->where('token', static::hashToken($plainToken))
            ->first();
    }

    /**
     * @param  Builder<UserInvitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
