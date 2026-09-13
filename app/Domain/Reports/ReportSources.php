<?php

namespace App\Domain\Reports;

use App\Domain\Accounts\AccountFields;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\ActivityFields;
use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\ContactFields;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\DealFields;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Reports\Enums\Aggregate;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\QuoteFields;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketFields;
use App\Models\User;

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
    public const KEYS = ['deals', 'leads', 'accounts', 'contacts', 'activities', 'tickets', 'quotes'];

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
        return self::make($key);
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
                Dimension::joined('owner', 'Owner', 'deal_owner.name', 'owner'),
                Dimension::joined('account', 'Account', 'deal_account.name', 'account'),
                Dimension::coded('close_reason', 'Close reason', 'deals.close_reason', DealCloseReason::options()),
                Dimension::date('created', 'Created', 'deals.created_at'),
                Dimension::date('expected_close', 'Expected close', 'deals.expected_close_date'),
                Dimension::date('closed', 'Closed', 'deals.closed_at'),
            ]),
            measures: self::measures([
                Measure::count('count', 'Deals'),
                Measure::money('value', 'Value', 'deals.value'),
                Measure::average('average_value', 'Average value', 'deals.value', 'money'),
                // Days from created to closed, averaged. Null for open deals,
                // and AVG ignores nulls, so this is the average of the deals
                // that actually closed rather than nought for the rest.
                Measure::average('days_to_close', 'Average days to close', 'TIMESTAMPDIFF(DAY, deals.created_at, deals.closed_at)'),
            ]),
            joins: self::joins([
                new ReportJoin('owner', 'users', 'owner_id', alias: 'deal_owner'),
                new ReportJoin('account', 'accounts', 'account_id', alias: 'deal_account'),
            ]),
            filters: DealFields::filters(),
            description: 'Pipeline value, win rates and how long deals take.',
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
                Dimension::joined('owner', 'Owner', 'lead_owner.name', 'owner'),
                Dimension::plain('country', 'Country', 'leads.country'),
                Dimension::date('created', 'Created', 'leads.created_at'),
                Dimension::date('converted', 'Converted', 'leads.converted_at'),
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
                new ReportJoin('owner', 'users', 'owner_id', alias: 'lead_owner'),
            ]),
            filters: LeadFields::filters(),
            description: 'Where leads come from, and what happens to them.',
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
                Dimension::joined('owner', 'Owner', 'account_owner.name', 'owner'),
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
                Dimension::joined('account', 'Account', 'contact_account.name', 'account'),
                Dimension::plain('country', 'Country', 'contacts.country'),
                Dimension::plain('job_title', 'Job title', 'contacts.job_title'),
                Dimension::joined('owner', 'Owner', 'contact_owner.name', 'owner'),
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
                Dimension::joined('owner', 'Owner', 'activity_owner.name', 'owner'),
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
                Dimension::joined('agent', 'Agent', 'ticket_owner.name', 'owner'),
                Dimension::joined('account', 'Account', 'ticket_account.name', 'account'),
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
                Dimension::joined('owner', 'Owner', 'quote_owner.name', 'owner'),
                Dimension::joined('account', 'Account', 'quote_account.name', 'account'),
                Dimension::date('issued', 'Issued', 'quotes.issue_date'),
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
