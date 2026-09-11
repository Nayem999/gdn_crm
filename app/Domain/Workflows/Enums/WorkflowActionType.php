<?php

namespace App\Domain\Workflows\Enums;

/**
 * What a workflow step does.
 *
 * The fixed set from the brief (5.4). Fixed is the point: an action type is
 * stored in a column and read back to decide which code runs, so a type that
 * could be invented by a form would be a stored row choosing what to execute.
 *
 * Each case declares the config keys it requires. 5.4 implements the handlers;
 * this is what lets 5.1 validate a definition without any of them existing yet.
 */
enum WorkflowActionType: string
{
    case UpdateField = 'update_field';
    case CreateRecord = 'create_record';
    case AssignOwner = 'assign_owner';
    case SendEmail = 'send_email';
    case SendNotification = 'send_notification';
    case CallWebhook = 'call_webhook';

    public function label(): string
    {
        return match ($this) {
            self::UpdateField => 'Update a field',
            self::CreateRecord => 'Create a record',
            self::AssignOwner => 'Assign an owner',
            self::SendEmail => 'Send an email',
            self::SendNotification => 'Send a notification',
            self::CallWebhook => 'Call a webhook',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UpdateField => 'Set a field on the record that triggered this workflow.',
            self::CreateRecord => 'Create a related record, such as a follow-up task.',
            self::AssignOwner => 'Hand the record to a user or a team.',
            self::SendEmail => 'Send a templated email.',
            self::SendNotification => 'Notify a user through the notification engine.',
            self::CallWebhook => 'POST the record to an external URL.',
        };
    }

    /**
     * Config keys this action cannot run without.
     *
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return match ($this) {
            // The field, but not the value: setting one to nothing is a real
            // instruction ("clear the company name"), and requiring a value
            // would make it unexpressible.
            self::UpdateField => ['field'],
            self::CreateRecord => ['module'],
            self::AssignOwner => ['assign_to'],
            self::SendEmail => ['template', 'recipient'],
            // Not an event key: every workflow notification is the one event
            // `workflow.notified`, because the event is what the admin matrix
            // governs. See SendNotificationHandler.
            self::SendNotification => ['recipient', 'message'],
            self::CallWebhook => ['url'],
        };
    }

    /**
     * Whether this action writes to the record that triggered the workflow.
     *
     * A delete trigger has no record left to write to by the time actions run,
     * so pairing the two is a definition that can never do anything.
     */
    public function writesToSubject(): bool
    {
        return $this === self::UpdateField || $this === self::AssignOwner;
    }

    /**
     * Whether this action reaches outside the application.
     *
     * The three that do are the ones that need a queue, a retry policy and a
     * timeout, and the ones whose failure should not necessarily stop the rest
     * of the workflow.
     */
    public function isExternal(): bool
    {
        return match ($this) {
            self::SendEmail, self::SendNotification, self::CallWebhook => true,
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
