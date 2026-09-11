<?php

namespace App\Domain\Workflows\Runtime;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a step is allowed to know about why it is running.
 *
 * Handed to each handler rather than letting handlers reach for the run and
 * pull whatever they like off it: a step acts on the record the workflow fired
 * for, in the module the workflow was written for, and nothing else.
 */
readonly class WorkflowContext
{
    /**
     * @param  array<string, mixed>  $trigger  What the trigger saw — for a change,
     *                                         the fields that moved and their old values.
     */
    public function __construct(
        public Workflow $workflow,
        public WorkflowRun $run,
        public ?Model $subject,
        public array $trigger = [],
    ) {}

    public function module(): string
    {
        return $this->workflow->module();
    }

    /**
     * The old value of a field, when the workflow fired on a change.
     */
    public function previously(string $field): mixed
    {
        $changed = $this->trigger['changed'] ?? [];

        return is_array($changed) ? ($changed[$field]['from'] ?? null) : null;
    }
}
