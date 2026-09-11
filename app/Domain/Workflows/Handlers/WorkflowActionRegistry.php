<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Workflows\Enums\WorkflowActionType;

/**
 * Which handler runs which kind of step.
 *
 * A stored `type` is matched here and nowhere else, against the enum's cases —
 * so a row in `workflow_actions` cannot name a class to instantiate, which is
 * what it would be doing if this mapped strings to class names from the config.
 */
final class WorkflowActionRegistry
{
    /**
     * @return array<string, class-string<WorkflowActionHandler>>
     */
    public static function all(): array
    {
        return [
            WorkflowActionType::UpdateField->value => UpdateFieldHandler::class,
            WorkflowActionType::CreateRecord->value => CreateRecordHandler::class,
            WorkflowActionType::AssignOwner->value => AssignOwnerHandler::class,
            WorkflowActionType::SendEmail->value => SendEmailHandler::class,
            WorkflowActionType::SendNotification->value => SendNotificationHandler::class,
            WorkflowActionType::CallWebhook->value => CallWebhookHandler::class,
            WorkflowActionType::RequestApproval->value => RequestApprovalHandler::class,
        ];
    }

    public static function for(WorkflowActionType $type): WorkflowActionHandler
    {
        return app(self::all()[$type->value]);
    }
}
