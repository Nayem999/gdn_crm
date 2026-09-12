<?php

namespace App\Livewire\Sales;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Pricing\PriceResolver;
use App\Domain\Sales\Actions\ChangeQuoteStatusAction;
use App\Domain\Sales\Actions\CreateQuoteAction;
use App\Domain\Sales\Actions\ReviseQuoteAction;
use App\Domain\Sales\Actions\SaveQuoteLinesAction;
use App\Domain\Sales\Actions\SendQuoteAction;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Pricing\DocumentTotals;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Builds one quote.
 *
 * The header and the lines are edited together and saved together, because a
 * quote's totals come from its lines — saving one without the other would leave
 * a document whose header disagrees with its own rows.
 *
 * **Only a draft is editable.** Once sent, the customer holds a copy, so the
 * screen shows it read-only and offers a new version instead. That rule lives
 * in the policy and the status enum; this screen obeys it rather than
 * reimplementing it.
 */
#[Title('Quote')]
class QuoteBuilder extends Component
{
    use AuthorizesRequests;

    public ?int $quoteId = null;

    public string $accountId = '';

    public string $contactId = '';

    public string $dealId = '';

    public string $billToName = '';

    public string $billToAddress = '';

    public string $billToEmail = '';

    public string $ownerId = '';

    public string $taxMode = 'exclusive';

    public string $priceBookId = '';

    public string $issueDate = '';

    public string $validUntil = '';

    public string $intro = '';

    public string $terms = '';

    public string $notes = '';

