<?php

namespace App\Jobs;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Leads\Actions\BackfillMetaLeadsAction;
use App\Domain\Meta\Models\MetaPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Catches one page up on the leads it missed.
 *
 * Carries the **id**, not the model: a serialised page would carry its access
 * token into the queue payload, where it would sit in a database table, in a
 * failed-jobs row and in whatever the queue driver logs. The token is read at
 * run time instead, from a column that is encrypted at rest.
 *
 * Retried, unlike the delivery pipeline's job, because what makes a backfill
 * fail is almost always Meta — an outage, a throttle — rather than anything
 * about the data. Nothing it does is repeated on a second attempt: every lead
 * it finds is checked against `meta_leads` before a delivery is written.
 */
class BackfillMetaLeads implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * @param  string|null  $since  ISO-8601; null means each form's own last lead.
     */
    public function __construct(public int $pageId, public ?string $since = null) {}

    public function handle(BackfillMetaLeadsAction $backfill): void
    {
        $page = MetaPage::query()->find($this->pageId);

        if ($page === null) {
            return;
        }

        try {
            $counts = $backfill($page, $this->since === null ? null : Carbon::parse($this->since));
        } catch (MetaApiException $exception) {
            // Their error body never reaches our summary — it can contain
            // anything, including a token of ours echoed back. The class and
            // the message this application wrote are enough to act on.
            Log::warning('A Meta lead backfill could not finish.', [
                'page' => $page->page_id,
                'reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('A Meta lead backfill finished.', ['page' => $page->page_id, ...$counts]);
    }
}
