<?php

namespace App\Domain\Meta\Leads;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaForm;
use App\Domain\Meta\Models\MetaPage;
use Illuminate\Support\Carbon;

/**
 * The forms behind the leads, read from Meta once and kept.
 *
 * A lead-ads webhook carries a form id and nothing else, so without this a
 * lead's attribution reads "form 5566778899" — which nobody can act on, and
 * which makes the campaign figures in 12.13 a list of numbers.
 *
 * Read **once a day at most**. A form's name and questions change rarely, and
 * a Graph call per lead would spend the same rate limit the leads themselves
 * need on re-reading a string that has not changed since breakfast.
 *
 * Nothing here throws. A form is decoration on a lead — the lead is the thing
 * that matters, and losing one because Meta would not answer a question about
 * its form would be the integration failing at the only job it has.
 */
class MetaForms
{
    /**
     * How long a cached form is trusted before it is read again.
     */
    public const REFRESH_AFTER_HOURS = 24;

    /**
     * The fields worth asking for. `questions` is what a mapping has to be
     * written against, and the first thing to look at when a form edited at
     * Meta's end starts producing leads with empty columns.
     */
    private const FIELDS = 'id,name,status,questions';

    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * The form with this id, read from Meta when we have nothing recent.
     */
    public function remember(string $formId, ?string $token, ?string $pageId = null): ?MetaForm
    {
        $form = MetaForm::query()->where('form_id', $formId)->first();

        if (! $this->isStale($form) || $token === null || $token === '') {
            return $form;
        }

        try {
            $row = $this->client->get($formId, ['fields' => self::FIELDS], $token);
        } catch (MetaApiException) {
            // Their outage, not ours. Whatever we already had is better than
            // failing a lead over a label.
            return $form;
        }

        return $this->store($row, $pageId ?? $form?->page_id);
    }

    /**
     * Every lead form on a page, refreshed as they are walked.
     *
     * The backfill's starting point: leads are retrieved per form, so the list
     * of forms is the list of places a missed lead could be sitting.
     *
     * @return array<int, MetaForm>
     *
     * @throws MetaApiException
     */
    public function forPage(MetaPage $page): array
    {
        $token = $page->access_token;

        if (! is_string($token) || $token === '') {
            return [];
        }

        $forms = [];

        foreach ($this->client->paginate($page->page_id.'/leadgen_forms', [
            'fields' => self::FIELDS,
            'limit' => 100,
        ], $token) as $row) {
            $form = $this->store($row, $page->page_id);

            if ($form !== null) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * Record that this form has just produced a lead.
     *
     * Written as Meta's moment rather than ours, and only ever forward: a
     * backfill walking three days of history must not leave the mark behind
     * where the webhook has already reached, or the next backfill fetches the
     * same days again.
     */
    public function sawLead(MetaForm $form, ?Carbon $at = null): void
    {
        $at ??= Carbon::now();

        if ($form->last_lead_at !== null && $form->last_lead_at->gte($at)) {
            return;
        }

        $form->forceFill(['last_lead_at' => $at])->save();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function store(array $row, ?string $pageId): ?MetaForm
    {
        $id = $row['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $form = MetaForm::query()->firstOrNew(['form_id' => $id]);

        $form->forceFill([
            // Upserted, never replaced — and the page is only written when we
            // know it, so re-reading a form through a route that does not carry
            // one cannot unlink it from its page.
            'page_id' => $pageId ?? $form->page_id,
            'name' => is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : ($form->name ?? 'Lead form'),
            'status' => is_string($row['status'] ?? null) ? $row['status'] : $form->status,
            // Kept when this read did not ask for them: a route that fetches a
            // form without its questions must not blank the definitions a
            // mapping is written against.
            'questions' => $this->questions($row) ?? $form->questions,
            'last_synced_at' => now(),
        ])->save();

        return $form->refresh();
    }

    /**
     * Meta's question definitions, kept as they arrive.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, array<string, mixed>>|null
     */
    private function questions(array $row): ?array
    {
        $questions = $row['questions'] ?? null;

        if (! is_array($questions)) {
            return null;
        }

        return array_values(array_filter($questions, 'is_array'));
    }

    private function isStale(?MetaForm $form): bool
    {
        return $form === null
            || $form->last_synced_at === null
            || $form->last_synced_at->lt(Carbon::now()->subHours(self::REFRESH_AFTER_HOURS));
    }
}
