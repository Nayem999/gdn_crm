<?php

namespace App\Domain\Reports;

use App\Domain\Accounts\AccountFields;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\ActivityFields;
use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Campaigns\CampaignFields;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\CampaignType;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Contacts\ContactFields;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\DealFields;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Reports\Enums\Aggregate;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\QuoteFields;
use App\Domain\Shared\RequestMemo;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketFields;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Everything a report can be about.
 *
 * The registry is the reporting engine's security boundary. A report definition
 * carries source, dimension and measure **keys**; if a key is not declared here
 * it is dropped before anything reaches SQL. Nothing else in the engine
 * accepts a column name, a table or a function from outside.
 *
 * Each source carries the permission that gates it and a base query built
 * through the module's own `visibleTo()`, so a report can never aggregate
 * records its reader could not open one by one.
 */
final class ReportSources
{
    /**
     * The sources, as a plain list.
     *
     * A constant rather than array_keys(all()), because building a source
     * reads the database — DealFields::stageOptions() walks the configured
     * pipelines — and the key list is wanted in places where there is no
     * connection yet. A Pest dataset closure is resolved before the test
     * database exists, which is exactly how this is found out; see
     * .ai/rules/tests.md.
     *
     * @var array<int, string>
     */
    public const KEYS = ['deals', 'leads', 'accounts', 'contacts', 'activities', 'tickets', 'quotes', 'campaigns'];

    /**
     * @return array<string, ReportSource>
     */
    public static function all(): array
    {
        $keyed = [];

        foreach (self::KEYS as $key) {
            $source = self::make($key);

            if ($source !== null) {
                $keyed[$key] = $source;
            }
        }

        return $keyed;
    }

    /**
     * One source, built on demand.
     *
     * Only the one asked for: a report on leads has no business reading the
     * pipeline configuration to build the deals source it will not use.
     */
    public static function find(string $key): ?ReportSource
    {
        /** @var ReportSource|null $source */
        $source = app(RequestMemo::class)->remember(
            'reports.source.'.$key,
            fn (): ?ReportSource => self::make($key),
        );

        // Memoised for the life of the request. Building the deals source reads
        // the configured pipelines to label its stage dimension, so a screen
        // that asked for a source per row — the reports list does, to print
        // what each one is about — ran those queries per row.
        return $source;
    }

    public static function has(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return self::KEYS;
    }

    private static function make(string $key): ?ReportSource
    {
        return match ($key) {
            'deals' => self::deals(),
            'leads' => self::leads(),
            'accounts' => self::accounts(),
            'contacts' => self::contacts(),
            'activities' => self::activities(),
            'tickets' => self::tickets(),
            'quotes' => self::quotes(),
            'campaigns' => self::campaigns(),
            default => null,
        };
    }

    /**
     * The sources this person may report on, keyed for a dropdown.
     *
     * @return array<string, string>
     */
    public static function optionsFor(User $viewer): array
    {
        $options = [];

        foreach (self::all() as $key => $source) {
            if ($source->visibleTo($viewer)) {
                $options[$key] = $source->label;
            }
        }

        return $options;
    }

    // -- The sources ---------------------------------------------------------

