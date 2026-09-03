<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Services\LeadQualification;
use RuntimeException;

/**
 * The only thing that moves a lead's status.
 *
 * Every route in — the form, a board drag, a later API call — comes through
 * here, so the transition rules in LeadStatus are enforced once. LeadData
 * deliberately has no status field for the same reason.
 */
class ChangeLeadStatusAction
{
    public function __construct(
        private readonly LeadQualification $qualification,
        private readonly ScoreLeadAction $scoreLead,
    ) {}

    /**
     * @throws RuntimeException when the move is not one the current status
     *                          allows, or when the lead does not yet meet the
     *                          qualification requirements
     */
    public function __invoke(Lead $lead, LeadStatus $target): Lead
    {
        $current = $lead->status();

        if ($current === $target) {
            return $lead;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(
                'A '.strtolower($current->label()).' lead cannot move to '.strtolower($target->label()).'.'
            );
        }

        if ($target === LeadStatus::Qualified) {
            $this->guardQualification($lead);
        }

        return $this->write($lead, $target);
    }

    /**
     * Move without checking, for the one caller entitled to: task 2.6's
     * conversion, which sets Converted once the account, contact and deal
     * genuinely exist. Nothing else should call this.
     */
    public function force(Lead $lead, LeadStatus $target): Lead
    {
        return $this->write($lead, $target);
    }

    /**
     * The qualification checklist is evaluated against the stored score, so the
     * lead is rescored first. Otherwise a requirement like "score is at least
     * 50" would be judged on whatever the column happened to hold.
     *
     * @throws RuntimeException
     */
    private function guardQualification(Lead $lead): void
    {
        ($this->scoreLead)($lead);

        $check = $this->qualification->for($lead);

        if (! $check->passes()) {
            throw new RuntimeException((string) $check->reason());
        }
    }

    private function write(Lead $lead, LeadStatus $target): Lead
    {
        $lead->forceFill([
            'status' => $target->value,
            'status_changed_at' => now(),
        ])->save();

        // Status is itself a field a scoring rule may read.
        return ($this->scoreLead)($lead);
    }
}
