<?php

namespace App\Domain\Leads\DTOs;

/**
 * The fields a create or update carries, already validated.
 */
readonly class LeadData
{
    /**
     * @param  array<int, array{user_id: int, priority: ?int}>|null  $assignees
     *                                                                           Null means "leave the assignee set alone" —
     *                                                                           what an ingested update carries, which has no
     *                                                                           opinion on who is working the lead. An empty
     *                                                                           array is refused by SyncLeadAssigneesAction
     *                                                                           rather than treated as "unassign everybody";
     *                                                                           removing the last assignee is its own,
     *                                                                           deliberate action.
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $jobTitle = null,
        public ?string $companyName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $mobile = null,
        public ?string $website = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $source = null,
        public ?string $estimatedValue = null,
        public ?string $description = null,
        public ?array $assignees = null,
        public ?int $campaignId = null,
        public bool $setsCampaign = false,
        public ?int $leadOwnerId = null,
        public bool $setsLeadOwner = false,
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

        return new self(
            firstName: trim((string) ($attributes['first_name'] ?? '')),
            lastName: trim((string) ($attributes['last_name'] ?? '')),
            jobTitle: $value('job_title'),
            companyName: $value('company_name'),
            email: $value('email'),
            phone: $value('phone'),
            mobile: $value('mobile'),
            website: $value('website'),
            addressLine1: $value('address_line_1'),
            addressLine2: $value('address_line_2'),
            city: $value('city'),
            state: $value('state'),
            postalCode: $value('postal_code'),
            country: $value('country'),
            source: $value('source'),
            estimatedValue: $value('estimated_value'),
            description: $value('description'),
            assignees: $attributes['assignees'] ?? null,
            campaignId: ($attributes['campaign_id'] ?? '') === '' ? null : (int) $attributes['campaign_id'],
            setsCampaign: array_key_exists('campaign_id', $attributes),
            leadOwnerId: ($attributes['lead_owner_id'] ?? '') === '' ? null : (int) $attributes['lead_owner_id'],
            setsLeadOwner: array_key_exists('lead_owner_id', $attributes),
        );
    }

    /**
     * The stored columns, keeping nulls so an update clears a field the user
     * emptied. Status is absent on purpose: it belongs to
     * ChangeLeadStatusAction, which is the only thing that may move it.
     * Assignees are absent too: they live in their own table, written by
     * SyncLeadAssigneesAction rather than a column on this one.
     *
     * The campaign and the lead owner are written only when the caller sent
     * them: capture forms, ingestion and Meta updates carry neither key, and
     * treating that as "clear it" would wipe what somebody set in the app.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $campaign = $this->setsCampaign ? ['campaign_id' => $this->campaignId] : [];
        $owner = $this->setsLeadOwner ? ['lead_owner_id' => $this->leadOwnerId] : [];

        return [
            ...$campaign,
            ...$owner,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'job_title' => $this->jobTitle,
            'company_name' => $this->companyName,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'website' => $this->website,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'source' => $this->source,
            'estimated_value' => $this->estimatedValue,
            'description' => $this->description,
        ];
    }
}
