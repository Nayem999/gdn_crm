<?php

namespace App\Domain\Sales\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Sales\Concerns\HasDocumentLines;
use App\Domain\Sales\Contracts\SellingDocument;
use App\Domain\Sales\Enums\PurchaseOrderStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A commitment we have made to a supplier.
 *
 * Its own table rather than a flag on sales orders — see the migration for why.
 * The lines are the same shape and the same calculator totals them, which is
 * the part worth sharing.
 *
 * @property int $id
 * @property string $number
 * @property int|null $supplier_account_id
 * @property string $supplier_name
 * @property string|null $supplier_address
 * @property string|null $supplier_email
 * @property int|null $sales_order_id
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
 * @property Carbon|null $ordered_at
 * @property Carbon|null $received_at
 * @property Carbon|null $cancelled_at
 */
class PurchaseOrder extends Model implements SellingDocument
{
    use HasCustomFields;
    use HasDocumentLines;

    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number', 'supplier_account_id', 'supplier_name', 'supplier_address', 'supplier_email',
        'sales_order_id', 'owner_id', 'status', 'tax_mode', 'order_date', 'expected_date', 'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PurchaseOrderStatus::Draft->value,
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
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['number', 'status', 'supplier_account_id', 'owner_id', 'expected_date'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Purchase order';
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'supplier_account_id');
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function status(): PurchaseOrderStatus
    {
        return PurchaseOrderStatus::tryFrom((string) $this->getAttributeValue('status')) ?? PurchaseOrderStatus::Draft;
    }

    public function taxMode(): TaxMode
    {
        return TaxMode::tryFrom((string) $this->getAttributeValue('tax_mode')) ?? TaxMode::Exclusive;
    }

    /**
     * A purchase order is priced by the supplier, so no price book of ours
     * applies to it.
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
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (['number', 'supplier_name'] as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }
}
