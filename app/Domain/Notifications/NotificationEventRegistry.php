<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;

/**
 * The canonical list of events the application notifies about.
 *
 * Nothing outside this registry can be dispatched, put in the matrix, or given a
 * template — the same rule PermissionCatalogue and SettingsRegistry follow. A
 * module adds its events here and they appear in the matrix automatically.
 *
 * The events below are the ones Phase 1 actually raises. Ticket, deal and
 * activity events arrive with their modules; 9.2 in particular fills out the
 * customer / assigned agent / watcher rows that Phase 1 has no records for.
 */
final class NotificationEventRegistry
{
    /**
     * @return array<string, NotificationEvent>
     */
    public static function events(): array
    {
        $events = [
            new NotificationEvent(
                key: 'user.invited',
                label: 'User invited',
                group: 'Users',
                description: 'Someone was invited to join the CRM.',
                recipientTypes: [RecipientType::Admin],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: [
                    'user.name' => 'The name on the invitation',
                    'user.email' => 'The address invited',
                    'actor.name' => 'Who sent the invitation',
                ],
                defaultSubject: 'New invitation sent',
                defaultTemplates: ['*' => '{{actor.name}} invited {{user.email}} to join {{app.name}}.'],
            ),
            new NotificationEvent(
                key: 'user.joined',
                label: 'Invitation accepted',
                group: 'Users',
                description: 'An invited person set up their account.',
                recipientTypes: [RecipientType::Admin],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: [
                    'user.name' => 'Who joined',
                    'user.email' => 'Their address',
                ],
                defaultSubject: 'Someone joined',
                defaultTemplates: ['*' => '{{user.name}} ({{user.email}}) accepted their invitation.'],
            ),
            new NotificationEvent(
                key: 'user.removed',
                label: 'User removed',
                group: 'Users',
                description: 'Someone lost access to the CRM.',
                recipientTypes: [RecipientType::Admin],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: [
                    'user.name' => 'Who was removed',
                    'user.email' => 'Their address',
                    'actor.name' => 'Who removed them',
                ],
                defaultSubject: 'Access removed',
                defaultTemplates: ['*' => '{{actor.name}} removed {{user.name}}\'s access.'],
            ),
            new NotificationEvent(
                key: 'settings.credential_changed',
                label: 'Credential changed',
                group: 'Security',
                description: 'A stored integration credential was replaced or removed.',
                recipientTypes: [RecipientType::Admin],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: [
                    'settings.group' => 'Which settings group',
                    'settings.keys' => 'Which keys changed',
                    'actor.name' => 'Who changed them',
                ],
                defaultSubject: 'A credential was changed',
                defaultTemplates: [
                    '*' => '{{actor.name}} changed {{settings.keys}} in {{settings.group}} settings. The values are not shown here.',
                ],
            ),
            new NotificationEvent(
                key: 'export.ready',
                label: 'Export ready',
                group: 'Exports',
                description: 'A queued export finished and can be downloaded.',
                recipientTypes: [RecipientType::AssignedAgent],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: [
                    'export.module' => 'Which list was exported',
                    'export.rows' => 'How many rows',
                    'export.filename' => 'The file produced',
                ],
                defaultSubject: 'Your export is ready',
                defaultTemplates: ['*' => 'Your {{export.module}} export is ready ({{export.rows}} rows).'],
            ),
            new NotificationEvent(
                key: 'export.failed',
                label: 'Export failed',
                group: 'Exports',
                description: 'A queued export could not be produced.',
                recipientTypes: [RecipientType::AssignedAgent],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: ['export.module' => 'Which list was exported'],
                defaultSubject: 'Your export could not be produced',
                defaultTemplates: ['*' => 'Your {{export.module}} export could not be generated. Please try again.'],
            ),
        ];

        $keyed = [];

        foreach ($events as $event) {
            $keyed[$event->key] = $event;
        }

        return $keyed;
    }

    public static function find(string $key): ?NotificationEvent
    {
        return self::events()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::events());
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::events());
    }

    /**
     * Events arranged by their group, for the matrix screen.
     *
     * @return array<string, array<int, NotificationEvent>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::events() as $event) {
            $grouped[$event->group][] = $event;
        }

        return $grouped;
    }

    /**
     * Merge fields every event can use, on top of its own.
     *
     * @return array<string, string>
     */
    public static function globalMergeFields(): array
    {
        return [
            'app.name' => 'The application name',
            'app.url' => 'A link back to the application',
            'recipient.name' => 'The person being notified',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mergeFieldsFor(string $key): array
    {
        $event = self::find($key);

        return $event === null
            ? self::globalMergeFields()
            : [...$event->mergeFields, ...self::globalMergeFields()];
    }
}
