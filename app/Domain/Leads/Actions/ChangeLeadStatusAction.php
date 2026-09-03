<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
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
    /**
     * @throws RuntimeException when the move is not one the current status allows
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

        $lead->forceFill([
            'status' => $target->value,
            'status_changed_at' => now(),
        ])->save();

        return $lead->refresh();
    }

    /**
     * Move without checking, for the one caller entitled to: task 2.6's
     * conversion, which sets Converted once the account, contact and deal
     * genuinely exist. Nothing else should call this.
     */
    public function force(Lead $lead, LeadStatus $target): Lead
    {
        $lead->forceFill([
            'status' => $target->value,
            'status_changed_at' => now(),
        ])->save();

        return $lead->refresh();
    }
}
