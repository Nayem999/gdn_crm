<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Models\NotificationTemplate;

/**
 * Finds the wording for one event on one channel, and merges the data in.
 *
 * An admin-edited template wins; otherwise the registry's default is used, so a
 * new event works the moment it is registered without anyone writing copy.
 */
class TemplateResolver
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{subject: string|null, body: string}
     */
    public function render(string $eventKey, NotificationChannel $channel, array $data): array
    {
        [$subject, $body] = $this->wordingFor($eventKey, $channel);

        return [
            'subject' => $subject === null ? null : $this->renderer->render($subject, $data),
            'body' => $this->renderer->render($body, $data),
        ];
    }

    /**
     * The stored template for this pairing, if an administrator has written one.
     */
    public function stored(string $eventKey, NotificationChannel $channel): ?NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('event', $eventKey)
            ->where('channel', $channel->value)
            ->first();
    }

    /**
     * What the editor should show: the stored wording, or the default to start
     * from.
     *
     * @return array{subject: string|null, body: string}
     */
    public function editable(string $eventKey, NotificationChannel $channel): array
    {
        [$subject, $body] = $this->wordingFor($eventKey, $channel);

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function wordingFor(string $eventKey, NotificationChannel $channel): array
    {
        $event = NotificationEventRegistry::find($eventKey);
        $stored = $this->stored($eventKey, $channel);

        $subject = $channel->hasSubject()
            ? (($stored !== null && $stored->subject !== null) ? $stored->subject : $event?->defaultSubject)
            : null;

        $body = $stored !== null ? $stored->body : ($event?->defaultBody($channel) ?? '');

        return [$subject, $body];
    }
}
