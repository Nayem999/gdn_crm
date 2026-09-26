<?php

namespace App\Domain\Notifications;

use App\Domain\Activities\ActivityMergeData;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Support\TicketMergeData;

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
                key: 'import.finished',
                label: 'Import finished',
                group: 'Imports',
                description: 'A queued import finished, whether or not every row landed.',
                recipientTypes: [RecipientType::AssignedAgent],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: [
                    'import.module' => 'Which list was imported into',
                    'import.filename' => 'The file that was uploaded',
                    'import.imported' => 'How many rows landed',
                    'import.failed' => 'How many rows were refused',
                ],
                defaultSubject: 'Your import has finished',
                defaultTemplates: [
                    '*' => '{{import.filename}} finished: {{import.imported}} rows imported into {{import.module}}, {{import.failed}} refused.',
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
            new NotificationEvent(
                key: 'integration.failing',
                label: 'An integration is failing',
                group: 'Inbound data',
                description: 'A data source has failed several deliveries in a row and is probably broken.',
                recipientTypes: [RecipientType::Admin],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: [
                    'source.name' => 'Which source',
                    'source.failures' => 'How many in a row',
                    'source.error' => 'What the last one said',
                ],
                defaultSubject: 'An inbound source is failing',
                defaultTemplates: [
                    '*' => '{{source.name}} has failed {{source.failures}} deliveries in a row. The last one said: {{source.error}}',
                ],
            ),
            new NotificationEvent(
                key: 'meta.lead_received',
                label: 'Lead arrived from Meta',
                group: 'Marketing',
                description: 'Somebody filled in a Facebook lead form and a lead was created for it.',
                recipientTypes: [RecipientType::AssignedAgent],
                // Email as well as in-app, which is a louder default than most
                // things here and is deliberate: a lead ad is answered in
                // minutes or not at all — the customer is on Facebook now — and
                // an alert nobody sees until they next open the CRM is an alert
                // that arrived too late. A high-volume campaign turns it off in
                // one cell of the matrix.
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: [
                    'lead.name' => 'Who filled the form in',
                    'lead.form' => 'Which form they used',
                    'lead.campaign' => 'The campaign the advertisement belonged to',
                ],
                defaultSubject: 'New Facebook lead: {{lead.name}}',
                defaultTemplates: [
                    '*' => '{{lead.name}} filled in {{lead.form}} ({{lead.campaign}}). They are expecting to hear back.',
                ],
            ),
            new NotificationEvent(
                key: 'leads.assignment_escalated',
                label: 'Lead assignment escalated',
                group: 'Leads',
                description: 'Nobody on the current priority tier has acted within the configured window, so the next tier was told.',
                recipientTypes: [RecipientType::AssignedAgent],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: [
                    'lead.name' => "The lead's name",
                    'lead.hours' => 'How long it had gone unactioned',
                ],
                defaultSubject: 'A lead needs attention: {{lead.name}}',
                defaultTemplates: [
                    '*' => '{{lead.name}} has had no action for {{lead.hours}} hours. It is now your turn to work it.',
                ],
            ),
            new NotificationEvent(
                key: 'leads.assigned',
                label: 'Lead assigned to you',
                group: 'Leads',
                description: 'Somebody was added to a lead as an assignee, or made its owner. The person who made the change is not told.',
                recipientTypes: [RecipientType::AssignedAgent],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: [
                    'lead.name' => "The lead's name",
                    'lead.company' => 'The company they are from',
                    'lead.role' => 'What the person was made: "an assignee" or "the owner"',
                    'lead.by' => 'Who made the change, or "an automation"',
                ],
                defaultSubject: 'Lead assigned to you: {{lead.name}}',
                defaultTemplates: [
                    '*' => '{{lead.by}} made you {{lead.role}} of {{lead.name}} ({{lead.company}}).',
                ],
            ),
            new NotificationEvent(
                key: 'activity.assigned',
                label: 'Activity assigned',
                group: 'Activities',
                description: 'A task, call or meeting was put on somebody else\'s list.',
                recipientTypes: [RecipientType::AssignedAgent],
                defaultChannels: [NotificationChannel::InApp],
                // One declaration for both activity events: ActivityMergeData
                // supplies exactly these, and a template naming a field the
                // event does not declare is refused at save time.
                mergeFields: ActivityMergeData::mergeFields(),
                defaultSubject: 'A new activity for you',
                defaultTemplates: [
                    '*' => '{{activity.type}}: {{activity.subject}}, due {{activity.due}} ({{activity.related}}).',
                ],
            ),
            new NotificationEvent(
                key: 'activity.reminder',
                label: 'Activity reminder',
                group: 'Activities',
                description: 'An activity is coming up and its reminder lead time has arrived.',
                recipientTypes: [RecipientType::AssignedAgent],
                // Email as well as in-app: the point of a reminder is to reach
                // somebody who is not currently looking at the CRM.
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: ActivityMergeData::mergeFields(),
                defaultSubject: 'Reminder: {{activity.subject}}',
                defaultTemplates: [
                    '*' => '{{activity.type}}: {{activity.subject}} is due {{activity.due}} ({{activity.related}}).',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.created',
                label: 'Ticket raised',
                group: 'Support',
                description: 'A customer reported a problem and a ticket was opened for it.',
                recipientTypes: [RecipientType::Customer, RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                // SMS is available on every ticket event and off on all of
                // them: it costs money per message and a support desk can
                // generate a great many. An administrator turns it on for the
                // events and audiences that are worth it.
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: TicketMergeData::mergeFields(),
                defaultSubject: '{{ticket.reference}}: {{ticket.subject}}',
                defaultTemplates: [
                    '*' => 'Ticket {{ticket.reference}} has been opened: {{ticket.subject}}. It is with {{ticket.agent}}.',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.status_changed',
                label: 'Ticket status changed',
                group: 'Support',
                description: 'A ticket moved from one status to another, short of being resolved or closed.',
                recipientTypes: [RecipientType::Customer, RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: TicketMergeData::moveFields(),
                defaultSubject: '{{ticket.reference}} is now {{ticket.new_status}}',
                defaultTemplates: [
                    '*' => '{{ticket.reference}} ({{ticket.subject}}) moved from {{ticket.old_status}} to {{ticket.new_status}}.',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.priority_changed',
                label: 'Ticket priority changed',
                group: 'Support',
                description: 'A ticket was retriaged up or down.',
                // No Customer: how urgently we are treating something is our
                // judgement, and "we have downgraded you to low" is not a
                // message anybody means to send. Leaving the type off means the
                // matrix has no cell to switch on by accident.
                recipientTypes: [RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: TicketMergeData::priorityFields(),
                defaultSubject: '{{ticket.reference}} is now {{ticket.new_priority}} priority',
                defaultTemplates: [
                    '*' => '{{ticket.reference}} ({{ticket.subject}}) went from {{ticket.old_priority}} to {{ticket.new_priority}} priority.',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.assigned',
                label: 'Ticket assigned',
                group: 'Support',
                description: 'A ticket was handed to an agent.',
                // Also no Customer: which of us is holding it is not their
                // business, and somebody who hears every reassignment reads it
                // as being passed around.
                recipientTypes: [RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: TicketMergeData::mergeFields(),
                defaultSubject: '{{ticket.reference}} is yours',
                defaultTemplates: [
                    '*' => '{{ticket.reference}} ({{ticket.subject}}) is now with {{ticket.agent}}. It is {{ticket.priority}} priority and {{ticket.status}}.',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.comment_added',
                label: 'Ticket reply added',
                group: 'Support',
                description: 'Somebody replied on a ticket. An internal note never reaches the customer, whatever this row says.',
                recipientTypes: [RecipientType::Customer, RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: TicketMergeData::commentFields(),
                defaultSubject: 'Re: {{ticket.reference}} {{ticket.subject}}',
                defaultTemplates: [
                    '*' => '{{comment.author}} replied on {{ticket.reference}}: {{comment.excerpt}}',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.resolved',
                label: 'Ticket resolved',
                group: 'Support',
                description: 'A ticket was marked resolved. Its own event, so a desk can tell the customer about this and nothing else.',
                recipientTypes: [RecipientType::Customer, RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: TicketMergeData::moveFields(),
                defaultSubject: '{{ticket.reference}} has been resolved',
                defaultTemplates: [
                    '*' => '{{ticket.reference}} ({{ticket.subject}}) has been marked resolved by {{ticket.agent}}. Reply on the ticket if it is not fixed.',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.closed',
                label: 'Ticket closed',
                group: 'Support',
                description: 'A ticket was closed for good.',
                recipientTypes: [RecipientType::Customer, RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                // Quieter than resolving on purpose: resolving is the message
                // that matters to a customer, and closing usually follows it.
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: TicketMergeData::moveFields(),
                defaultSubject: '{{ticket.reference}} has been closed',
                defaultTemplates: [
                    '*' => '{{ticket.reference}} ({{ticket.subject}}) has been closed.',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.sla_warning',
                label: 'SLA running out',
                group: 'Support',
                description: 'A ticket is approaching the time we promised and has not been answered or resolved yet.',
                // No Customer: telling somebody "we are about to be late" is
                // announcing a failure in advance and gives them nothing to act
                // on.
                recipientTypes: [RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                defaultChannels: [NotificationChannel::InApp],
                mergeFields: TicketMergeData::slaFields(),
                defaultSubject: '{{ticket.reference}}: {{sla.remaining}} on the {{sla.promise}}',
                defaultTemplates: [
                    '*' => '{{ticket.reference}} ({{ticket.subject}}) is due a {{sla.promise}} by {{sla.due}} — {{sla.remaining}}.',
                ],
            ),
            new NotificationEvent(
                key: 'ticket.sla_breached',
                label: 'SLA breached',
                group: 'Support',
                description: 'A ticket went past the time we promised. It is escalated a priority when this fires.',
                recipientTypes: [RecipientType::AssignedAgent, RecipientType::Admin, RecipientType::Watcher],
                // Email as well as in-app: a breach that nobody sees until they
                // next open the CRM is a breach nobody acted on.
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: TicketMergeData::slaFields(),
                defaultSubject: '{{ticket.reference}} has missed its {{sla.promise}}',
                defaultTemplates: [
                    '*' => '{{ticket.reference}} ({{ticket.subject}}) missed its {{sla.promise}}, due {{sla.due}} under {{sla.policy}}. It is {{sla.remaining}} and is now {{ticket.priority}} priority, with {{ticket.agent}}.',
                ],
            ),
            new NotificationEvent(
                key: 'approval.requested',
                label: 'Approval waiting',
                group: 'Workflows',
                description: 'An automation stopped and is waiting for somebody to approve or reject.',
                recipientTypes: [RecipientType::AssignedAgent],
                // Email as well as in-app: a workflow is sitting still until
                // this person answers, and an approval nobody notices is the
                // failure mode of the whole feature.
                defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
                mergeFields: [
                    'approval.summary' => 'What is being approved',
                    'approval.module' => 'Which module the record is in',
                    'approval.workflow' => 'Which automation asked',
                    'approval.due' => 'When the answer is needed by',
                    'app.name' => 'The application name',
                ],
                defaultSubject: 'Approval needed: {{approval.summary}}',
                defaultTemplates: [
                    '*' => '{{approval.workflow}} is waiting on you: {{approval.summary}}. Needed by {{approval.due}}.',
                ],
            ),
            new NotificationEvent(
                key: 'workflow.notified',
                label: 'Workflow notification',
                group: 'Workflows',
                description: 'An automation told somebody about a record.',
                recipientTypes: [RecipientType::AssignedAgent],
                defaultChannels: [NotificationChannel::InApp],
                // One event for every workflow, not one per workflow. The event
                // is what the admin matrix governs, and a matrix that grew a row
                // each time somebody wrote an automation would be unreadable —
                // while letting a workflow *choose* its event would let it
                // borrow another event's channels and audience.
                mergeFields: [
                    'workflow.name' => 'Which automation fired',
                    'message' => 'What the automation was set up to say',
                    'module.label' => 'Which module the record is in',
                    'record.label' => 'The record it is about',
                    'record.id' => 'That record\'s id',
                    'app.name' => 'The application name',
                ],
                defaultSubject: '{{workflow.name}}',
                defaultTemplates: [
                    '*' => '{{workflow.name}}: {{message}} ({{module.label}} — {{record.label}})',
                ],
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
