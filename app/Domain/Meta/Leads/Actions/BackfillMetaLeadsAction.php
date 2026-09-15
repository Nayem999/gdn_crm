<?php

namespace App\Domain\Meta\Leads\Actions;

use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Leads\MetaForms;
use App\Domain\Meta\Leads\MetaLeadService;
use App\Domain\Meta\Models\MetaForm;
use App\Domain\Meta\Models\MetaLead;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Jobs\ProcessIntegrationEvent;
use Illuminate\Support\Carbon;

/**
 * The leads that arrived while nobody was listening.
 *
 * A webhook is the only notice Meta gives, and it gives it once plus a few
 * retries over a few hours. An application that was down for a morning, a page
 * whose subscription lapsed, a webhook URL that was wrong for a day — in every
 * one of those the leads exist at Meta's end and nowhere here, and no amount of
 * waiting brings them.
 *
 * So this walks the forms and asks for what they have. Each lead it finds is
 * written into `integration_events` and dispatched exactly as a pushed delivery
 * is, for the reason the pull half of the gateway does the same: a backfilled
 * lead and a webhook lead are mapped, deduplicated and attributed by one piece
 * of code, and the delivery log answers "where did this come from" the same way
 * for both. A second path that created records directly would be a second set
 * of rules, and the one that drifts is always the one nobody looks at.
 *
 * `signature_verified` is false on what it writes. Nothing signed it — we asked
 * — and recording otherwise would make the log assert a guarantee nobody gave.
 */
class BackfillMetaLeadsAction
{
    /**
     * How far back a form with no history of its own is asked about.
     *
     * A week, not everything Meta still holds. A backfill exists to close a gap
     * in a running integration, and a first run that swept ninety days would
     * drop a quarter of history into somebody's lead list as though it had all
     * arrived this morning — every one of them notifying an owner and starting
     * an assignment workflow.
     */
    public const DEFAULT_LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly MetaForms $forms,
    ) {}

    /**
     * @return array{forms: int, found: int, queued: int, throttled: bool}
     *
     * @throws MetaApiException
     */
    public function __invoke(MetaPage $page, ?Carbon $since = null): array
    {
        $counts = ['forms' => 0, 'found' => 0, 'queued' => 0, 'throttled' => false];

        if (! $page->isUsable()) {
            // Nothing to read with. Not an exception: a sweep over several
            // pages must not stop because one was never finished connecting.
            return $counts;
        }

        $source = MetaSources::for(MetaChannel::LeadGen);
        $token = (string) $page->access_token;

        foreach ($this->forms->forPage($page) as $form) {
            // Asked between forms rather than between pages: carrying on past
            // Meta's ceiling gets the whole app throttled, which breaks every
            // other integration too — and the remaining forms will still be
            // there in an hour.
            if ($this->client->isNearRateLimit()) {
                $counts['throttled'] = true;

                break;
            }

            $counts['forms']++;

            foreach ($this->leads($form, $token, $since) as $lead) {
                $counts['found']++;

                if ($this->queue($lead, $source->getKey())) {
                    $counts['queued']++;
                }
            }
        }

        return $counts;
    }

    /**
     * One form's leads since we last heard from it.
     *
     * The window is the form's own `last_lead_at`, which the webhook keeps up
     * to date — so a backfill after an outage asks for the outage rather than
     * for the week, and asking twice in an afternoon fetches almost nothing.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws MetaApiException
     */
    private function leads(MetaForm $form, string $token, ?Carbon $since): iterable
    {
        $from = $since ?? $form->last_lead_at ?? Carbon::now()->subDays(self::DEFAULT_LOOKBACK_DAYS);

        return $this->client->paginate($form->form_id.'/leads', [
            'fields' => MetaLeadService::LEAD_FIELDS,
            // Meta's own filter syntax. Filtering at their end rather than
            // fetching everything and discarding it here is the difference
            // between one page and ninety days of them.
            'filtering' => (string) json_encode([[
                'field' => 'time_created',
                'operator' => 'GREATER_THAN',
                'value' => $from->getTimestamp(),
            ]]),
            'limit' => 100,
        ], $token);
    }

    /**
     * Write one found lead down as a delivery, unless we already have it.
     *
     * The check is on `meta_leads`, which is the lasting record of a
     * submission: an event log that has been pruned would otherwise make every
     * old lead look new again.
     *
     * @param  array<string, mixed>  $lead
     */
    private function queue(array $lead, int $sourceId): bool
    {
        $id = $lead['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return false;
        }

        $id = (string) $id;

        if (MetaLead::query()->where('meta_lead_id', $id)->exists()) {
            return false;
        }

        $body = (string) json_encode($lead);

        $event = new IntegrationEvent;

        $event->forceFill([
            'data_source_id' => $sourceId,
            'payload' => $body,
            'body_hash' => hash('sha256', $body),
            // The same key the webhook door uses, so a lead that arrives both
            // ways is one delivery in the log rather than two.
            'external_id' => 'leadgen:'.$id,
            'signature_verified' => false,
            'is_sandbox' => false,
            'received_at' => now(),
        ])->save();

        ProcessIntegrationEvent::dispatch($event->id);

        return true;
    }
}
