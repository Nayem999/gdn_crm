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
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One quote, at one version.
 *
 * A **document**, not a view of a deal: the billing details, the prices and the
 * totals are all copies taken when it was written. The customer has their own
 * copy, and a quote that changed when the account was renamed would no longer
 * be the thing they were sent.
 *
 * Versions are separate rows sharing a `number`, linked by `root_id`. Both
 * remain readable exactly as they were sent, which is the one thing a disputed
 * quote needs.
 *
 * @property int $id
 * @property string $number
 * @property int $version
 * @property int|null $root_id
 * @property int|null $account_id
 * @property int|null $contact_id
 * @property int|null $deal_id
 * @property string $bill_to_name
 * @property string|null $bill_to_address
 * @property string|null $bill_to_email
 * @property int $owner_id
 * @property string $status
 * @property string $tax_mode
 * @property int|null $price_book_id
 * @property Carbon $issue_date
 * @property Carbon|null $valid_until
 * @property string|null $intro
 * @property string|null $terms
 * @property string|null $notes
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $superseded_at
 */
class Quote extends Model implements SellingDocument
{
    use HasCustomFields;
    use HasDocumentLines;

    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'version',
        'root_id',
        'account_id',
        'contact_id',
        'deal_id',
        'bill_to_name',
        'bill_to_address',
        'bill_to_email',
        'owner_id',
        'status',
        'tax_mode',
        'price_book_id',
        'issue_date',
        'valid_until',
        'intro',
        'terms',
        'notes',
    ];

    /**
     * `status()`, `taxMode()` and `total()` all share a name with a column, so
     * each needs a default — see .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => QuoteStatus::Draft->value,
        'tax_mode' => TaxMode::Exclusive->value,
        'version' => 1,
        'subtotal' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'issue_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        // Not the totals: they are derived from the lines, and logging them
        // would record the same edit twice in different words.
        return ['number', 'version', 'status', 'account_id', 'owner_id', 'valid_until', 'tax_mode'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Quote';
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

    /**
     * @return BelongsTo<PriceBook, $this>
     */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    /**
     * Every version of this quote, this one included.
     *
     * @return HasMany<Quote, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'root_id', 'id');
    }

    public function documentPriceBook(): ?PriceBook
    {
        return $this->priceBook;
    }

    public function status(): QuoteStatus
    {
        return QuoteStatus::tryFrom((string) $this->getAttributeValue('status')) ?? QuoteStatus::Draft;
    }

    public function taxMode(): TaxMode
    {
        return TaxMode::tryFrom((string) $this->getAttributeValue('tax_mode')) ?? TaxMode::Exclusive;
    }

    /**
     * The id every version of this quote shares.
     *
     * Version 1 carries no `root_id` — there is no chicken-and-egg on insert —
     * so it is its own root.
     */
    public function rootId(): int
    {
        return $this->root_id ?? $this->id;
    }

    /**
     * How it is referred to: "Q-2026-0007 v2", or just the number at v1.
     */
    public function reference(): string
    {
        return $this->version > 1
            ? $this->number.' v'.$this->version
            : $this->number;
    }

    /**
     * Whether the clock has run out on this quote.
     *
     * Only a sent quote can expire: a draft nobody sent has not lapsed, and an
     * accepted one is already settled.
     */
    public function hasLapsed(?Carbon $on = null): bool
    {
        return $this->status() === QuoteStatus::Sent
            && $this->valid_until !== null
            && $this->valid_until->copy()->endOfDay()->lt($on ?? Carbon::now());
    }

    public function isEditable(): bool
    {
        return $this->status()->isEditable();
    }

    /**
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), [
            QuoteStatus::Draft->value,
            QuoteStatus::Sent->value,
        ]);
    }

    /**
     * The latest version of each quote, which is what a list should show.
     *
     * A superseded row is history: showing it beside its replacement means a
     * list where the same quote appears three times and only one of them
     * matters.
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeCurrentVersions(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), '!=', QuoteStatus::Superseded->value);
    }

    /**
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
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
