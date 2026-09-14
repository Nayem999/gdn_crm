<?php

namespace App\Domain\Meta\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Meta\Auth\MetaToken;
use App\Domain\Meta\Enums\MetaConnectionStatus;
use App\Models\User;
use Database\Factories\MetaAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The Meta business this installation is connected to.
 *
 * One per installation in practice, though nothing here forbids a second: a
 * company that acquired another may genuinely have two businesses for a while,
 * and a unique constraint on "there can be only one" would be a migration
 * somebody has to fight during exactly that fortnight.
 *
 * **No `ScopesByAccessLevel`.** A connection is not a customer record; it
 * belongs to the installation, not to the person who happened to authorise it.
 * Who may see it is a permission question (`meta.view`), answered by the policy.
 *
 * @property int $id
 * @property string $business_id
 * @property string $name
 * @property string|null $user_token
 * @property Carbon|null $token_expires_at
 * @property array<int, string>|null $granted_scopes
 * @property string $status
 * @property string|null $last_error
 * @property int|null $connected_by_id
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_synced_at
 */
class MetaAccount extends Model
{
    /** @use HasFactory<MetaAccountFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'name',
        'user_token',
        'token_expires_at',
        'granted_scopes',
        'status',
        'last_error',
        'connected_by_id',
        'connected_at',
        'last_synced_at',
    ];

    /**
     * `status()` is named after its column, so it needs a default or a
     * partially-created instance reads the method and Laravel takes it for a
     * relation. See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => MetaConnectionStatus::Connected->value,
    ];

    /**
     * The token is never rendered, so it is never in $visible either — but the
     * real protection is the cast: what is written to the column is ciphertext,
     * and a database dump carries nothing usable.
     *
     * @var list<string>
     */
    protected $hidden = ['user_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_token' => 'encrypted',
            'granted_scopes' => 'array',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * What the audit trail records. Deliberately not the token: the trail says
     * that a connection changed, never what it changed to.
     *
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['business_id', 'name', 'status', 'connected_by_id', 'connected_at'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_id');
    }

    /**
     * @return HasMany<MetaPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(MetaPage::class);
    }

    /**
     * @return HasMany<MetaAdAccount, $this>
     */
    public function adAccounts(): HasMany
    {
        return $this->hasMany(MetaAdAccount::class);
    }

    /**
     * @return HasMany<WhatsAppBusinessAccount, $this>
     */
    public function whatsAppAccounts(): HasMany
    {
        return $this->hasMany(WhatsAppBusinessAccount::class);
    }

    public function status(): MetaConnectionStatus
    {
        return MetaConnectionStatus::tryFrom((string) $this->getAttributeValue('status'))
            ?? MetaConnectionStatus::Connected;
    }

    public function token(): ?MetaToken
    {
        $value = $this->user_token;

        return is_string($value) && $value !== ''
            ? new MetaToken($value, $this->token_expires_at)
            : null;
    }

    /**
     * Whether this connection can still be used.
     *
     * An expired token is not "disconnected": the row, the pages and every
     * lead that came through them are still ours, and the fix is to
     * re-authorise rather than to start again.
     */
    public function isUsable(): bool
    {
        $token = $this->token();

        return $this->status() === MetaConnectionStatus::Connected
            && $token !== null
            && ! $token->isExpired();
    }

    /**
     * The permissions this integration needs that Meta did not grant.
     *
     * A person can decline individual permissions on the consent screen, and
     * without this the first sign is a capability failing weeks later.
     *
     * @param  array<int, string>  $required
     * @return array<int, string>
     */
    public function missingScopes(array $required): array
    {
        return array_values(array_diff($required, $this->granted_scopes ?? []));
    }
}
