<?php

namespace App\Domain\Meta\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\MetaPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Facebook page this installation reads leads and messages from.
 *
 * It carries a token of its own rather than borrowing the user's. That is not
 * an optimisation: a page token keeps working after the person who authorised
 * it closes their laptop, changes their password or leaves the company, and a
 * CRM whose lead capture stopped when somebody went on holiday would be worse
 * than no integration at all.
 *
 * `is_subscribed` is a fact about Meta's side, which is why it is stored rather
 * than inferred. A page that quietly stopped being subscribed is a page whose
 * leads stop arriving with nothing here to show for it.
 *
 * @property int $id
 * @property int $meta_account_id
 * @property string $page_id
 * @property string $name
 * @property string|null $category
 * @property string|null $access_token
 * @property bool $is_subscribed
 * @property Carbon|null $subscribed_at
 * @property Carbon|null $last_synced_at
 */
class MetaPage extends Model
{
    /** @use HasFactory<MetaPageFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'meta_account_id',
        'page_id',
        'name',
        'category',
        'access_token',
        'is_subscribed',
        'subscribed_at',
        'last_synced_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'is_subscribed' => 'boolean',
            'subscribed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['page_id', 'name', 'is_subscribed'];
    }

    /**
     * @return BelongsTo<MetaAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAccount::class, 'meta_account_id');
    }

    /**
     * Whether this page can actually be read from.
     *
     * A page with no token of its own is one that was listed during the wizard
     * and never finished connecting — it should read as unfinished rather than
     * quietly failing on the first webhook.
     */
    public function isUsable(): bool
    {
        return is_string($this->access_token) && $this->access_token !== '';
    }
}
