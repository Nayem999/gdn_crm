<?php

namespace App\Domain\Reports;

use App\Domain\Deals\Enums\DealStage;
use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Models\Report;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterGroup;

/**
 * The eight reports every installation starts with.
 *
 * Declared as definitions rather than written as queries, so a built-in is the
 * same kind of thing a person builds: it opens in the builder, it can be
 * duplicated and changed, and it goes through the same runner with the same
 * scoping. A "standard report" that was special-cased SQL would be a second
 * engine to keep in step, and would answer with numbers the builder could not
 * reproduce.
 *
 * Each is addressed by a **slug**, which is stable: the dashboard and the
 * navigation link to these, and an id would move between installations.
 */
final class StandardReports
{
    /**
     * The slugs, as a plain list.
     *
     * A constant for the reason ReportSources::KEYS is one — building a
     * definition can read the database, and a Pest dataset is resolved before
     * the test database exists.
     *
     * @var array<int, string>
     */
    public const SLUGS = [
        'sales-by-month',
        'leads-by-source',
        'pipeline-by-stage',
        'lead-conversion',
        'revenue-by-month',
        'salesperson-performance',
        'activity-by-person',
        'top-customers',
        // Phase 12. The three the brief asks for, and each answers a question
        // Ads Manager cannot: it stops at the form submission and knows nothing
        // about what was qualified, opened or won.
        'leads-by-meta-campaign',
        'revenue-by-meta-campaign',
        'meta-conversion-funnel',
    ];

    /**
     * @return array<string, array{name: string, description: string, chart: ChartType, definition: ReportDefinition}>
     */
    public static function all(): array
    {
        $reports = [];

        foreach (self::SLUGS as $slug) {
            $report = self::make($slug);

            if ($report !== null) {
                $reports[$slug] = $report;
            }
        }

        return $reports;
    }

    /**
     * @return array{name: string, description: string, chart: ChartType, definition: ReportDefinition}|null
     */
    public static function find(string $slug): ?array
    {
        return self::make($slug);
    }

