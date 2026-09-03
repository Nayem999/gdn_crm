<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * The database row behind one bell entry.
 *
 * Not queued: the engine's own SendNotification job already runs on the queue,
 * so queueing again would put a job inside a job.
 */
class InAppNotification extends Notification
{
    public function __construct(
        public readonly string $event,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $url = null,
        /** @var array<string, mixed> Whatever the event carried, for the bell. */
        public readonly array $data = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->event,
            'subject' => $this->subject,
            'message' => $this->body,
            'url' => $this->url,
            'data' => $this->data,
        ];
    }
}
