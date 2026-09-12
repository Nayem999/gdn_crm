<?php

namespace App\Domain\Sales\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Sales\Concerns\HasDocumentLines;
use App\Domain\Sales\Contracts\SellingDocument;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentState;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A demand for money.
 *
 * Its `status` says what the document is; whether it has been paid is derived
 * from the payments — see `PaymentState` for why those are two different
 * things.
 *
 * @property int $id
 * @property string $number
 * @property int|null $sales_order_id
 * @property int|null $quote_id
 * @property int|null $account_id
 * @property int|null $contact_id
 * @property string $bill_to_name
 * @property string|null $bill_to_address
 * @property string|null $bill_to_email
 * @property int $owner_id
 * @property string $status
 * @property string $tax_mode
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property string|null $terms
 * @property string|null $notes
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property string $amount_paid
 * @property Carbon|null $issued_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 */
class Invoice extends Model implements SellingDocument
{
    use HasCustomFields;
    use HasDocumentLines;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number', 'sales_order_id', 'quote_id', 'account_id', 'contact_id',
        'bill_to_name', 'bill_to_address', 'bill_to_email', 'owner_id',
        'status', 'tax_mode', 'issue_date', 'due_date', 'terms', 'notes',
    ];

    /**
     * `status()`, `taxMode()` and `total()` share their names with columns. See
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => InvoiceStatus::Draft->value,
        'tax_mode' => TaxMode::Exclusive->value,
        'subtotal' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 0,
        'amount_paid' => 0,
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        // amount_paid is left out: every payment already writes its own row,
        // and logging the running total would say the same thing twice.
        return ['number', 'status', 'account_id', 'owner_id', 'due_date', 'total'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Invoice';
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function status(): InvoiceStatus
    {
        return InvoiceStatus::tryFrom((string) $this->getAttributeValue('status')) ?? InvoiceStatus::Draft;
    }

    public function taxMode(): TaxMode
    {
        return TaxMode::tryFrom((string) $this->getAttributeValue('tax_mode')) ?? TaxMode::Exclusive;
    }

    public function documentPriceBook(): ?PriceBook
    {
        return null;
    }

    public function reference(): string
    {
        return $this->number;
    }

    public function isEditable(): bool
    {
        return $this->status()->isEditable();
    }

    /**
     * How much of it has been paid.
     *
     * Derived every time it is asked, from the total and the running sum, so
     * there is nothing to keep in step.
     */
    public function paymentState(): PaymentState
    {
        return PaymentState::for((float) $this->total, (float) $this->amount_paid);
    }

    /**
     * What is still owed. Never negative: an overpayment is a credit to sort
     * out, not a negative balance on this invoice.
     */
    public function outstanding(): float
    {
        return max(0.0, round((float) $this->total - (float) $this->amount_paid, 2));
    }

    /**
     * Whether this is late.
     *
     * A cancelled invoice is not late, and neither is a draft — an invoice
     * nobody sent cannot be overdue. Settled ones are not either, however long
     * they took.
     */
    public function isOverdue(?Carbon $on = null): bool
    {
        return $this->status() === InvoiceStatus::Issued
            && ! $this->paymentState()->isSettled()
            && $this->due_date->copy()->endOfDay()->lt($on ?? Carbon::now());
    }

    /**
     * Issued invoices with something still owed, past their date.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOverdue(Builder $query, ?Carbon $on = null): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), InvoiceStatus::Issued->value)
            ->whereColumn($query->qualifyColumn('amount_paid'), '<', $query->qualifyColumn('total'))
            ->whereDate($query->qualifyColumn('due_date'), '<', ($on ?? Carbon::now())->toDateString());
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), InvoiceStatus::Issued->value)
            ->whereColumn($query->qualifyColumn('amount_paid'), '<', $query->qualifyColumn('total'));
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (['number', 'bill_to_name', 'bill_to_email'] as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }
}
