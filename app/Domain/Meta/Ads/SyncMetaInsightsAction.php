<?php

namespace App\Domain\Meta\Ads;

use App\Domain\Meta\Enums\MetaAdLevel;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaInsight;
use Illuminate\Support\Carbon;

/**
 * What each campaign, ad set and ad actually spent and produced, by day.
 *
 * Three decisions hold this together.
 *
 * **A day is re-read after it has already been read.** Meta's attribution
 * windows move a day's figures for up to seventy-two hours: a conversion on
 * Friday can be credited to Tuesday's advertisement, and Tuesday's spend is not
 * final until the window closes. So every run re-asks for a trailing few days
 * and upserts them. A sync that only ever asked for yesterday would leave every
 * figure permanently under-reported, and nothing would look wrong.
 *
 * **The cursor moves once, at the end.** `insights_synced_through` is written
 * after the whole run has succeeded — the rule Phase 8's pull sync learned by
 * getting it wrong. Advancing per page would leave the mark past days a failed
 * run never fetched, and those days would never be asked for again: the gap
 * would be permanent and silent.
 *
 * **Rates are Meta's, not ours.** `ctr`, `cpc` and `cpm` are stored as returned
 * rather than recomputed from spend and clicks, because Meta deduplicates clicks
 * and attributes impressions over a window. A figure we divided ourselves would
 * disagree with Ads Manager, and the person holding both screens believes Ads
 * Manager.
 */
class SyncMetaInsightsAction
{
    /**
     * How many days back a run re-reads, on top of whatever is new.
     *
     * Three, because that is the window Meta's own documentation gives for
     * figures settling.
     */
    public const RESTATEMENT_DAYS = 3;

    /**
     * How far back a first run asks, when there is no mark to work from.
     */
    public const DEFAULT_LOOKBACK_DAYS = 30;

    /**
     * The action types that mean "somebody became a lead", **in preference
     * order**, of which only the first one present is counted.
     *
     * This is the trap in Meta's `actions` array and it is not obvious: one
     * submission is frequently reported under several action types at once —
     * `lead` and `onsite_conversion.lead_grouped` are the same seven people —
     * so adding them up reports fourteen leads for seven enquiries, and the cost
     * per lead on every screen is then half what it really is. A test sends both
     * and expects seven.
     */
    private const LEAD_ACTIONS = [
        'lead',
        'leadgen_grouped',
        'onsite_conversion.lead_grouped',
    ];

    /**
     * Outcomes further down the funnel than a lead — a purchase, a completed
     * registration — which is what the Conversions API in 12.12 sends back.
     * Same rule: the pixel and the aggregate report the same sale, so the first
     * present wins rather than the sum.
     */
    private const CONVERSION_ACTIONS = [
        'purchase',
        'offsite_conversion.fb_pixel_purchase',
        'complete_registration',
        'offsite_conversion.fb_pixel_complete_registration',
    ];

    private const FIELDS = 'campaign_id,adset_id,ad_id,spend,impressions,reach,clicks,ctr,cpc,cpm,actions,date_start';

    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @return array{rows: int, from: string, to: string, throttled: bool}
     *
     * @throws MetaApiException
     */
    public function __invoke(MetaAdAccount $account, ?Carbon $since = null, ?Carbon $until = null): array
    {
        // The ad account's own token where it was given one. A business handed
        // a separate permanent token for the Marketing API has exactly that,
        // and reaching past it to the connection's token means these calls run
        // on a credential nobody chose for them.
        $token = $account->usableToken();

        if ($token === null) {
            throw new MetaApiException('That Meta connection has no access token. Reconnect it.');
        }

        $from = $since ?? $this->from($account);
        $to = $until ?? Carbon::today();

        // A window that ends before it starts is a mark somebody moved forward
        // by hand, or a clock that disagrees. Reading nothing beats reading
        // Meta's answer to a nonsensical range.
        if ($from->gt($to)) {
            $from = $to->copy();
        }

        $rows = 0;
        $throttled = false;
        $readAt = now();

        foreach (MetaAdLevel::cases() as $level) {
            if ($this->client->isNearRateLimit()) {
                $throttled = true;

                break;
            }

            $rows += $this->level($account, $level, $from, $to, $token, $readAt);
        }

        // Only now, and only when the whole window was actually read. A run that
        // stopped short of the rate limit has not covered its window, and
        // marking it as covered is how a gap becomes permanent.
        if (! $throttled) {
            $account->forceFill(['insights_synced_through' => $to->toDateString()])->save();
        }

        return [
            'rows' => $rows,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'throttled' => $throttled,
        ];
    }

