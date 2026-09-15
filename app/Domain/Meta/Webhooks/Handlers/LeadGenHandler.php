<?php

namespace App\Domain\Meta\Webhooks\Handlers;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Leads\MetaLeadService;
use App\Domain\Meta\Models\MetaForm;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Webhooks\MetaSources;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * What a Facebook Lead Ads delivery does.
 *
 * Meta's webhook is a notification, not the lead: it says "submission 900112233
 * happened on form 5566778899", and the answers have to be fetched with the
 * page's own token. So this resolves the page, retrieves, and hands the result
 * to the service — and the two things that can go wrong before then are
 * answered differently on purpose:
 *
 * - **A page this installation does not manage is skipped**, not failed. A
 *   business often has pages the CRM was never connected to, and a webhook
 *   subscription is per app rather than per page: turning somebody else's page
 *   into a red row in the health panel would bury the deliveries that are
 *   genuinely broken.
 * - **Meta being unreachable throws.** That is worth retrying and worth seeing,
 *   and the delivery is kept so it can be replayed once Meta is back.
 *
 * Two payload shapes reach here. The webhook's envelope, and — from the
 * backfill — a lead exactly as Graph returns it. The second is not re-fetched:
 * it arrived complete, and asking Meta again for something already in hand
 * would spend a call per lead against the very rate limit a backfill is trying
 * to catch up through.
 */
class LeadGenHandler implements MetaChannelHandler
{
    public function __construct(private readonly MetaLeadService $service) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    public function handle(IntegrationEvent $event, array $payload): array
    {
        $source = $event->dataSource ?? MetaSources::for(MetaChannel::LeadGen);

        // A lead as Graph returns it — `field_data` is the submission, and the
        // webhook envelope never carries one.
        if (array_key_exists('field_data', $payload)) {
            return $this->one($payload, $source, $this->pageForForm($payload));
        }

        $results = [];

        foreach ($this->submissions($payload) as $submission) {
            $results[] = $this->retrieve($submission, $source);
        }

        if ($results === []) {
            // A delivery on the leadgen subscription that names no submission.
            // Recorded rather than failed: the body is kept, and somebody
            // reading the log can see exactly what Meta sent.
            return ['status' => IntegrationEventStatus::Skipped, 'outcome' => 'no_leadgen'];
        }

        return $this->summarise($results);
    }

    /**
     * Fetch one submission's answers and hand them on.
     *
     * @param  array{lead_id: string, page_id: string|null}  $submission
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    private function retrieve(array $submission, DataSource $source): array
    {
        $page = $submission['page_id'] === null
            ? null
            : MetaPage::query()->where('page_id', $submission['page_id'])->first();

        if ($page === null) {
            return ['status' => IntegrationEventStatus::Skipped, 'outcome' => 'unknown_page'];
        }

        if (! $page->isUsable()) {
            // Listed during the wizard and never finished connecting. Skipped
            // rather than failed, but visibly: there is nothing to retry until
            // somebody reconnects the page.
            return ['status' => IntegrationEventStatus::Skipped, 'outcome' => 'no_page_token'];
        }

        // Meta being unreachable throws out of here, which fails the event and
        // keeps the body. That is the right answer: it is worth retrying, and
        // both Meta's own retry and 8.9's replay are routes back to it.
        $lead = $this->service->retrieve($submission['lead_id'], (string) $page->access_token);

        return $this->one($lead, $source, $page);
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    private function one(array $lead, DataSource $source, ?MetaPage $page): array
    {
        try {
            return $this->service->ingest($lead, $source, $page);
        } catch (Throwable $exception) {
            // The delivery's own account of the failure lives on the event; this
            // is the submission's, which outlives it and is what somebody
            // looking for one missing lead searches by.
            $id = $lead['id'] ?? null;

            if (is_string($id) || is_int($id)) {
                $this->service->fail((string) $id, class_basename($exception).': '.$exception->getMessage());
            }

            throw $exception;
        }
    }

    /**
     * The lead-ad submissions named in a webhook envelope.
     *
     * Everything is checked rather than assumed: the payload is a stranger's
     * document, an `entry` that is not a list or a `value` that is not an
     * object is simply not a submission, and nothing in it names a column, a
     * class or a page — the ids are looked up, never dereferenced.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{lead_id: string, page_id: string|null}>
     */
    private function submissions(array $payload): array
    {
        $submissions = [];

        foreach (is_array($payload['entry'] ?? null) ? $payload['entry'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryPage = $this->id($entry, 'id');

            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== MetaChannel::LeadGen->field()) {
                    continue;
                }

                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $leadId = $this->id($value, 'leadgen_id');

                if ($leadId === null) {
                    continue;
                }

                $submissions[] = [
                    'lead_id' => $leadId,
                    // The change's own page id, falling back to the entry's:
                    // Meta sends both and they agree, but a delivery carrying
                    // only one should still find its page.
                    'page_id' => $this->id($value, 'page_id') ?? $entryPage,
                ];
            }
        }

        return $submissions;
    }

    /**
     * The page a backfilled lead belongs to, by way of its form.
     *
     * A lead as Graph returns it names its form and not its page, and the form
     * is where the page was recorded when the backfill walked it.
     *
     * @param  array<string, mixed>  $lead
     */
    private function pageForForm(array $lead): ?MetaPage
    {
        $formId = $this->id($lead, 'form_id');

        if ($formId === null) {
            return null;
        }

        $pageId = MetaForm::query()->where('form_id', $formId)->value('page_id');

        return is_string($pageId) && $pageId !== ''
            ? MetaPage::query()->where('page_id', $pageId)->first()
            : null;
    }

    /**
     * One answer for a delivery that may have carried several submissions.
     *
     * Writing beats not writing: a delivery that made a lead reads as "created"
     * even when a second submission in the same envelope was a duplicate,
     * because the question the log is asked is "did anything come of this".
     *
     * @param  array<int, array{status: IntegrationEventStatus, outcome: string, record?: Model|null}>  $results
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    private function summarise(array $results): array
    {
        foreach (['created', 'updated', 'skipped', 'duplicate'] as $preferred) {
            foreach ($results as $result) {
                if ($result['outcome'] === $preferred) {
                    return $result;
                }
            }
        }

        return $results[0] ?? ['status' => IntegrationEventStatus::Skipped, 'outcome' => 'no_leadgen'];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function id(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
