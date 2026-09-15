<?php

namespace App\Domain\Meta\Leads;

use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Ingestion\DTOs\MappedPayload;
use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\IngestionWriters;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\PayloadMapper;
use App\Domain\Ingestion\Writers\ImportBackedWriter;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Enums\MetaLeadStatus;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaForm;
use App\Domain\Meta\Models\MetaLead;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Domain\Shared\Duplicates\MatchStrategy;
use App\Domain\Shared\Models\DuplicateKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * A Facebook lead-ad submission, all the way to a lead somebody can ring.
 *
 * The webhook does not carry the lead. It carries an id, and the answers have
 * to be fetched from Graph with the page's own token — which is why this is not
 * the generic ingestion mapper handed a different payload: there is nothing to
 * map until the retrieval has happened, and the retrieval is the step that
 * fails in ways worth retrying.
 *
 * After that it is deliberately the same shape as every other inbound delivery:
 * map through the source's mapping, validate against the module's own rules,
 * deduplicate, write through the module's own action. A lead created here obeys
 * every rule a typed-in one does and fires the same observers — which is why
 * assignment is a workflow on "lead created" rather than a second rule engine
 * built for this phase.
 *
 * Three things it refuses to take from Meta, because a payload must not decide
 * them:
 *
 * - **the owner**, which comes from the source or from whoever connected Meta;
 * - **the source**, which is the channel the delivery arrived on;
 * - **the status**, which `CreateLeadAction` forces to New whatever is asked.
 */
class MetaLeadService
{
    /**
     * What Graph is asked for. `field_data` is the submission; the rest is the
     * attribution, read now rather than joined later because an ad renamed next
     * week must not rewrite what last week's lead came from.
     */
    public const LEAD_FIELDS = 'id,created_time,field_data,form_id,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,platform,is_organic';

    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly MetaForms $forms,
        private readonly PayloadMapper $mapper,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Fetch one lead's answers from Meta.
     *
     * With the **page** token, not the user's: a page token outlives the
     * session, the password change and the holiday of whoever authorised it,
     * and lead capture that stops when somebody goes away is worse than none.
     *
     * @return array<string, mixed>
     *
     * @throws MetaApiException
     */
    public function retrieve(string $leadId, string $token): array
    {
        return $this->client->get($leadId, ['fields' => self::LEAD_FIELDS], $token);
    }

