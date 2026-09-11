<?php

namespace App\Domain\Workflows\Triggers;

/**
 * The guard against a workflow triggering itself.
 *
 * A workflow that sets a field fires the update trigger, which runs the
 * workflow, which sets the field again. Nothing about the definition is wrong;
 * the loop is in the wiring, so the wiring is where it is stopped.
 *
 * 5.4 wraps every write an action makes in `while()`, so those writes raise no
 * triggers. That is deliberately blunt — an action cannot start *any* workflow,
 * not merely its own — because the alternative is chasing cycles through three
 * workflows that each update the next one's watched field, and a depth counter
 * only decides how long the loop runs for.
 *
 * A container singleton, so its scope is one request or one queued job.
 */
final class WorkflowSuppressor
{
    private int $depth = 0;

    public function isSuppressed(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Run a callback with triggers switched off.
     *
     * Counted rather than a boolean: nested calls must not have the inner one's
     * exit re-arm triggers while the outer is still writing. The decrement is
     * in a finally, so a throwing action cannot leave the application with
     * triggers permanently off.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function while(callable $callback): mixed
    {
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }
}