    /**
     * The lines, as the browser has them.
     *
     * Typed as loosely as it really is: a wire-bound public property, so the
     * browser decides what arrives. `SaveQuoteLinesAction` is what turns it
     * into lines, dropping rows with nothing on them.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $lines = [];

    public string $sendSubject = '';

    public string $sendIntro = '';

    public ?string $error = null;

    public ?string $saved = null;

    public function mount(?Quote $quote = null): void
    {
        if ($quote?->exists) {
            $this->authorize('view', $quote);
            $this->fillFrom($quote);

            return;
        }

        $this->authorize('create', Quote::class);

        $this->ownerId = (string) auth()->id();
        $this->issueDate = now()->toDateString();
        $this->validUntil = now()->addDays(30)->toDateString();
        $this->addLine();
    }

    // -- What the screen offers --------------------------------------------------

    public function quote(): ?Quote
    {
        return $this->quoteId === null
            ? null
            : Quote::query()->with('lines')->whereKey($this->quoteId)->first();
    }

    public function isEditable(): bool
    {
        $quote = $this->quote();

        return $quote === null || $quote->isEditable();
    }

    /**
     * @return array<int, string>
     */
    public function accountOptions(): array
    {
        return Account::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function contactOptions(): array
    {
        return Contact::query()
            ->visibleTo(auth()->user())
            ->when($this->accountId !== '', fn ($query) => $query->where('account_id', (int) $this->accountId))
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (Contact $contact): array => [$contact->id => $contact->fullName()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int|string, string>
     */
    public function priceBookOptions(): array
    {
        return ['' => 'Catalogue prices', ...PriceBook::query()->active()->orderBy('name')->pluck('name', 'id')->all()];
    }

    /**
     * @return array<int, string>
     */
    public function productOptions(): array
    {
        return Product::query()
            ->visibleTo(auth()->user())
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function taxModeOptions(): array
    {
        return TaxMode::options();
    }

    /**
     * @return array<string, string>
     */
    public function discountTypeOptions(): array
    {
        return ['' => 'No discount', ...DiscountType::options()];
    }

    /**
     * What the quote comes to, as edited — computed from the same calculator
     * that will store the figures, so the screen cannot show one total and the
     * PDF another.
     */
    public function liveTotals(): DocumentTotals
    {
        $quote = $this->quote();

        if ($quote === null) {
            return DocumentTotals::zero();
        }

        return $quote->totals();
    }

    // -- Editing ------------------------------------------------------------------

    public function addLine(): void
    {
        $this->lines[] = [
            'id' => null,
            'product_id' => '',
            'name' => '',
            'description' => '',
            'quantity' => '1',
            'unit_price' => '',
            'discount_type' => '',
            'discount_value' => '',
            'tax_rate' => '',
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function moveLine(int $index, int $by): void
    {
        $target = $index + $by;

        if (! isset($this->lines[$index], $this->lines[$target])) {
            return;
        }

        [$this->lines[$index], $this->lines[$target]] = [$this->lines[$target], $this->lines[$index]];
    }

    /**
     * Choosing a product fills the line in from the catalogue.
     *
     * Through the resolver, so the figure offered is the one the price book
     * would charge — and then editable, because quoting means being able to
     * agree a different number.
     */
    public function updatedLines(mixed $value, ?string $key = null): void
    {
        if ($key === null || ! str_ends_with($key, '.product_id')) {
            return;
        }

        $index = (int) str($key)->before('.product_id')->toString();
        $product = Product::query()->whereKey((int) $value)->first();

        if ($product === null || ! isset($this->lines[$index])) {
            return;
        }

        $book = $this->priceBookId === ''
            ? null
            : PriceBook::query()->whereKey((int) $this->priceBookId)->first();

        $this->lines[$index]['name'] = $product->name;
        $this->lines[$index]['unit_price'] = (string) app(PriceResolver::class)->priceFor($product, $book);
        $this->lines[$index]['tax_rate'] = $product->tax_rate === null ? '' : (string) $product->tax_rate;
    }

    public function save(): void
    {
        $quote = $this->quote();

        $this->authorize($quote === null ? 'create' : 'update', $quote ?? Quote::class);

        $this->validate($this->rules(), [], [
            'billToName' => 'customer name',
            'billToEmail' => 'email address',
            'ownerId' => 'owner',
            'issueDate' => 'issue date',
            'validUntil' => 'valid until',
        ]);

        $quote ??= app(CreateQuoteAction::class)([
            'account_id' => $this->accountId,
            'contact_id' => $this->contactId,
            'deal_id' => $this->dealId,
            'bill_to_name' => $this->billToName,
            'bill_to_address' => $this->billToAddress,
            'bill_to_email' => $this->billToEmail,
            'owner_id' => $this->ownerId,
            'tax_mode' => $this->taxMode,
            'price_book_id' => $this->priceBookId,
            'issue_date' => $this->issueDate,
            'valid_until' => $this->validUntil,
            'intro' => $this->intro,
            'terms' => $this->terms,
            'notes' => $this->notes,
        ]);

        $quote->forceFill([
            'account_id' => $this->accountId === '' ? null : (int) $this->accountId,
            'contact_id' => $this->contactId === '' ? null : (int) $this->contactId,
            'bill_to_name' => $this->billToName,
            'bill_to_address' => $this->billToAddress === '' ? null : $this->billToAddress,
            'bill_to_email' => $this->billToEmail === '' ? null : $this->billToEmail,
            'owner_id' => (int) $this->ownerId,
            'tax_mode' => $this->taxMode,
            'price_book_id' => $this->priceBookId === '' ? null : (int) $this->priceBookId,
            'issue_date' => $this->issueDate,
            'valid_until' => $this->validUntil === '' ? null : $this->validUntil,
            'intro' => $this->intro === '' ? null : $this->intro,
            'terms' => $this->terms === '' ? null : $this->terms,
            'notes' => $this->notes === '' ? null : $this->notes,
        ])->save();

        // The lines and the totals in one action, so the header can never
        // disagree with its own rows.
        app(SaveQuoteLinesAction::class)($quote, $this->lines);

        $this->fillFrom($quote->fresh() ?? $quote);
        $this->saved = 'Saved.';
        $this->error = null;
    }

    public function send(): void
    {
        $quote = $this->quote();

        if ($quote === null) {
            return;
        }

        $this->authorize('send', $quote);

        try {
            app(SendQuoteAction::class)($quote, $this->sendSubject ?: null, $this->sendIntro);
            $this->error = null;
        } catch (RuntimeException $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->fillFrom($quote->fresh() ?? $quote);
        $this->dispatch('notify', type: 'success', message: 'Sent to '.$quote->bill_to_email.'.');
    }

    public function revise(): void
    {
        $quote = $this->quote();

        if ($quote === null) {
            return;
        }

        $this->authorize('revise', $quote);

        try {
            $next = app(ReviseQuoteAction::class)($quote);
        } catch (RuntimeException $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->redirectRoute('quotes.edit', $next, navigate: true);
    }

    public function mark(string $status): void
    {
        $quote = $this->quote();
        $target = QuoteStatus::tryFrom($status);

        if ($quote === null || $target === null) {
            return;
        }

        $this->authorize('send', $quote);

        try {
            app(ChangeQuoteStatusAction::class)($quote, $target);
            $this->error = null;
        } catch (RuntimeException $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->fillFrom($quote->fresh() ?? $quote);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'accountId' => ['nullable', 'integer', 'exists:accounts,id'],
            'contactId' => ['nullable', 'integer', 'exists:contacts,id'],
            'billToName' => ['required', 'string', 'min:2', 'max:255'],
            'billToAddress' => ['nullable', 'string', 'max:1000'],
            'billToEmail' => ['nullable', 'email', 'max:255'],
            'ownerId' => ['required', 'integer', 'exists:users,id'],
            'taxMode' => ['required', Rule::in(array_keys(TaxMode::options()))],
            'priceBookId' => ['nullable', 'integer', 'exists:price_books,id'],
            'issueDate' => ['required', 'date'],
            // A quote that expires before it is issued is one nobody can accept.
            'validUntil' => ['nullable', 'date', 'after_or_equal:issueDate'],
            'lines' => ['array'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    private function fillFrom(Quote $quote): void
    {
        $this->quoteId = $quote->id;
        $this->accountId = (string) $quote->account_id;
        $this->contactId = (string) $quote->contact_id;
        $this->dealId = (string) $quote->deal_id;
        $this->billToName = $quote->bill_to_name;
        $this->billToAddress = (string) $quote->bill_to_address;
        $this->billToEmail = (string) $quote->bill_to_email;
        $this->ownerId = (string) $quote->owner_id;
        $this->taxMode = $quote->taxMode()->value;
        $this->priceBookId = (string) $quote->price_book_id;
        $this->issueDate = $quote->issue_date->toDateString();
        $this->validUntil = $quote->valid_until?->toDateString() ?? '';
        $this->intro = (string) $quote->intro;
        $this->terms = (string) $quote->terms;
        $this->notes = (string) $quote->notes;

        $this->lines = $quote->lines->map(fn ($line): array => [
            'id' => $line->id,
            'product_id' => (string) $line->product_id,
            'name' => $line->name,
            'description' => (string) $line->description,
            'quantity' => (string) $line->quantity(),
            'unit_price' => (string) $line->unit_price,
            'discount_type' => (string) $line->discount_type,
            'discount_value' => (string) $line->discount_value,
            'tax_rate' => (string) $line->tax_rate,
        ])->all();

        if ($this->lines === [] && $quote->isEditable()) {
            $this->addLine();
        }
    }

    public function render(): View
    {
        return view('livewire.sales.quote-builder');
    }
}
