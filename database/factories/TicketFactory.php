<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::New->value,
            'priority' => TicketPriority::Normal->value,
            'source' => TicketSource::Manual->value,
            'contact_id' => null,
            'account_id' => null,
            'owner_id' => User::factory(),
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    /**
     * A status and the stamps that belong with it.
     *
     * Set together, because a fixture saying "resolved" with no resolved_at
     * describes something the application cannot produce, and a test written
     * against it proves nothing.
     */
    public function withStatus(TicketStatus $status, ?Carbon $at = null): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'resolved_at' => $status->marksResolved() ? ($at ?? now()) : null,
            'closed_at' => $status === TicketStatus::Closed ? ($at ?? now()) : null,
        ]);
    }

    public function resolved(?Carbon $at = null): static
    {
        return $this->withStatus(TicketStatus::Resolved, $at);
    }

    public function closed(?Carbon $at = null): static
    {
        return $this->withStatus(TicketStatus::Closed, $at);
    }

    public function withPriority(TicketPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority->value]);
    }

    public function from(TicketSource $source): static
    {
        return $this->state(fn () => ['source' => $source->value]);
    }

    public function forContact(Contact $contact): static
    {
        return $this->state(fn () => [
            'contact_id' => $contact->id,
            // Kept consistent: a ticket for somebody at another account is a
            // thing the create action refuses, so a fixture must not build one.
            'account_id' => $contact->account_id,
        ]);
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn () => ['account_id' => $account->id]);
    }
}
