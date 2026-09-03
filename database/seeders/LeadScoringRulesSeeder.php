<?php

namespace Database\Seeders;

use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Shared\Enums\FilterOperator;
use Illuminate\Database\Seeder;

/**
 * A starter scoring model, so a fresh installation scores leads sensibly
 * before anybody opens the rules screen.
 *
 * No qualification requirements are seeded on purpose: an empty checklist means
 * every lead may be qualified, and gating that is a decision for whoever runs
 * the CRM rather than a default that silently blocks the pipeline.
 */
class LeadScoringRulesSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: an installation that has been configured keeps its rules.
        if (LeadScoringRule::query()->exists()) {
            return;
        }

        $position = 0;

        foreach ($this->defaults() as $rule) {
            LeadScoringRule::create([
                ...$rule,
                'kind' => LeadRuleKind::Score->value,
                'position' => $position++,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaults(): array
    {
        return [
            [
                'label' => 'Works for a named company',
                'field' => 'company_name',
                'operator' => FilterOperator::IsNotEmpty->value,
                'points' => 10,
            ],
            [
                'label' => 'Reachable by phone',
                'field' => 'phone',
                'operator' => FilterOperator::IsNotEmpty->value,
                'points' => 10,
            ],
            [
                'label' => 'Reachable by email',
                'field' => 'email',
                'operator' => FilterOperator::IsNotEmpty->value,
                'points' => 10,
            ],
            [
                'label' => 'Free-mail address rather than a work one',
                'field' => 'email',
                'operator' => FilterOperator::Contains->value,
                'value' => 'gmail.com',
                'points' => -5,
            ],
            [
                'label' => 'Gave a job title',
                'field' => 'job_title',
                'operator' => FilterOperator::IsNotEmpty->value,
                'points' => 5,
            ],
            [
                'label' => 'Named a budget',
                'field' => 'estimated_value',
                'operator' => FilterOperator::GreaterThan->value,
                'value' => '0',
                'points' => 10,
            ],
            [
                'label' => 'Substantial budget',
                'field' => 'estimated_value',
                'operator' => FilterOperator::GreaterThanOrEqual->value,
                'value' => '10000',
                'points' => 20,
            ],
            [
                'label' => 'Came in through a referral',
                'field' => 'source',
                'operator' => FilterOperator::Equals->value,
                'value' => 'referral',
                'points' => 20,
            ],
            [
                'label' => 'Came in through a partner',
                'field' => 'source',
                'operator' => FilterOperator::Equals->value,
                'value' => 'partner',
                'points' => 15,
            ],
            [
                'label' => 'Somebody has already worked it',
                'field' => 'status',
                'operator' => FilterOperator::In->value,
                'selected' => [
                    LeadStatus::Contacted->value,
                    LeadStatus::Nurturing->value,
                    // Qualified belongs here too: without it, qualifying a lead
                    // would drop its score, since status is a scoring input.
                    LeadStatus::Qualified->value,
                ],
                'points' => 10,
            ],
        ];
    }
}
