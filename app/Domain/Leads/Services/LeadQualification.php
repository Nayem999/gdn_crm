<?php

namespace App\Domain\Leads\Services;

use App\Domain\Leads\DTOs\QualificationCheck;
use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use Illuminate\Support\Collection;

/**
 * The checklist a lead must satisfy before it can be marked Qualified.
 *
 * Requirements are ANDed: every active one must be met. A requirement on the
 * `score` field is how "must reach 50 points" is expressed — no special case is
 * needed, because the score is an ordinary indexed column.
 */
class LeadQualification
{
    public function __construct(private readonly LeadRuleMatcher $matcher) {}

    /**
     * @return Collection<int, LeadScoringRule>
     */
    public function requirements(): Collection
    {
        return LeadScoringRule::query()
            ->ofKind(LeadRuleKind::Qualification)
            ->active()
            ->ordered()
            ->get();
    }

    public function for(Lead $lead): QualificationCheck
    {
        $checked = [];

        foreach ($this->requirements() as $requirement) {
            $checked[] = [
                'label' => $requirement->label,
                'met' => $this->matcher->matches($requirement, $lead),
            ];
        }

        return new QualificationCheck($checked);
    }
}