    /**
     * Where this run starts.
     *
     * The mark, walked back by the restatement window so recently-read days are
     * asked about again; or a month for an account that has never been read,
     * which is enough history for the first dashboard to say something.
     */
    private function from(MetaAdAccount $account): Carbon
    {
        $through = $account->insights_synced_through;

        return $through === null
            ? Carbon::today()->subDays(self::DEFAULT_LOOKBACK_DAYS)
            : Carbon::parse($through)->subDays(self::RESTATEMENT_DAYS);
    }

    /**
     * @throws MetaApiException
     */
    private function level(
        MetaAdAccount $account,
        MetaAdLevel $level,
        Carbon $from,
        Carbon $to,
        string $token,
        Carbon $readAt,
    ): int {
        $written = 0;

        foreach ($this->client->paginate($account->graphId().'/insights', [
            'level' => $level->value,
            'fields' => self::FIELDS,
            // A day at a time. Without this Meta returns one aggregated row for
            // the whole range, which cannot afterwards be cut into the periods
            // every question about advertising actually has in it.
            'time_increment' => 1,
            'time_range' => (string) json_encode([
                'since' => $from->toDateString(),
                'until' => $to->toDateString(),
            ]),
            'limit' => 500,
        ], $token) as $row) {
            $entityId = $this->text($row, $level->idField());
            $date = $this->text($row, 'date_start');

            if ($entityId === null || $date === null) {
                continue;
            }

            MetaInsight::query()->updateOrCreate(
                // The unique key. A re-read of a day whose figures have moved
                // updates it in place; without this every restatement pass would
                // add a second Tuesday.
                ['level' => $level->value, 'entity_id' => $entityId, 'date' => $date],
                [
                    'spend' => $this->decimal($row, 'spend') ?? '0',
                    'impressions' => $this->count($row, 'impressions'),
                    'reach' => $this->count($row, 'reach'),
                    'clicks' => $this->count($row, 'clicks'),
                    'ctr' => $this->decimal($row, 'ctr'),
                    'cpc' => $this->decimal($row, 'cpc'),
                    'cpm' => $this->decimal($row, 'cpm'),
                    'leads' => $this->actions($row, self::LEAD_ACTIONS),
                    'conversions' => $this->actions($row, self::CONVERSION_ACTIONS),
                    // From the ad account, per row. An agency running one client
                    // in USD and another in BDT must never have the two added
                    // together, and a row without its own currency invites it.
                    'currency' => $account->currency,
                    'read_at' => $readAt,
                ]
            );

            $written++;
        }

        return $written;
    }

    /**
     * How many Meta counted, taking the **first** of the listed action types it
     * reported rather than the sum of all of them.
     *
     * The types in each list are the same event counted different ways, so a sum
     * would multiply one enquiry into several. First-present rather than largest
     * because the order states which reading is preferred, and a silent "pick
     * the biggest" would quietly change meaning the day Meta adds another way to
     * count the same thing.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $wanted
     */
    private function actions(array $row, array $wanted): int
    {
        $counted = [];

        foreach (is_array($row['actions'] ?? null) ? $row['actions'] : [] as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = $action['action_type'] ?? null;
            $value = $action['value'] ?? null;

            if (is_string($type) && in_array($type, $wanted, true) && is_numeric($value)) {
                // Meta can report one type more than once in a row — a breakdown
                // it did not collapse — and those genuinely add up.
                $counted[$type] = ($counted[$type] ?? 0) + (int) $value;
            }
        }

        foreach ($wanted as $type) {
            if (isset($counted[$type])) {
                return $counted[$type];
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function count(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function decimal(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        // Meta sends these as strings, and they stay strings: passing a money
        // figure through a float is how a penny goes missing.
        return is_numeric($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
