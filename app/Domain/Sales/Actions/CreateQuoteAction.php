<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Sales\Documents\DocumentNumber;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * Starts a quote.
 *
 * The **billing details are copied**, not linked: a quote says who it was for
 * at the time it was written, so renaming an account or moving a contact does
 * not rewrite a document somebody already holds. The account and contact ids
 * are kept alongside for navigation, and both null out if those records go.
 */
class CreateQuoteAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes): Quote
    {
        return DB::transaction(function () use ($attributes): Quote {
            $account = $this->account($attributes);
            $contact = $this->contact($attributes);
            $deal = $this->deal($attributes);

            $quote = new Quote;

            $quote->forceFill([
                // Taken from the locked counter, so two quotes started in the
                // same second cannot claim the same number.
                'number' => DocumentNumber::next('quote', 'Q'),
                'version' => 1,
                'root_id' => null,
                'account_id' => $account?->id,
                'contact_id' => $contact?->id,
                'deal_id' => $deal?->id,
                ...$this->billTo($attributes, $account, $contact),
                'owner_id' => (int) ($attributes['owner_id'] ?? auth()->id()),
                'status' => QuoteStatus::Draft->value,
                'tax_mode' => (TaxMode::tryFrom((string) ($attributes['tax_mode'] ?? '')) ?? TaxMode::Exclusive)->value,
                'price_book_id' => isset($attributes['price_book_id']) && $attributes['price_book_id'] !== ''
                    ? (int) $attributes['price_book_id']
                    : null,
                'issue_date' => $attributes['issue_date'] ?? now()->toDateString(),
                'valid_until' => $attributes['valid_until'] ?? now()->addDays(30)->toDateString(),
                'intro' => $this->text($attributes, 'intro'),
                'terms' => $this->text($attributes, 'terms'),
                'notes' => $this->text($attributes, 'notes'),
            ])->save();

            return $quote;
        });
    }

    /**
     * Who it is addressed to, as it will print.
     *
     * Taken from what was typed when something was typed, and from the account
     * or contact otherwise — so the common case needs no retyping and the
     * awkward case is still expressible.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function billTo(array $attributes, ?Account $account, ?Contact $contact): array
    {
        $name = trim((string) ($attributes['bill_to_name'] ?? ''));
        $address = trim((string) ($attributes['bill_to_address'] ?? ''));
        $email = trim((string) ($attributes['bill_to_email'] ?? ''));

        return [
            'bill_to_name' => $name !== ''
                ? $name
                : ($account !== null ? $account->name : ($contact !== null ? $contact->fullName() : 'Customer')),
            'bill_to_address' => $address !== '' ? $address : $this->addressOf($account),
            'bill_to_email' => $email !== '' ? $email : ($contact->email ?? ($account === null ? null : $account->email)),
        ];
    }

    private function addressOf(?Account $account): ?string
    {
        if ($account === null) {
            return null;
        }

        $lines = array_filter([
            $account->address_line_1,
            $account->address_line_2,
            $account->city,
            $account->state,
            $account->postal_code,
            $account->country,
        ]);

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes): ?Account
    {
        $id = (int) ($attributes['account_id'] ?? 0);

        return $id > 0 ? Account::query()->whereKey($id)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(array $attributes): ?Contact
    {
        $id = (int) ($attributes['contact_id'] ?? 0);

        return $id > 0 ? Contact::query()->whereKey($id)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function deal(array $attributes): ?Deal
    {
        $id = (int) ($attributes['deal_id'] ?? 0);

        return $id > 0 ? Deal::query()->whereKey($id)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function text(array $attributes, string $key): ?string
    {
        $value = trim((string) ($attributes[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
