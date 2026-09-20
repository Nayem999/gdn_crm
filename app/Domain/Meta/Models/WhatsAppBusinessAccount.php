<?php

namespace App\Domain\Meta\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\WhatsAppBusinessAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp Business Account — the thing message templates belong to.
 *
 * Kept apart from pages because it genuinely is apart: a WhatsApp business
 * account is not a page, the two are often owned by different businesses, and
 * the token that reads one cannot send through the other. Folding them together
 * would be convenient right up until the first customer whose WhatsApp sits
 * under their agency's business and whose pages do not.
 *
 * @property int $id
 * @property int $meta_account_id
 * @property string $waba_id
 * @property string $name
 * @property string|null $access_token
 * @property bool $is_subscribed
 * @property Carbon|null $last_synced_at
 * @property string|null $token_type
 * @property string|null $token_app_id
 * @property string|null $token_error
 * @property Carbon|null $token_checked_at
 */
class WhatsAppBusinessAccount extends Model
{
    /** @use HasFactory<WhatsAppBusinessAccountFactory> */
    use HasFactory;

    use RecordsActivity;

    protected $table = 'whatsapp_business_accounts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'meta_account_id',
        'waba_id',
        'name',
        'timezone',
        'message_template_namespace',
        'access_token',
        'is_subscribed',
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
            'last_synced_at' => 'datetime',
            'token_checked_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['waba_id', 'name', 'is_subscribed'];
    }

    /**
     * @return BelongsTo<MetaAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAccount::class, 'meta_account_id');
    }

    /**
     * @return HasMany<WhatsAppPhoneNumber, $this>
     */
    public function phoneNumbers(): HasMany
    {
        // The foreign key is named, because Laravel derives
        // `whats_app_business_account_id` from this class name and the column is
        // `whatsapp_business_account_id` — WhatsApp is one word everywhere but
        // in PHP class casing.
        return $this->hasMany(WhatsAppPhoneNumber::class, 'whatsapp_business_account_id');
    }

    /**
     * The number this installation sends from.
     */
    public function defaultNumber(): ?WhatsAppPhoneNumber
    {
        return $this->phoneNumbers()->where('is_default', true)->first()
            ?? $this->phoneNumbers()->first();
    }
}
