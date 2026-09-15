<?php

namespace App\Console\Commands;

use App\Domain\Meta\Leads\Actions\BackfillMetaLeadsAction;
use App\Domain\Meta\Models\MetaPage;
use App\Jobs\BackfillMetaLeads as BackfillMetaLeadsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Asks Meta for the leads a page has that this application does not.
 *
 * Run by hand after an outage, a webhook that was pointed at the wrong URL, or
 * a subscription that lapsed. Deliberately **not** on the schedule: a backfill
 * that ran every night would make the webhook's own reliability impossible to
 * judge — everything would arrive eventually, and nobody would notice that
 * nothing was arriving on time.
 */
class BackfillMetaLeads extends Command
{
    protected $signature = 'meta:backfill-leads
        {--page= : One page id, rather than every connected page}
        {--days= : How far back to ask, instead of each form\'s own last lead}
        {--sync : Run it here rather than queueing it}';

    protected $description = 'Fetch lead-ad submissions Meta has and this application missed';

    public function handle(BackfillMetaLeadsAction $backfill): int
    {
        $pageId = $this->option('page');

        $pages = MetaPage::query()
            ->when(is_string($pageId) && $pageId !== '', fn ($query) => $query->where('page_id', $pageId))
            ->orderBy('id')
            ->get()
            // A page listed during the wizard and never finished connecting has
            // nothing to read with, and saying so beats a run that reports zero.
            ->filter(fn (MetaPage $page) => $page->isUsable());

        if ($pages->isEmpty()) {
            $this->warn('No connected page to backfill. Connect one under Settings → Meta.');

            return self::SUCCESS;
        }

        $days = $this->option('days');
        $since = is_numeric($days) ? Carbon::now()->subDays((int) $days) : null;

        foreach ($pages as $page) {
            if (! $this->option('sync')) {
                BackfillMetaLeadsJob::dispatch($page->id, $since?->toIso8601String());

                $this->line($page->name.': queued');

                continue;
            }

            $counts = $backfill($page, $since);

            $this->line(sprintf(
                '%s: %d found over %d %s, %d queued%s',
                $page->name,
                $counts['found'],
                $counts['forms'],
                str('form')->plural($counts['forms']),
                $counts['queued'],
                $counts['throttled'] ? ' (stopped short of Meta\'s rate limit)' : '',
            ));
        }

        return self::SUCCESS;
    }
}
