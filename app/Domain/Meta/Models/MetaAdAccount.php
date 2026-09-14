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
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
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
