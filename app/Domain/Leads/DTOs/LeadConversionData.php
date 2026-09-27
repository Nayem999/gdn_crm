<?php

namespace App\Domain\Leads\DTOs;

/**
 * What the operator decided when converting a lead.
 *
 * Each of the three records is either linked to something that already exists
 * or created from the lead. Linking is the reason duplicate detection matters
 * here: a lead from an existing customer should join that account, not start a
 * second one.
 */
readonly class LeadConversionData
{
    /**
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, website?: ?string}|null  $newAccount
     *                                                                                                       the new account's own details, from the convert page. Null keeps
     *                                                                                                       the older behaviour of building it from the lead.
     * @param  array<int, array<string, mixed>>|null  $people
     *                                                         the contact persons, each picked (contact_id) or
     *                                                         described on its own fields; the first becomes the
     *                                                         deal's contact. Null keeps the single contactId /
     *                                                         lead-details behaviour.
     */
    public function __construct(
        /** Link to this account instead of creating one. */
        public ?int $accountId = null,
        /** Overrides the lead's company_name when creating the account. */
        public ?string $accountName = null,

        /** Link to this contact instead of creating one. */
        public ?int $contactId = null,
        /** The new contact's name when one is created; the lead's otherwise. */
        public ?string $contactFirstName = null,
        public ?string $contactLastName = null,

        /** Deals are optional: a lead can become a customer with nothing in play. */
        public bool $createDeal = true,
        public ?string $dealName = null,
        public ?string $dealValue = null,
        public ?string $dealCloseDate = null,

        /** Who owns the three new records. Defaults to the lead's own owner. */
        public ?int $ownerId = null,

        public ?array $newAccount = null,
        public ?array $people = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $value = fn (string $key): ?string => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || $attributes[$key] === '' => null,
            default => (string) $attributes[$key],
        };

        $id = fn (string $key): ?int => is_numeric($attributes[$key] ?? null)
            ? (int) $attributes[$key]
            : null;

        return new self(
            accountId: $id('account_id'),
            accountName: $value('account_name'),
            contactId: $id('contact_id'),
            contactFirstName: $value('contact_first_name'),
            contactLastName: $value('contact_last_name'),
            createDeal: (bool) ($attributes['create_deal'] ?? true),
            dealName: $value('deal_name'),
            dealValue: $value('deal_value'),
            dealCloseDate: $value('deal_close_date'),
            ownerId: $id('owner_id'),
            newAccount: is_array($attributes['new_account'] ?? null) ? $attributes['new_account'] : null,
            people: is_array($attributes['people'] ?? null) ? array_values($attributes['people']) : null,
        );
    }
}
