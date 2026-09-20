<?php

namespace App\Domain\Meta\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\MetaAdAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An advertising account whose spend this installation reads.
 *
 * `currency` is stored per account rather than taken from the company profile,
 * and that is load-bearing: an agency running one client's ads in USD and
 * another's in BDT would otherwise have both added into one meaningless total.
 * Every figure read from Meta is in *this* account's currency, and 12.13's
 * dashboard has to say so rather than summing them.
 *
 * `status` is Meta's own — active, disabled, unsettled. A disabled account still
 * has spend worth reading, so it is recorded rather than used to hide the row.
 *
 * @property int $id
 * @property int $meta_account_id
 * @property string $ad_account_id
 * @property string $name
 * @property string|null $currency
 * @property string|null $timezone
 * @property string|null $status
 * @property bool $is_active
 * @property Carbon|null $last_synced_at
 * @property string|null $access_token
 * @property string|null $token_type
 * @property string|null $token_app_id
 * @property string|null $token_error
 * @property Carbon|null $token_checked_at
 * @property Carbon|null $insights_synced_through
 */
class MetaAdAccount extends Model
{
    /** @use HasFactory<MetaAdAccountFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'meta_account_id',
        'ad_account_id',
        'name',
        'currency',
        'timezone',
        'status',
        'is_active',
        'last_synced_at',
    ];

    /**
     * `status()` shares its name with the column, so the key has to be present
     * on every instance. See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => null,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'token_checked_at' => 'datetime',
            // A date, not a datetime: insights are days, reported in the ad
            // account's own timezone, and an hour-precision mark would make
            // "have we got Tuesday" unanswerable. See 12.7's migration.
            'insights_synced_through' => 'date',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['ad_account_id', 'name', 'currency', 'status', 'is_active'];
    }

    /**
     * @return BelongsTo<MetaAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAccount::class, 'meta_account_id');
    }

    /**
     * The credential this ad account's calls use.
     *
     * Its own where it was given one, the connection's otherwise — which is
     * what a single system user holding every asset looks like, and what most
     * installations have.
     *
     * Asked here rather than at each call site, because there are three of
     * them (the capability test, the structure sync, the insights sync) and
     * one reaching past the asset to the account is the bug this method
     * exists to end.
     */
    public function usableToken(): ?string
    {
        $own = $this->access_token;

        if (is_string($own) && $own !== '') {
            return $own;
        }

        return $this->account?->token()?->value;
    }

    /**
     * Whether there is a credential to call Meta with at all.
     *
     * Deliberately not "and Meta still accepts it": that is a fact about the
     * last check, held in token_error, and a screen conflating the two would
     * report an ad account as unconfigured when it is configured and revoked.
     */
    public function isUsable(): bool
    {
        return $this->usableToken() !== null;
    }

    public function status(): ?string
    {
        $status = $this->getAttributeValue('status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Meta prefixes an ad account id with `act_` in every endpoint that takes
     * one, and omits it from every response that returns one. Rather than
     * remember which is which at eleven call sites, the id is stored bare and
     * prefixed here.
     */
    public function graphId(): string
    {
        return str_starts_with($this->ad_account_id, 'act_')
            ? $this->ad_account_id
            : 'act_'.$this->ad_account_id;
    }
}
