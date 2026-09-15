<?php

namespace App\Jobs;

use App\Domain\Meta\Ads\SyncMetaAdStructureAction;
use App\Domain\Meta\Ads\SyncMetaInsightsAction;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Models\MetaAdAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One ad account's structure and figures, off the schedule.
 *
 * **One job per ad account** is the chunk. An agency with nine accounts gets
 * nine jobs that fail and retry independently, rather than one that loses the
 * ninth account's figures because the third account's token expired — and the
 * queue can spread them rather than making one worker hold Meta's rate limit for
 * a quarter of an hour.
 *
 * Carries the id, not the model: a serialised ad account would drag its
 * connection and token through the queue payload, and this job writes to the row
 * it would be carrying a stale copy of.
 *
 * Structure first, then figures. A day's spend against a campaign nobody has a
 * name for is a row nothing can display, and the structure read is the cheap one.
 */
class SyncMetaAds implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * Minutes, not seconds. What makes this fail is Meta — a throttle, an
     * outage, an expired token — and none of those is over in ten seconds.
     *
     * @var array<int, int>
     */
    public array $backoff = [120, 600];

    public function __construct(public int $adAccountId) {}

    public function handle(SyncMetaAdStructureAction $structure, SyncMetaInsightsAction $insights): void
    {
        $account = MetaAdAccount::query()->find($this->adAccountId);

        if ($account === null || ! $account->is_active) {
            return;
        }

        try {
            $shape = $structure($account);
            $figures = $insights($account);
        } catch (MetaApiException $exception) {
            // Their error body never reaches our log: it can carry anything,
            // including a token of ours echoed back. The message this
            // application wrote is what somebody needs.
            Log::warning('A Meta ads sync could not finish.', [
                'ad_account' => $account->ad_account_id,
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('A Meta ads sync finished.', [
            'ad_account' => $account->ad_account_id,
            ...$shape,
            'insight_rows' => $figures['rows'],
            'through' => $figures['to'],
        ]);
    }
}
