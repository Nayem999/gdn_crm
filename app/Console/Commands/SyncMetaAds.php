<?php

namespace App\Console\Commands;

use App\Domain\Meta\Ads\SyncMetaAdStructureAction;
use App\Domain\Meta\Ads\SyncMetaInsightsAction;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Jobs\SyncMetaAds as SyncMetaAdsJob;
use Illuminate\Console\Command;

/**
 * Reads every connected ad account's campaigns, ad sets, ads and daily figures.
 *
 * Queued rather than run here, one job per ad account: the schedule's job is to
 * decide that it is time, not to spend fifteen minutes talking to Meta while the
 * next tick waits behind it.
 *
 * An account with no connection or one somebody switched off is skipped
 * silently. A half-configured integration is somebody mid-setup, not an error
 * worth waking anybody for.
 */
class SyncMetaAds extends Command
{
    protected $signature = 'meta:sync-ads
        {--account= : One ad account id, rather than every active one}
        {--sync : Run it here rather than queueing it}';

    protected $description = 'Read Meta campaigns, ad sets, ads and their daily figures';

    public function handle(SyncMetaAdStructureAction $structure, SyncMetaInsightsAction $insights): int
    {
        $only = $this->option('account');

        $accounts = MetaAdAccount::query()
            ->where('is_active', true)
            ->when(is_string($only) && $only !== '', fn ($query) => $query->where('ad_account_id', $only))
            ->orderBy('id')
            ->get()
            // Asked per row rather than in SQL: "is there a usable connection
            // behind this" is a question about the account's token, and a join
            // that tried to express it would be a second statement of the rule.
            ->filter(fn (MetaAdAccount $account) => $account->account?->isUsable() === true);

        if ($accounts->isEmpty()) {
            $this->warn('No connected ad account to read. Connect one under Settings → Meta.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            if (! $this->option('sync')) {
                SyncMetaAdsJob::dispatch($account->id);

                $this->line($account->name.': queued');

                continue;
            }

            $shape = $structure($account);
            $figures = $insights($account);

            $this->line(sprintf(
                '%s: %d campaigns, %d ad sets, %d ads, %d days of figures to %s%s',
                $account->name,
                $shape['campaigns'],
                $shape['ad_sets'],
                $shape['ads'],
                $figures['rows'],
                $figures['to'],
                $shape['throttled'] || $figures['throttled'] ? ' (stopped short of Meta\'s rate limit)' : '',
            ));
        }

        return self::SUCCESS;
    }
}
