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
    public function __construct(
        /** Link to this account instead of creating one. */
        public ?int $accountId = null,
        /** Overrides the lead's company_name when creating the account. */
        public ?string $accountName = null,

        /** Link to this contact instead of creating one. */
        public ?int $contactId = null,

        /** Deals are optional: a lead can become a customer with nothing in play. */
        public bool $createDeal = true,
        public ?string $dealName = null,
        public ?string $dealValue = null,
        public ?string $dealCloseDate = null,

        /** Who owns the three new records. Defaults to the lead's own owner. */
        public ?int $ownerId = null,
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
            createDeal: (bool) ($attributes['create_deal'] ?? true),
            dealName: $value('deal_name'),
            dealValue: $value('deal_value'),
            dealCloseDate: $value('deal_close_date'),
            ownerId: $id('owner_id'),
        );
    }
}