    /**
     * Take one retrieved lead through to a record.
     *
     * @param  array<string, mixed>  $lead  As Graph returned it.
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    public function ingest(array $lead, DataSource $source, ?MetaPage $page = null): array
    {
        $metaLeadId = $this->leadId($lead);

        if ($metaLeadId === null) {
            // Not skipped: a lead-ads payload without a lead id is not
            // something Meta sends, and moving past it silently would hide a
            // subscription pointed somewhere it should not be.
            throw new RuntimeException('The retrieved lead had no id of its own.');
        }

        $submission = MetaLead::query()->firstOrNew(['meta_lead_id' => $metaLeadId]);

        // The idempotency gate. Meta retries anything it did not hear a 200
        // for, a queue can deliver a job twice, and 8.9's replay deliberately
        // runs a delivery again — so "did this submission already make a lead"
        // has to be the first question, and its answer is that lead rather than
        // a second copy of it.
        if ($submission->exists && $submission->lead_id !== null) {
            return $this->settle($submission, IntegrationEventStatus::Processed, 'duplicate', $submission->lead);
        }

        $form = $this->form($lead, $page);
        $payload = MetaLeadPayload::normalise($lead, $form?->name);
        $submittedAt = MetaLeadPayload::submittedAt($lead) ?? Carbon::now();

        $this->remember($submission, $lead, $payload, $form, $submittedAt);

        $writer = IngestionWriters::for($source->target_module);

        if ($writer === null) {
            throw new RuntimeException('Nothing can write into '.$source->target_module.'.');
        }

        $mapped = $this->map($source, $payload, $writer);
        $row = $this->rowFrom($mapped, $writer, $form);
        $owner = $this->owner($source, $page);

        if ($owner === null) {
            throw new RuntimeException(
                'Nobody owns what Meta creates. Set a default owner on the "'.$source->name.'" source, or reconnect Meta.'
            );
        }

        $existing = $this->existingLead($row);
        $policy = $source->dedupeAction();

        if ($existing !== null && $policy === DedupeAction::Skip) {
            // The submission is linked to that person anyway: it belongs to
            // them whether or not it changed anything, and a skipped lead with
            // nothing to point at is a dead end for whoever asks where their
            // enquiry went.
            return $this->settle($submission, IntegrationEventStatus::Skipped, 'skipped', $existing, MetaLeadStatus::Skipped);
        }

        $attribution = $this->attribution($payload, $form, $submittedAt);
        $isUpdate = $existing !== null && $policy === DedupeAction::Update;

        $crmLead = DB::transaction(function () use ($existing, $isUpdate, $writer, $row, $owner, $attribution, $mapped) {
            $record = $isUpdate
                ? $writer->update($existing, $row, $owner)
                : $writer->create($row, $owner);

            // Inside the transaction with the record: a lead that exists with
            // half its custom fields is worse than one that does not exist.
            $writer->writeCustomFields($record, $mapped->customFields);

            if ($record instanceof Lead) {
                // A first touch is recorded; a later one only fills the gaps.
                // The advertisement somebody came from months ago is still
                // where they came from, and a second form fill does not
                // overwrite it.
                $isUpdate
                    ? $record->enrichAttribution($attribution)
                    : $record->recordAttribution($attribution);
            }

            return $record;
        });

        if ($form !== null) {
            $this->forms->sawLead($form, $submittedAt);
        }

        if (! $isUpdate) {
            $this->announce($crmLead, $owner, $payload);
        }

        return $this->settle($submission, IntegrationEventStatus::Processed, $isUpdate ? 'updated' : 'created', $crmLead);
    }

    /**
     * Record that a submission could not be turned into a lead.
     *
     * Called once the pipeline has caught the failure, so a `meta_leads` row is
     * never left reading "received" for something that settled hours ago. The
     * row keeps its payload, which is what makes 8.9's replay worth having
     * after the mapping is fixed.
     */
    public function fail(string $metaLeadId, string $error): void
    {
        $submission = MetaLead::query()->where('meta_lead_id', $metaLeadId)->first();

        $submission?->forceFill([
            'status' => MetaLeadStatus::Failed->value,
            // Truncated: this column is read on a screen, and an exception from
            // the database layer can echo an entire query.
            'error' => mb_substr($error, 0, 1000),
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Run the source's mapping over the submission.
     *
     * @param  array<string, mixed>  $payload
     */
    private function map(DataSource $source, array $payload, ImportBackedWriter $writer): MappedPayload
    {
        $mapped = $this->mapper->map($this->withMappings($source), $payload, $writer);

        if (! $mapped->isComplete()) {
            throw new RuntimeException('Required by the mapping but absent: '.implode(', ', $mapped->missing).'.');
        }

        return $mapped;
    }

    /**
     * The mapped values, with the ones a payload may not decide forced back to
     * what this channel actually is, and refused outright if they would make a
     * record the application would not accept by hand.
     *
     * @return array<string, string|null>
     */
    private function rowFrom(MappedPayload $mapped, ImportBackedWriter $writer, ?MetaForm $form): array
    {
        $row = $mapped->row;

        // Where a lead came from is a fact about the channel, not an answer on
        // a form. A question named "source" must not be able to tell the CRM
        // this enquiry was a referral.
        $row['source'] = LeadSource::FacebookLeadAds->value;

        $this->guardRequired($row, $writer, $form);

        // The module's own rules, the same ones an import and a typed-in record
        // obey.
        $validator = Validator::make($row, $writer->rulesFor(array_keys($row)));

        if ($validator->fails()) {
            throw new RuntimeException(implode(' ', $validator->errors()->all()));
        }

        return $row;
    }

    /**
     * A source with its own mappings, or with the standard Meta ones.
     *
     * Set on the relation rather than written to the table: a default that
     * saved itself would appear on the mapping screen as a rule somebody there
     * had chosen, and deleting it would bring it straight back.
     */
    private function withMappings(DataSource $source): DataSource
    {
        if ($source->mappings->isNotEmpty()) {
            return $source;
        }

        return $source->setRelation('mappings', MetaLeadDefaults::mappings());
    }

    /**
     * Refuse a half lead, loudly.
     *
     * The module declares which of its fields are required, and a form that
     * asks for none of them produces a row that would create a nameless record
     * — the sort of thing noticed weeks later, in a list of leads called "  ".
     * Failing here puts it in the delivery log with the form's name on it,
     * which is where somebody can fix the mapping and replay it.
     *
     * @param  array<string, string|null>  $row
     */
    private function guardRequired(array $row, ImportBackedWriter $writer, ?MetaForm $form): void
    {
        $missing = [];

        foreach ($writer->fields() as $key => $field) {
            if ($field->required && ($row[$key] ?? null) === null) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The form %s does not supply %s. Map the question that carries it under the source\'s field mapping.',
            $form === null ? 'this lead came from' : '"'.$form->name.'"',
            implode(' or ', $missing),
        ));
    }

    /**
     * The lead this submission is probably about, if there is one.
     *
     * Through the fingerprints the duplicate engine already keeps rather than a
     * `where email = ?`: those are normalised — addresses lower-cased, numbers
     * compared on their last nine digits — so a number Meta sends as
     * `+44 117 000 0000` matches one somebody typed as `0117 000 0000`. A
     * column comparison misses that and creates the second copy this exists to
     * prevent.
     *
     * **Email before telephone**, which is the other way round from the order
     * the brief lists and is deliberate: an address is an identity, a number is
     * frequently a switchboard two colleagues share. Where they point at
     * different people the address is the one to believe — the same judgement
     * the match weights already encode.
     *
     * @param  array<string, string|null>  $row
     */
    private function existingLead(array $row): ?Lead
    {
        $candidates = [
            [MatchStrategy::Email, $row['email'] ?? null],
            [MatchStrategy::Phone, $row['phone'] ?? null],
            [MatchStrategy::Phone, $row['mobile'] ?? null],
        ];

        foreach ($candidates as [$strategy, $value]) {
            $fingerprint = $strategy->normalise(is_string($value) ? $value : null);

            // Null means "too weak to match on", which is not the same as "no
            // match": a blank must never match every record with a blank.
            if ($fingerprint === null) {
                continue;
            }

            /** @var array<int, int> $ids */
            $ids = DuplicateKey::query()
                ->where('keyable_type', Lead::class)
                ->where('kind', $strategy->value)
                ->where('value', $fingerprint)
                ->pluck('keyable_id')
                ->all();

            if ($ids === []) {
                continue;
            }

            $lead = Lead::query()
                ->whereKey($ids)
                // A record merged away is a resolved duplicate; updating it
                // would write into the one somebody deliberately retired.
                ->whereNull('merged_into_id')
                // The oldest match is the original, which is the one an update
                // should land on.
                ->orderBy('id')
                ->first();

            if ($lead !== null) {
                return $lead;
            }
        }

        return null;
    }

    /**
     * Who owns what Meta creates.
     *
     * The source's configured owner first, then whoever connected Meta — they
     * set the integration up, and a lead owned by somebody who can find it
     * beats one owned by nobody. A record with no owner is how the visibility
     * scope springs a leak, so with neither this fails rather than writing one.
     */
    private function owner(DataSource $source, ?MetaPage $page): ?User
    {
        return $source->defaultOwner
            ?? $source->createdBy
            ?? $page?->account?->connectedBy;
    }

    /**
     * Where this lead came from, as a value the record keeps.
     *
     * @param  array<string, mixed>  $payload
     */
    private function attribution(array $payload, ?MetaForm $form, Carbon $submittedAt): MarketingAttribution
    {
        // The form's name if we have its row, and otherwise whatever the
        // normalised payload was told it was called.
        $formName = $form === null ? $this->text($payload, 'form_name') : $form->name;

        return new MarketingAttribution(
            source: LeadSource::FacebookLeadAds->value,
            // What a person reads first in the panel: which form they filled
            // in, rather than which numbered object it was.
            sourceDetail: $formName,
            metaLeadId: $this->text($payload, 'id'),
            pageId: $form?->page_id,
            formId: $this->text($payload, 'form_id'),
            formName: $formName,
            metaCampaignId: $this->text($payload, 'campaign_id'),
            metaCampaignName: $this->text($payload, 'campaign_name'),
            metaAdSetId: $this->text($payload, 'adset_id'),
            metaAdSetName: $this->text($payload, 'adset_name'),
            metaAdId: $this->text($payload, 'ad_id'),
            metaAdName: $this->text($payload, 'ad_name'),
            // Meta's moment, not ours: a lead the backfill picks up on Thursday
            // is still a lead from Tuesday, and recording otherwise puts it in
            // the wrong week of every report.
            capturedAt: $submittedAt,
        );
    }

    /**
     * Tell the owner a lead is waiting.
     *
     * Swallowed on failure, deliberately: the lead is written and committed,
     * and a notification channel that is down must not turn a delivery that
     * worked into one the log calls failed — which would also mean a replay
     * trying to create the lead a second time.
     *
     * @param  array<string, mixed>  $payload
     */
    private function announce(Model $lead, User $owner, array $payload): void
    {
        if (! $lead instanceof Lead) {
            return;
        }

        try {
            $this->notifier->send('meta.lead_received', [Recipient::user($owner, RecipientType::AssignedAgent)], [
                'lead' => [
                    'name' => $lead->fullName(),
                    'form' => $this->text($payload, 'form_name') ?? 'a Facebook form',
                    'campaign' => $this->text($payload, 'campaign_name') ?? 'no campaign',
                ],
            ], null, route('leads.show', $lead));
        } catch (Throwable $exception) {
            Log::warning('A Meta lead was created but its notification could not be sent.', [
                'lead' => $lead->getKey(),
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The form this lead came from, refreshed at most once a day.
     *
     * @param  array<string, mixed>  $lead
     */
    private function form(array $lead, ?MetaPage $page): ?MetaForm
    {
        $formId = $lead['form_id'] ?? null;

        if (! is_string($formId) || $formId === '') {
            return null;
        }

        return $this->forms->remember($formId, $page?->access_token, $page?->page_id);
    }

    /**
     * Write down what arrived, before anything decides what to do with it.
     *
     * @param  array<string, mixed>  $lead
     * @param  array<string, mixed>  $payload
     */
    private function remember(MetaLead $submission, array $lead, array $payload, ?MetaForm $form, Carbon $submittedAt): void
    {
        $submission->forceFill([
            'meta_form_id' => $form?->getKey(),
            'form_id' => $this->text($payload, 'form_id'),
            'page_id' => $form?->page_id,
            'meta_campaign_id' => $this->text($payload, 'campaign_id'),
            'meta_campaign_name' => $this->text($payload, 'campaign_name'),
            'meta_ad_set_id' => $this->text($payload, 'adset_id'),
            'meta_ad_set_name' => $this->text($payload, 'adset_name'),
            'meta_ad_id' => $this->text($payload, 'ad_id'),
            'meta_ad_name' => $this->text($payload, 'ad_name'),
            // What Graph answered, as it answered it: the answers are the only
            // account of what the customer typed, and a mapping corrected next
            // week is replayed against these.
            'payload' => json_encode($lead),
            'received_at' => $submittedAt,
            'status' => MetaLeadStatus::Received->value,
            'error' => null,
        ])->save();
    }

    /**
     * @return array{status: IntegrationEventStatus, outcome: string, record: Model|null}
     */
    private function settle(
        MetaLead $submission,
        IntegrationEventStatus $status,
        string $outcome,
        ?Model $lead,
        ?MetaLeadStatus $submissionStatus = null,
    ): array {
        if ($submission->exists) {
            $submission->forceFill([
                'lead_id' => $lead instanceof Lead ? $lead->getKey() : $submission->lead_id,
                'status' => ($submissionStatus ?? MetaLeadStatus::Processed)->value,
                'error' => null,
                'processed_at' => now(),
            ])->save();
        }

        return ['status' => $status, 'outcome' => $outcome, 'record' => $lead];
    }

    /**
     * @param  array<string, mixed>  $lead
     */
    private function leadId(array $lead): ?string
    {
        $id = $lead['id'] ?? null;

        if (is_int($id)) {
            return (string) $id;
        }

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
