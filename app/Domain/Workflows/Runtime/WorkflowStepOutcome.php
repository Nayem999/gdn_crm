<?php

namespace App\Domain\Workflows\Runtime;

use App\Domain\Workflows\Enums\WorkflowRunStatus;

/**
 * What one step did, in the words the log will show.
 *
 * A handler returns one of these rather than throwing or returning a bare bool,
 * because the log's job is to say *what happened* — "set status to contacted",
 * "no address to send to" — and a boolean cannot.
 *
 * The distinction between skipped and failed is the one that matters: skipped
 * means there was nothing to do and that is fine (no address, no owner to
 * assign); failed means the step should have worked and did not, and 5.8 offers
 * to retry it.
 */
readonly class WorkflowStepOutcome
{
    /**
     * @param  array<string, mixed>  $result  What it actually did — the field it
     *                                        set, the id of the record it made.
     */
    private function __construct(
        public WorkflowRunStatus $status,
        public string $message,
        public array $result = [],
        /** Whether the run stops here and waits for somebody. */
        public bool $pauses = false,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function success(string $message, array $result = []): self
    {
        return new self(WorkflowRunStatus::Success, $message, $result);
    }

    /**
     * Nothing to do, and that is not a problem.
     */
    public static function skipped(string $message): self
    {
        return new self(WorkflowRunStatus::Skipped, $message);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function failed(string $message, array $result = []): self
    {
        return new self(WorkflowRunStatus::Failed, $message, $result);
    }

    /**
     * The step asked a person, and the run stops here until they answer.
     *
     * Not a status a *step* ends in — the step itself succeeded in asking — so
     * it is carried beside the status rather than in it. What it changes is the
     * run: everything after this step waits.
     *
     * @param  array<string, mixed>  $result
     */
    public static function awaitingApproval(string $message, array $result = []): self
    {
        return new self(WorkflowRunStatus::Success, $message, $result, pauses: true);
    }

    public function failedOutright(): bool
    {
        return $this->status === WorkflowRunStatus::Failed;
    }
}
