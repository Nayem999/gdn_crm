<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Actions\DispatchNotificationAction;
use App\Models\User;

/**
 * The one entry point the rest of the application uses.
 *
 * Modules call Notifier::send('ticket.created', $recipients, $data). They never
 * touch a driver, a template or the queue directly.
 */
class Notifier
{
    public function __construct(private readonly DispatchNotificationAction $dispatch) {}

    /**
     * @param  array<int, Recipient>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function send(string $eventKey, array $recipients, array $data = [], ?User $actor = null, ?string $url = null): int
    {
        return $this->dispatch->handle($eventKey, $recipients, $data, $actor, $url);
    }

    /**
     * Tell everyone who administers an area, skipping whoever caused it.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToAdmins(string $eventKey, string $permission, array $data = [], ?User $actor = null, ?string $url = null): int
    {
        return $this->send(
            $eventKey,
            app(AdminRecipients::class)->holding($permission, $actor),
            $data,
            $actor,
            $url
        );
    }
}
