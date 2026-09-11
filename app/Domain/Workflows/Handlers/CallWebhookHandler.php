<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowMergeData;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posts the record to somewhere else.
 *
 * Three things this is careful about:
 *
 * - **Where it may post.** Checked by `WebhookTarget`, because a configurable
 *   outbound request is an SSRF primitive: without it, anybody who can write a
 *   workflow can make the application read the cloud metadata service or reach
 *   a service listening only on the private network.
 * - **What it sends.** The module's declared field set, through the same merge
 *   data a template gets — not `toArray()`. A column somebody adds to a table
 *   must not silently start leaving the building.
 * - **How long it waits.** Ten seconds, and no redirects followed. A redirect
 *   is how a checked public address becomes an unchecked private one.
 *
 * Not signed. 7.10 owns outbound webhook signing and the encrypted secret
 * storage that goes with it; a secret sitting in this action's JSON config
 * would be a credential in a plaintext column, which is worse than no signature
 * at all.
 */
class CallWebhookHandler implements WorkflowActionHandler
{
    private const TIMEOUT_SECONDS = 10;

    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome
    {
        $url = trim((string) $action->setting('url'));
        $refusal = WebhookTarget::refuse($url);

        if ($refusal !== null) {
            return WorkflowStepOutcome::failed($refusal);
        }

        $payload = [
            'workflow' => $context->workflow->name,
            'module' => $context->module(),
            'trigger' => $context->run->trigger_event,
            'record' => WorkflowMergeData::for($context->module(), $context->subject, $context->trigger)['record'] ?? null,
        ];

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                // A redirect turns an address that passed the check into one
                // that did not.
                ->withoutRedirecting()
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException $unreachable) {
            return WorkflowStepOutcome::failed('Could not reach '.$url.': '.$unreachable->getMessage());
        }

        if ($response->failed()) {
            return WorkflowStepOutcome::failed(
                $url.' answered '.$response->status().'.',
                ['status' => $response->status()],
            );
        }

        return WorkflowStepOutcome::success(
            'Posted to '.$url.' ('.$response->status().')',
            ['status' => $response->status()],
        );
    }
}
