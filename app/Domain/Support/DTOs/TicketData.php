<?php

namespace App\Domain\Support\DTOs;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;

/**
 * The fields a create or update carries, already validated.
 *
 * `status` is deliberately absent, and so are the two stamps that follow it.
 * ChangeTicketStatusAction is the only writer of them, so no form and no
 * payload can declare a ticket resolved — the same rule DealData follows for
 * stage and ActivityData for status. `number` is absent because it is derived.
 */
readonly class TicketData
{
    public function __construct(
        public string $subject,
        public int $ownerId,
        public ?string $description = null,
        public TicketPriority $priority = TicketPriority::Normal,
        public TicketSource $source = TicketSource::Manual,
        public ?int $contactId = null,
        public ?int $accountId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $text = fn (string $key): ?string => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || trim((string) $attributes[$key]) === '' => null,
            default => trim((string) $attributes[$key]),
        };

        $id = fn (string $key): ?int => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || $attributes[$key] === '' => null,
            default => (int) $attributes[$key],
        };

        return new self(
            subject: trim((string) ($attributes['subject'] ?? '')),
            ownerId: (int) ($attributes['owner_id'] ?? 0),
            description: $text('description'),
            priority: TicketPriority::tryFrom((int) ($attributes['priority'] ?? 0)) ?? TicketPriority::Normal,
            source: TicketSource::tryFrom((string) ($attributes['source'] ?? '')) ?? TicketSource::Manual,
            contactId: $id('contact_id'),
            accountId: $id('account_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'source' => $this->source->value,
            'contact_id' => $this->contactId,
            'account_id' => $this->accountId,
            'owner_id' => $this->ownerId,
        ];
    }
}
