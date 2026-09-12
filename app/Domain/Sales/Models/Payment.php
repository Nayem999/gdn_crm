<?php

namespace App\Domain\Sales\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Models\User;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received against an invoice.
 *
 * Audited, because it is a record of money: who said it arrived, when, and for
 * how much is exactly the sort of thing somebody eventually has to reconstruct.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $amount
 * @property Carbon $paid_on
 * @property string $method
 * @property string|null $reference
 * @property string|null $notes
 * @property int|null $recorded_by
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['invoice_id', 'amount', 'paid_on', 'method', 'reference', 'notes', 'recorded_by'];

    /**
     * `amount()` and `method()` are named after their columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount' => 0,
        'method' => PaymentMethod::BankTransfer->value,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['invoice_id', 'amount', 'paid_on', 'method', 'reference'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function amount(): float
    {
        return (float) $this->getAttributeValue('amount');
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->getAttributeValue('method')) ?? PaymentMethod::Other;
    }
}