    /**
     * @return array{name: string, description: string, chart: ChartType, definition: ReportDefinition}|null
     */
    private static function make(string $slug): ?array
    {
        return match ($slug) {
            'sales-by-month' => [
                'name' => 'Sales by month',
                'description' => 'What was won, month by month.',
                'chart' => ChartType::Line,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'deals',
                    'dimensions' => ['closed'],
                    'measures' => ['value', 'count'],
                    'grain' => 'month',
                    // Won deals only: a "sales" figure that included the lost
                    // ones would be the size of the attempt, not of the sale.
                    // Filtered on the stage rather than on close_reason — a
                    // deal is won when it reaches the won stage, and the reason
                    // is why, which is a different column and often empty.
                    'filters' => self::equals('stage', DealStage::Won->value),
                ]),
            ],
            'leads-by-source' => [
                'name' => 'Leads by source',
                'description' => 'Where the leads come from, and what they are worth.',
                'chart' => ChartType::Pie,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'leads',
                    'dimensions' => ['source'],
                    'measures' => ['count', 'estimated_value'],
                    'sort_by' => 'count',
                ]),
            ],
            'pipeline-by-stage' => [
                'name' => 'Pipeline by stage',
                'description' => 'What is in play, and where it has got to.',
                'chart' => ChartType::Funnel,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'deals',
                    'dimensions' => ['stage'],
                    'measures' => ['count', 'value'],
                    'sort_by' => 'count',
                ]),
            ],
            'lead-conversion' => [
                'name' => 'Lead conversion',
                'description' => 'How many leads at each status became customers.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'leads',
                    'dimensions' => ['status'],
                    // Both, side by side: a conversion rate reported as one
                    // number hides whether it moved because more converted or
                    // because fewer arrived.
                    'measures' => ['count', 'converted'],
                    'sort_by' => 'count',
                ]),
            ],
            'revenue-by-month' => [
                'name' => 'Revenue by month',
                'description' => 'Accepted quotes, by the month they were accepted.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'quotes',
                    'dimensions' => ['accepted'],
                    'measures' => ['total', 'count'],
                    'grain' => 'month',
                    'filters' => self::notEmpty('accepted_at'),
                ]),
            ],
            'salesperson-performance' => [
                'name' => 'Salesperson performance',
                'description' => 'Deals per person: how many, how much, and how long they take.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'deals',
                    'dimensions' => ['owner'],
                    'measures' => ['count', 'value', 'days_to_close'],
                    'sort_by' => 'value',
                ]),
            ],
            'activity-by-person' => [
                'name' => 'Activity by person',
                'description' => 'Calls, meetings and tasks, and who is doing them.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'activities',
                    'dimensions' => ['owner', 'type'],
                    'measures' => ['count', 'completed'],
                    'sort_by' => 'count',
                ]),
            ],
            'top-customers' => [
                'name' => 'Top customers',
                'description' => 'Accounts by the value of the deals against them.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'deals',
                    'dimensions' => ['account'],
                    'measures' => ['value', 'count'],
                    'sort_by' => 'value',
                    'limit' => 20,
                ]),
            ],
            'leads-by-meta-campaign' => [
                'name' => 'Leads by Meta campaign',
                'description' => 'Which advertising produced enquiries, and what they were estimated at.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'leads',
                    'dimensions' => ['meta_campaign'],
                    'measures' => ['count', 'estimated_value'],
                    'sort_by' => 'count',
                    'sort_direction' => 'desc',
                ]),
            ],
            'revenue-by-meta-campaign' => [
                'name' => 'Revenue by Meta campaign',
                'description' => 'What each campaign eventually earned, from the deals its leads became.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'deals',
                    'dimensions' => ['meta_campaign'],
                    'measures' => ['value', 'count'],
                    // Won only. Counting open deals here would report a
                    // campaign's hopes as its earnings.
                    'filters' => self::equals('stage', DealStage::Won->value),
                    'sort_by' => 'value',
                    'sort_direction' => 'desc',
                ]),
            ],
            'meta-conversion-funnel' => [
                'name' => 'Meta leads by status',
                'description' => 'What became of the leads the advertising brought in.',
                'chart' => ChartType::Bar,
                'definition' => ReportDefinition::fromArray([
                    'source' => 'leads',
                    // Status against campaign: the funnel a marketer reads left
                    // to right, and the one that shows a campaign buying volume
                    // that never qualifies.
                    'dimensions' => ['meta_campaign', 'status'],
                    'measures' => ['count'],
                    'sort_by' => 'count',
                    'sort_direction' => 'desc',
                ]),
            ],
            default => null,
        };
    }

    /**
     * Creates the built-ins that are missing, and leaves the rest alone.
     *
     * Idempotent, and deliberately **not** an update: somebody may have edited
     * a standard report to suit the company, and a seeder that overwrote that
     * on every deploy would be a seeder nobody dares run.
     *
     * @return int how many were created
     */
    public static function install(): int
    {
        $created = 0;

        foreach (self::all() as $slug => $spec) {
            $exists = Report::query()->withTrashed()->where('slug', $slug)->exists();

            if ($exists) {
                continue;
            }

            $report = new Report;

            $report->forceFill([
                'name' => $spec['name'],
                'description' => $spec['description'],
                'source' => $spec['definition']->source,
                'definition' => $spec['definition']->toArray(),
                'chart_type' => $spec['chart']->value,
                // No owner: a built-in belongs to the installation, not to
                // whoever happened to run the seeder.
                'owner_id' => null,
                'is_shared' => true,
                'is_standard' => true,
                'slug' => $slug,
            ])->save();

            $created++;
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private static function equals(string $field, string $value): array
    {
        return [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [[
                'field' => $field,
                'operator' => FilterOperator::Equals->value,
                'value' => $value,
                'second_value' => null,
                'selected' => [],
            ]],
            'groups' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function notEmpty(string $field): array
    {
        return [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [[
                'field' => $field,
                'operator' => FilterOperator::IsNotEmpty->value,
                'value' => null,
                'second_value' => null,
                'selected' => [],
            ]],
            'groups' => [],
        ];
    }
}
