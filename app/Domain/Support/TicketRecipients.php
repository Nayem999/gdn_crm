<?php

namespace App\Domain\Support;

use App\Domain\Notifications\AdminRecipients;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Recipient;
use App\Domain\Support\Models\Ticket;
use App\Models\User;

/**
 * Who hears about a ticket.
 *
 * The engine deliberately does not resolve recipients — only the module owning
 * a record knows who its customer, its agent and its watchers are. This is that
 * knowledge for the support module, in one place, so seven events cannot each
 * decide it slightly differently.
 *
 * Admins come from AdminRecipients, the one resolver the engine does own,
 * because "everybody holding this permission" means the same thing everywhere.
 */
class TicketRecipients
{
    /**
     * The permission that makes somebody a support administrator.
     */
    public const ADMIN_PERMISSION = 'tickets.assign';

    public function __construct(private readonly AdminRecipients $admins) {}

    /**
     * Everyone: the customer, the agent, the administrators and the watchers.
     *
     * Duplicates are fine — the engine drops a person who appears twice, so an
     * agent who is also an admin still hears once.
     *
     * @return array<int, Recipient>
     */
    public function all(Ticket $ticket, ?User $actor = null): array
    {
        return [
            ...$this->customer($ticket),
            ...$this->agent($ticket),
            ...$this->admins->holding(self::ADMIN_PERMISSION, $actor),
            ...$this->watchers($ticket),
        ];
    }

    /**
     * Everyone on our side: the agent, the administrators and the watchers.
     *
     * What an internal comment reaches. The customer is left out by
     * construction rather than by remembering to filter later.
     *
     * @return array<int, Recipient>
     */
    public function internal(Ticket $ticket, ?User $actor = null): array
    {
        return [
            ...$this->agent($ticket),
            ...$this->admins->holding(self::ADMIN_PERMISSION, $actor),
            ...$this->watchers($ticket),
        ];
    }

    /**
     * The person who raised it, if there is one and we can reach them.
     *
     * A list of none or one rather than a nullable, so callers can splat it
     * without asking. Plenty of tickets have no contact attached — see the
     * "unlinked" chip on the queue — and a ticket for a contact with no email
     * and no mobile has nowhere to send anything.
     *
     * @return array<int, Recipient>
     */
    public function customer(Ticket $ticket): array
    {
        $contact = $ticket->contact;

        if ($contact === null) {
            return [];
        }

        $email = self::filled($contact->email);
        $phone = self::filled($contact->mobile) ?? self::filled($contact->phone);

        if ($email === null && $phone === null) {
            return [];
        }

        // Built directly rather than through Recipient::address(), which insists
        // on an address: a contact we hold only a mobile number for is still
        // reachable, on the one channel that can use it.
        return [new Recipient(
            type: RecipientType::Customer,
            address: $email,
            name: $contact->fullName(),
            // Carried separately: an SMS to an email address is a delivery
            // failure, and the two are different columns on a contact.
            phone: $phone,
        )];
    }

    /**
     * @return array<int, Recipient>
     */
    public function agent(Ticket $ticket): array
    {
        $owner = $ticket->owner;

        return $owner === null ? [] : [Recipient::user($owner, RecipientType::AssignedAgent)];
    }

    /**
     * @return array<int, Recipient>
     */
    public function watchers(Ticket $ticket): array
    {
        return $ticket->watchers
            ->map(fn (User $user) => Recipient::user($user, RecipientType::Watcher))
            ->values()
            ->all();
    }

    /**
     * @return array<int, Recipient>
     */
    public function administrators(?User $actor = null): array
    {
        return $this->admins->holding(self::ADMIN_PERMISSION, $actor);
    }

    /**
     * A column's value, or null when it is there but empty — `??` alone treats
     * an empty string as a usable address.
     */
    private static function filled(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
