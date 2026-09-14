<?php

namespace App\Domain\Meta\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\WhatsAppPhoneNumberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One number a WhatsApp business account can send from.
 *
 * `is_default` picks the one this installation actually uses. One at a time,
 * enforced by the action rather than the schema: a CRM that sent from whichever
 * number came back first would produce conversations the customer cannot reply
 * to, because their reply goes to the number they were written from and the
 * webhook arrives under a different phone number id.
 *
 * `quality_rating` and `messaging_limit` are Meta's own, and they are not
 * decoration: between them they decide how many conversations this number may
 * start today, and a send refused for exceeding a limit reads as a broken
 * integration unless the screen can say otherwise.
 *
 * @property int $id
 * @property int $whatsapp_business_account_id
 * @property string $phone_number_id
 * @property string $display_number
 * @property string|null $verified_name
 * @property string|null $quality_rating
 * @property string|null $messaging_limit
 * @property bool $is_default
 */
class WhatsAppPhoneNumber extends Model
{
    /** @use HasFactory<WhatsAppPhoneNumberFactory> */
    use HasFactory;

    use RecordsActivity;

    protected $table = 'whatsapp_phone_numbers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'whatsapp_business_account_id',
        'phone_number_id',
        'display_number',
        'verified_name',
        'quality_rating',
        'messaging_limit',
        'is_default',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['phone_number_id', 'display_number', 'verified_name', 'is_default'];
    }

    /**
     * @return BelongsTo<WhatsAppBusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppBusinessAccount::class, 'whatsapp_business_account_id');
    }

    /**
     * What to show beside the number, when Meta has told us anything worth
     * showing.
     */
    public function healthNote(): ?string
    {
        return match ($this->quality_rating) {
            'RED' => 'Meta has rated this number poorly. Message limits may be reduced.',
            'YELLOW' => 'Meta has flagged this number. Watch how customers respond to your messages.',
            default => null,
        };
    }
}
