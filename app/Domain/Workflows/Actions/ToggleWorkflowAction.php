<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\WorkflowCache;
use RuntimeException;

/**
 * Switches a workflow on or off.
 *
 * Its own action rather than a checkbox on the form, because switching one on
 * is the moment it starts changing records — so it is the one place that
 * refuses a definition which could not run: no module, no field for a trigger
 * that needs one, or no active steps.
 */
class ToggleWorkflowAction
{
    /**
     * @throws RuntimeException when the workflow could not run as defined
     */
    public function __invoke(Workflow $workflow, bool $active): Workflow
    {
        if ($active) {
            $this->guard($workflow);
        }

        $workflow->forceFill(['is_active' => $active])->save();

        // Switching one on is exactly the moment the memo must not be stale.
        app(WorkflowCache::class)->flush();

        return $workflow->fresh() ?? $workflow;
    }

    private function guard(Workflow $workflow): void
    {
        if ($workflow->actions()->where('is_active', true)->doesntExist()) {
            throw new RuntimeException('Add at least one step before switching this workflow on.');
        }

        if ($workflow->trigger()->needsField() && $workflow->trigger_field === null) {
            throw new RuntimeException('Choose the field this workflow watches before switching it on.');
        }
    }
}
