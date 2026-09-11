<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Notifications\TemplateRenderer;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowMergeData;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

/**
 * Emails somebody outside the organisation — usually the record itself.
 *
 * Distinct from `SendNotification`, which tells a colleague through the 1.9
 * engine and obeys their preferences. This one writes to a lead or a contact,
 * who has no account here and no preferences to obey, so it goes straight to
 * the mailer.
 *
 * The body is **merged, never compiled**: `TemplateRenderer` scans for
 * `{{ record.first_name }}` and substitutes. An admin-authored template cannot
 * become executable and neither can a customer's own data, which is the whole
 * reason that class exists rather than a Blade string.
 *
 * `template` holds the body inline. Phase 7 brings stored, versioned email
 * templates and the provider drivers behind them; when it lands, this handler's
 * `template` becomes a reference rather than the text, and nothing else about
 * it changes.
 */
class SendEmailHandler implements WorkflowActionHandler
{
    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome
    {
        $address = $this->address((string) $action->setting('recipient'), $context);

        if ($address === null) {
            // Common and not a fault: plenty of leads arrive without an email.
            return WorkflowStepOutcome::skipped('There is no address to send to.');
        }

        $data = WorkflowMergeData::for($context->module(), $context->subject, $context->trigger);
        $renderer = app(TemplateRenderer::class);

        $subject = trim($renderer->render((string) $action->setting('subject'), $data));
        $body = trim($renderer->render((string) $action->setting('template'), $data));

        if ($body === '') {
            return WorkflowStepOutcome::failed('The message body is empty.');
        }

        // Queued, not sent inline: a run must not wait on an SMTP handshake,
        // and a mail server being slow is not a workflow failure.
        Mail::to($address)->queue(new NotificationMail(
            $subject === '' ? $context->workflow->name : $subject,
            $body,
        ));

        return WorkflowStepOutcome::success('Queued an email to '.$address, ['to' => $address]);
    }

    /**
     * Where to write.
     *
     * `record_email` reads the address off the record through the module's own
     * field set, so a module without an email field simply has nobody to write
     * to rather than reaching for an arbitrary column.
     */
    private function address(string $rule, WorkflowContext $context): ?string
    {
        $address = match (true) {
            $rule === 'record_email' => $context->subject?->getAttribute('email'),
            str_starts_with($rule, 'address:') => (string) str($rule)->after('address:'),
            default => null,
        };

        $address = is_string($address) ? trim($address) : '';

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : $address;
    }
}
