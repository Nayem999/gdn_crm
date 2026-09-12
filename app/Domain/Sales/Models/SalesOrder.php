<?php

namespace App\Domain\Sales\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Deals\Models\Deal;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Sales\Concerns\HasDocumentLines;
use App\Domain\Sales\Contracts\SellingDocument;
use App\Domain\Sales\Enums\SalesOrderStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A commitment made to a customer.
 *
 * Carries its own lines and its own snapshot of who it is for, so it stands on
 * its own after the quote it came from is revised, superseded or removed.
 *
 * @property int $id
 * @property string $number
 * @property int|null $quote_id
 * @property int|null $account_id
 * @property int|null $contact_id
 * @property int|null $deal_id
 * @property string $bill_to_name
 * @property string|null $bill_to_address
 * @property string|null $bill_to_email
 * @property string|null $customer_reference
 * @property int $owner_id
 * @property string $status
 * @property string $tax_mode
 * @property Carbon $order_date
 * @property Carbon|null $expected_date
 * @property string|null $notes
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $cancelled_at
 */
class SalesOrder extends Model implements SellingDocument
{
    use HasCustomFields;
    use HasDocumentLines;

    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number', 'quote_id', 'account_id', 'contact_id', 'deal_id',
        'bill_to_name', 'bill_to_address', 'bill_to_email', 'customer_reference',
        'owner_id', 'status', 'tax_mode', 'order_date', 'expected_date', 'notes',
    ];

    /**
     * `status()`, `taxMode()` and `total()` share their names with columns. See
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => SalesOrderStatus::Draft->value,
        'tax_mode' => TaxMode::Exclusive->value,
        'subtotal' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['number', 'status', 'account_id', 'owner_id', 'expected_date', 'customer_reference'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Sales order';
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
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
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function status(): SalesOrderStatus
    {
        return SalesOrderStatus::tryFrom((string) $this->getAttributeValue('status')) ?? SalesOrderStatus::Draft;
    }

    public function taxMode(): TaxMode
    {
        return TaxMode::tryFrom((string) $this->getAttributeValue('tax_mode')) ?? TaxMode::Exclusive;
    }

    /**
     * An order prices from the document it came from, not from a book: the
     * figures were agreed when the quote was accepted.
     */
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
     * @param  Builder<SalesOrder>  $query
     * @return Builder<SalesOrder>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (['number', 'bill_to_name', 'customer_reference'] as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }
}