    private static function deals(): ReportSource
    {
        return new ReportSource(
            key: 'deals',
            label: 'Deals',
            table: 'deals',
            permission: 'deals.view',
            query: fn (User $viewer) => Deal::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::coded('stage', 'Stage', 'deals.stage', DealFields::stageOptions()),
                Dimension::coded('pipeline', 'Pipeline', 'deals.pipeline_id', DealFields::pipelineOptions()),
                Dimension::record('owner', 'Owner', 'deal_owner.name', 'owner', 'deals.owner_id', User::class),
                Dimension::record('account', 'Account', 'deal_account.name', 'account', 'deals.account_id', Account::class, 'accounts.show'),
                Dimension::record('campaign', 'Campaign', 'deal_campaign.name', 'campaign', 'deals.campaign_id', Campaign::class, 'campaigns.show'),
                Dimension::coded('close_reason', 'Close reason', 'deals.close_reason', DealCloseReason::options()),
                Dimension::date('created', 'Created', 'deals.created_at'),
                Dimension::date('expected_close', 'Expected close', 'deals.expected_close_date', dateOnly: true),
                Dimension::date('closed', 'Closed', 'deals.closed_at'),
                // Which advertising this revenue can be traced to. Conversion
                // copies a lead's attribution onto its deal, which is the only
                // reason the question is answerable at all once a sale starts.
                Dimension::joined('meta_campaign', 'Meta campaign', 'deal_attribution.meta_campaign_name', 'attribution'),
                Dimension::joined('meta_ad', 'Meta advertisement', 'deal_attribution.meta_ad_name', 'attribution'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Deals'),
                Measure::money('value', 'Value', 'deals.value'),
                Measure::average('average_value', 'Average value', 'deals.value', 'money'),
                // Days from created to closed, averaged. Null for open deals,
                // and AVG ignores nulls, so this is the average of the deals
                // that actually closed rather than nought for the rest.
                Measure::average('days_to_close', 'Average days to close', 'TIMESTAMPDIFF(DAY, deals.created_at, deals.closed_at)'),
                // Won and lost read the stages each pipeline marks as such, not
                // a stage called "won": a company can rename and add stages.
                new Measure('won_count', 'Won deals', Aggregate::Count, 'CASE WHEN '.self::dealStageIn(StageOutcome::Won).' THEN 1 END', 'number'),
                Measure::money('won_value', 'Won value', 'CASE WHEN '.self::dealStageIn(StageOutcome::Won).' THEN deals.value END'),
                new Measure('lost_count', 'Lost deals', Aggregate::Count, 'CASE WHEN '.self::dealStageIn(StageOutcome::Lost).' THEN 1 END', 'number'),
                // An average of 100 for each won deal and 0 for each lost one
                // is the win rate of the closed deals; open ones are null and
                // AVG leaves them out, so it is never diluted by work in hand.
                Measure::average('win_rate', 'Win rate', 'CASE WHEN '.self::dealStageIn(StageOutcome::Won).' THEN 100 WHEN '.self::dealStageIn(StageOutcome::Lost).' THEN 0 END', 'percent'),
                Measure::money('open_value', 'Open pipeline', 'CASE WHEN NOT '.self::dealStageIn(null).' THEN deals.value END'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'deal_owner'),
                new ReportJoin('account', 'accounts', 'account_id', alias: 'deal_account'),
                new ReportJoin('campaign', 'campaigns', 'campaign_id', alias: 'deal_campaign'),
                new ReportJoin(
                    'attribution',
                    'marketing_attributions',
                    'id',
                    'attributable_id',
                    'deal_attribution',
                    ['attributable_type' => Deal::class],
                ),
            ]),
            filters: DealFields::filters(),
            description: 'Pipeline value, win rates and how long deals take.',
            recordLabel: 'deals.name',
            recordRoute: 'deals.show',
        );
    }

    private static function leads(): ReportSource
    {
        return new ReportSource(
            key: 'leads',
            label: 'Leads',
            table: 'leads',
            permission: 'leads.view',
            query: fn (User $viewer) => Lead::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::coded('status', 'Status', 'leads.status', LeadStatus::options()),
                Dimension::coded('source', 'Source', 'leads.source', LeadSource::options()),
                // Kept as the `owner` key so saved reports still resolve, but
                // labelled for what it is now that leads have a separate owner.
                Dimension::record('owner', 'Primary assignee', 'lead_owner.name', 'owner', 'lead_owner.user_id', User::class),
                Dimension::record('lead_owner', 'Lead owner', 'lead_owner_user.name', 'lead_owner', 'leads.lead_owner_id', User::class),
                Dimension::record('campaign', 'Campaign', 'lead_campaign.name', 'campaign', 'leads.campaign_id', Campaign::class, 'campaigns.show'),
                Dimension::plain('country', 'Country', 'leads.country'),
                Dimension::date('created', 'Created', 'leads.created_at'),
                Dimension::date('converted', 'Converted', 'leads.converted_at'),
                // What paid for the lead. The **stored** name rather than a
                // join to today's campaign row: campaigns are renamed, and a
                // report that read the current name onto last year's leads
                // would quietly rewrite history.
                Dimension::joined('meta_campaign', 'Meta campaign', 'lead_attribution.meta_campaign_name', 'attribution'),
                Dimension::joined('meta_ad_set', 'Meta ad set', 'lead_attribution.meta_ad_set_name', 'attribution'),
                Dimension::joined('meta_ad', 'Meta advertisement', 'lead_attribution.meta_ad_name', 'attribution'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Leads'),
                Measure::money('estimated_value', 'Estimated value', 'leads.estimated_value'),
                Measure::average('average_score', 'Average score', 'leads.score'),
                // A count of the converted ones. COUNT over a nullable column
                // skips the nulls, which is exactly the question.
                new Measure('converted', 'Converted', Aggregate::Count, 'leads.converted_at', 'number'),
            ]),
            joins: self::joins([
                // Not a plain "leads.owner_id = users.id" any more — leads
                // have no such column, and several people can be assigned at
                // once. This derived table picks the same one primaryAssignee()
                // would: the lowest priority (nulls last), ties broken by
                // whoever was assigned first — so "Owner" in a report reads the
                // same person the app shows everywhere else it only has room
                // for one name.
                //
                // No explicit tenant filter inside the subquery, and none is
                // needed: it only ever gets asked about lead ids the OUTER
                // query already returned, which are already scoped to the
                // viewer's own tenant and access level — a lead_assignees row
                // cannot belong to a different tenant's lead than the id it is
                // matched against.
                new ReportJoin('owner', self::leadOwnerSubquery(), 'id', 'lead_id', alias: 'lead_owner'),
                new ReportJoin('lead_owner', 'users', 'lead_owner_id', alias: 'lead_owner_user'),
                new ReportJoin('campaign', 'campaigns', 'campaign_id', alias: 'lead_campaign'),
                // A morph table, so the type is part of the join: on the id
                // alone it would match a deal or a contact holding the same
                // number and report one module's campaign against another's.
                new ReportJoin(
                    'attribution',
                    'marketing_attributions',
                    'id',
                    'attributable_id',
                    'lead_attribution',
                    ['attributable_type' => Lead::class],
                ),
            ]),
            filters: LeadFields::filters(),
            description: 'Where leads come from, and what happens to them.',
            recordLabel: "CONCAT_WS(' ', leads.first_name, leads.last_name)",
            recordRoute: 'leads.show',
        );
    }

    private static function accounts(): ReportSource
    {
        return new ReportSource(
            key: 'accounts',
            label: 'Accounts',
            table: 'accounts',
            permission: 'accounts.view',
            query: fn (User $viewer) => Account::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::plain('industry', 'Industry', 'accounts.industry'),
                Dimension::plain('size', 'Size', 'accounts.size'),
                Dimension::plain('country', 'Country', 'accounts.country'),
                Dimension::record('owner', 'Owner', 'account_owner.name', 'owner', 'accounts.owner_id', User::class),
                Dimension::date('created', 'Created', 'accounts.created_at'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Accounts'),
                Measure::money('annual_revenue', 'Annual revenue', 'accounts.annual_revenue'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'account_owner'),
            ]),
            filters: AccountFields::filters(),
            description: 'Who the customers are.',
            recordLabel: 'accounts.name',
            recordRoute: 'accounts.show',
        );
    }

    private static function contacts(): ReportSource
    {
        return new ReportSource(
            key: 'contacts',
            label: 'Contacts',
            table: 'contacts',
            permission: 'contacts.view',
            query: fn (User $viewer) => Contact::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::record('account', 'Account', 'contact_account.name', 'account', 'contacts.account_id', Account::class, 'accounts.show'),
                Dimension::plain('country', 'Country', 'contacts.country'),
                Dimension::plain('job_title', 'Job title', 'contacts.job_title'),
                Dimension::record('owner', 'Owner', 'contact_owner.name', 'owner', 'contacts.owner_id', User::class),
                Dimension::date('created', 'Created', 'contacts.created_at'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Contacts'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'contact_owner'),
                new ReportJoin('account', 'accounts', 'account_id', alias: 'contact_account'),
            ]),
            filters: ContactFields::filters(),
            description: 'The people behind the accounts.',
            recordLabel: "CONCAT_WS(' ', contacts.first_name, contacts.last_name)",
            recordRoute: 'contacts.show',
        );
    }

    private static function activities(): ReportSource
    {
        return new ReportSource(
            key: 'activities',
            label: 'Activities',
            table: 'activities',
            permission: 'activities.view',
            query: fn (User $viewer) => Activity::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::coded('type', 'Type', 'activities.type', ActivityType::options()),
                Dimension::coded('status', 'Status', 'activities.status', ActivityStatus::options()),
                Dimension::coded('priority', 'Priority', 'activities.priority', ActivityPriority::options()),
                Dimension::record('owner', 'Owner', 'activity_owner.name', 'owner', 'activities.owner_id', User::class),
                Dimension::date('due', 'Due', 'activities.due_at'),
                Dimension::date('completed', 'Completed', 'activities.completed_at'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Activities'),
                Measure::sum('minutes', 'Total minutes', 'activities.duration_minutes'),
                new Measure('completed', 'Completed', Aggregate::Count, 'activities.completed_at', 'number'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'activity_owner'),
            ]),
            filters: ActivityFields::filters(),
            description: 'Calls, meetings and tasks, and who is doing them.',
            recordLabel: 'activities.subject',
            recordRoute: 'activities.edit',
        );
    }

    private static function tickets(): ReportSource
    {
        return new ReportSource(
            key: 'tickets',
            label: 'Support tickets',
            table: 'tickets',
            permission: 'tickets.view',
            query: fn (User $viewer) => Ticket::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::coded('status', 'Status', 'tickets.status', TicketStatus::options()),
                Dimension::coded('priority', 'Priority', 'tickets.priority', TicketPriority::options()),
                Dimension::coded('source', 'Came in by', 'tickets.source', TicketSource::options()),
                Dimension::record('agent', 'Agent', 'ticket_owner.name', 'owner', 'tickets.owner_id', User::class),
                Dimension::record('account', 'Account', 'ticket_account.name', 'account', 'tickets.account_id', Account::class, 'accounts.show'),
                Dimension::date('created', 'Raised', 'tickets.created_at'),
                Dimension::date('resolved', 'Resolved', 'tickets.resolved_at'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Tickets'),
                // Net of holds, the same arithmetic SupportMetrics uses — a
                // report that disagreed with the support screen would have
                // somebody arguing about which was real. See .ai/rules/support.md
                // on why the CAST is not optional.
                Measure::average(
                    'resolution_hours',
                    'Average hours to resolve',
                    'GREATEST(TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.resolved_at) - CAST(tickets.sla_paused_seconds AS SIGNED), 0) / 3600'
                ),
                Measure::average(
                    'first_response_minutes',
                    'Average minutes to first reply',
                    'TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.first_responded_at) / 60'
                ),
                new Measure('breached', 'Missed a promise', Aggregate::Count, 'tickets.resolution_breached_at', 'number'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'ticket_owner'),
                new ReportJoin('account', 'accounts', 'account_id', alias: 'ticket_account'),
            ]),
            filters: TicketFields::filters(),
            description: 'Support volume, resolution times and who is carrying it.',
            recordLabel: 'tickets.subject',
            recordRoute: 'tickets.show',
        );
    }

    /**
     * What marketing cost, in the same report engine as what it produced.
     *
     * Cost per lead is not a measure here: it divides one source's rows by
     * another's, which is a join the builder deliberately does not offer. The
     * campaign's own page computes it, and 12.13's dashboard does it across
     * campaigns — both from the CRM's records rather than a cached column.
     */
    private static function campaigns(): ReportSource
    {
        return new ReportSource(
            key: 'campaigns',
            label: 'Campaigns',
            table: 'campaigns',
            permission: 'campaigns.view',
            query: fn (User $viewer) => Campaign::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::coded('status', 'Status', 'campaigns.status', CampaignStatus::options()),
                Dimension::coded('type', 'Type', 'campaigns.type', CampaignType::options()),
                Dimension::record('owner', 'Owner', 'campaign_owner.name', 'owner', 'campaigns.owner_id', User::class),
                Dimension::date('started', 'Starts', 'campaigns.start_date', dateOnly: true),
                Dimension::date('ended', 'Ends', 'campaigns.end_date', dateOnly: true),
            ]),
            measures: self::measures([
                Measure::count('count', 'Campaigns'),
                Measure::money('budget', 'Budget', 'campaigns.budget'),
                Measure::money('spent', 'Spent', 'campaigns.actual_cost'),
                Measure::money('expected_revenue', 'Expected revenue', 'campaigns.expected_revenue'),
                Measure::average('average_spend', 'Average spend', 'campaigns.actual_cost', 'money'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'campaign_owner'),
            ]),
            filters: CampaignFields::filters(),
            description: 'What marketing costs, and what it was budgeted at.',
            recordLabel: 'campaigns.name',
            recordRoute: 'campaigns.show',
        );
    }

    private static function quotes(): ReportSource
    {
        return new ReportSource(
            key: 'quotes',
            label: 'Quotes',
            table: 'quotes',
            permission: 'quotes.view',
            query: fn (User $viewer) => Quote::query()->visibleTo($viewer),
            dimensions: self::dimensions([
                Dimension::coded('status', 'Status', 'quotes.status', QuoteStatus::options()),
                Dimension::record('owner', 'Owner', 'quote_owner.name', 'owner', 'quotes.owner_id', User::class),
                Dimension::record('account', 'Account', 'quote_account.name', 'account', 'quotes.account_id', Account::class, 'accounts.show'),
                Dimension::date('issued', 'Issued', 'quotes.issue_date', dateOnly: true),
                Dimension::date('accepted', 'Accepted', 'quotes.accepted_at'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Quotes'),
                Measure::money('total', 'Total', 'quotes.total'),
                Measure::average('average_total', 'Average total', 'quotes.total', 'money'),
                new Measure('accepted', 'Accepted', Aggregate::Count, 'quotes.accepted_at', 'number'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'quote_owner'),
                new ReportJoin('account', 'accounts', 'account_id', alias: 'quote_account'),
            ]),
            filters: QuoteFields::filters(),
            description: 'What was quoted, and what came back.',
            recordLabel: 'quotes.number',
            recordRoute: 'quotes.edit',
        );
    }

    /**
     * @param  array<int, Dimension>  $items
     * @return array<string, Dimension>
     */
    private static function dimensions(array $items): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $keyed[$item->key] = $item;
        }

        return $keyed;
    }

    /**
     * @param  array<int, Measure>  $items
     * @return array<string, Measure>
     */
    private static function measures(array $items): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $keyed[$item->key] = $item;
        }

        return $keyed;
    }

    /**
     * A lead's primary assignee, as a one-row-per-lead derived table a plain
     * ReportJoin can point at — the reporting engine only ever joins one
     * column to one column, and "the owner" is no longer that on the leads
     * table itself.
     */
    /**
     * "This deal's stage is one of the stages for this outcome", as SQL.
     *
     * The keys come from pipeline configuration an administrator types, so they
     * are quoted by the driver rather than trusted. Null means any closing
     * outcome, won or lost.
     */
    private static function dealStageIn(?StageOutcome $outcome): string
    {
        $pdo = DB::connection()->getPdo();
        $quoted = array_map(fn (string $key): string => (string) $pdo->quote($key), Deal::closingStageKeys($outcome));

        return 'deals.stage IN ('.implode(', ', $quoted).')';
    }

    private static function leadOwnerSubquery(): string
    {
        return <<<'SQL'
        (SELECT ranked.lead_id, ranked.user_id, users.name FROM (
            SELECT lead_id, user_id, ROW_NUMBER() OVER (
                PARTITION BY lead_id ORDER BY priority IS NULL, priority, assigned_at
            ) AS rn
            FROM lead_assignees
        ) ranked
        INNER JOIN users ON users.id = ranked.user_id
        WHERE ranked.rn = 1)
        SQL;
    }

    /**
     * @param  array<int, ReportJoin>  $joins
     * @return array<string, ReportJoin>
     */
    private static function joins(array $joins): array
    {
        $keyed = [];

        foreach ($joins as $join) {
            $keyed[$join->key] = $join;
        }

        return $keyed;
    }
}
