<?php

namespace Database\Factories;

use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketComment>
 */
class TicketCommentFactory extends Factory
{
    protected $model = TicketComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'author_id' => User::factory(),
            'author_name' => null,
            'body' => fake()->paragraph(),
            'is_internal' => false,
            'from_customer' => false,
        ];
    }

    public function on(Ticket $ticket): static
    {
        return $this->state(fn () => ['ticket_id' => $ticket->id]);
    }

    public function by(User $user): static
    {
        return $this->state(fn () => ['author_id' => $user->id, 'from_customer' => false]);
    }

    public function internal(): static
    {
        return $this->state(fn () => ['is_internal' => true, 'from_customer' => false]);
    }

    /**
     * The customer's own words.
     *
     * Author and the internal flag are set together with it, because a
     * customer's reply has no user behind it and cannot be a private note —
     * a fixture that said otherwise would describe something the action refuses
     * to produce.
     */
    public function fromCustomer(?string $name = null): static
    {
        return $this->state(fn () => [
            'author_id' => null,
            'author_name' => $name ?? fake()->name(),
            'from_customer' => true,
            'is_internal' => false,
        ]);
    }
}
