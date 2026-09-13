<?php

namespace App\Livewire\Settings;

use App\Domain\Ingestion\Actions\DryRunMappingAction;
use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\IngestionWriters;
use App\Domain\Ingestion\MappingSuggester;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceMapping;
use App\Domain\Ingestion\PayloadMapper;
use App\Domain\Ingestion\ValueTransformer;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where each field of a record gets its value from.
 *
 * Built around a **real payload** rather than a form you type paths into.
 * Mapping against the other system's documentation means mapping against what
 * their documentation says they send, which is rarely what they send — so the
 * screen listens for one delivery, keeps it, and turns every path in it into
 * something to click.
 */
#[Title('Field mapping')]
class SourceMapping extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $sourceId;

    /**
     * The rows being edited, as plain arrays.
     *
     * Held in state rather than saved per keystroke: a mapping half-written is
     * a mapping that would run on the next delivery, and somebody building one
     * is by definition not finished.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    // -- Matching (8.6) -------------------------------------------------------

    public ?string $externalIdPath = null;

    /**
     * @var array<int, string>
     */
    public array $dedupeFields = [];

    public string $dedupeAction = 'update';

    public ?string $testPayload = null;

    /**
     * @var array<int, string>
     */
    public array $dryRunErrors = [];

    /**
     * @var array<string, string|null>|null
     */
    public ?array $dryRunResult = null;

    public function mount(DataSource $source): void
    {
        $this->authorize('update', $source);

        $this->sourceId = $source->id;
        $this->externalIdPath = $source->external_id_path;
        $this->dedupeFields = $source->dedupe_fields ?? [];
        $this->dedupeAction = $source->dedupeAction()->value;
        $this->loadRows();
    }

    public function source(): DataSource
    {
        return DataSource::query()->findOrFail($this->sourceId);
    }

    private function loadRows(): void
    {
        $this->rows = $this->source()->mappings->map(fn (DataSourceMapping $mapping) => [
            'id' => $mapping->id,
            'source_path' => $mapping->source_path,
            'target_field' => $mapping->target_field,
            'is_custom_field' => $mapping->is_custom_field,
            'transform' => $mapping->transform,
            // Flattened for the form: a value map is edited as `from = to`
            // lines because that is how somebody writes a short translation
            // table, and rebuilt into an array on save.
            'transform_map' => $this->mapToText($mapping->transform_options['map'] ?? null),
            'transform_fallback' => $mapping->transform_options['fallback'] ?? null,
            'transform_from' => $mapping->transform_options['from'] ?? null,
            'default_value' => $mapping->default_value,
            'is_required' => $mapping->is_required,
        ])->values()->all();
    }

    // -- What can be mapped ---------------------------------------------------

    /**
     * Every field a mapping may target, module fields first then custom ones.
     *
     * @return array<string, string>
     */
    public function targetOptions(): array
    {
        $writer = IngestionWriters::for($this->source()->target_module);
        $options = [];

        foreach ($writer?->fields() ?? [] as $key => $field) {
            $options[$key] = $field->label;
        }

        foreach ($this->customFieldOptions() as $key => $label) {
            // Prefixed in the list so somebody can tell a custom field from a
            // built-in one when both are called "Region".
            $options[$key] = $label.' (custom field)';
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function customFieldOptions(): array
    {
        $source = $this->source();

        return collect(app(PayloadMapper::class)->customFieldKeys($source))
            ->mapWithKeys(fn (string $key) => [$key => $key])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function samplePaths(): array
    {
        return $this->source()->samplePaths();
    }

    /**
     * @return array<string, string>
     */
    public function transformOptions(): array
    {
        return app(ValueTransformer::class)->options();
    }

    // -- Listen mode ----------------------------------------------------------

    public function listen(): void
    {
        $source = $this->source();

        $this->authorize('update', $source);

        $source->forceFill([
            'listening_until' => now()->addMinutes(DataSource::LISTEN_MINUTES),
        ])->save();

        $this->dispatch(
            'notify',
            type: 'success',
            message: 'Listening for '.DataSource::LISTEN_MINUTES.' minutes. Send one delivery from the other system.',
        );
    }

    public function stopListening(): void
    {
        $source = $this->source();

        $this->authorize('update', $source);

        $source->forceFill(['listening_until' => null])->save();
    }

    // -- The rows -------------------------------------------------------------

    public function addRow(): void
    {
        $this->rows[] = [
            'id' => null,
            'source_path' => '',
            'target_field' => '',
            'is_custom_field' => false,
            'transform' => null,
            'transform_map' => null,
            'transform_fallback' => null,
            'transform_from' => null,
            'default_value' => null,
            'is_required' => false,
        ];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);

        // Re-indexed, or Livewire hands row 0's state to whatever slid into
        // index 0 — the same trap the filter builder documents.
        $this->rows = array_values($this->rows);
    }

    /**
     * Fill in what the sample and the module obviously have in common.
     *
     * Only ever **adds** rows for fields nothing is mapped to yet: overwriting
     * somebody's careful mapping with a guess is the one thing a suggestion
     * must not do.
     */
    public function suggest(): void
    {
        $source = $this->source();

        $this->authorize('update', $source);

        $writer = IngestionWriters::for($source->target_module);

        if ($writer === null) {
            return;
        }

        $already = array_column($this->rows, 'target_field');
        $suggestions = MappingSuggester::suggest($source->samplePaths(), $writer->fields());
        $added = 0;

        foreach ($suggestions as $field => $path) {
            if (in_array($field, $already, true)) {
                continue;
            }

            $this->rows[] = [
                'id' => null,
                'source_path' => $path,
                'target_field' => $field,
                'is_custom_field' => false,
                'transform' => null,
                'transform_map' => null,
                'transform_fallback' => null,
                'transform_from' => null,
                'default_value' => null,
                'is_required' => false,
            ];
            $added++;
        }

        $this->dispatch(
            'notify',
            type: 'success',
            message: $added === 0
                ? 'Nothing obvious left to suggest — the rest need a decision.'
                : $added.' '.str('mapping')->plural($added).' suggested. Check them before saving.',
        );
    }

    public function save(): void
    {
        $source = $this->source();

        $this->authorize('update', $source);

        $this->validate([
            'rows.*.source_path' => ['required', 'string', 'max:255'],
            'rows.*.target_field' => ['required', 'string', 'max:120'],
            'rows.*.default_value' => ['nullable', 'string', 'max:500'],
            'rows.*.transform' => ['nullable', 'string', 'max:32'],
        ], [], [
            'rows.*.source_path' => 'payload path',
            'rows.*.target_field' => 'field',
        ]);

        $targets = $this->targetOptions();
        $custom = $this->customFieldOptions();

        // A target the module does not offer is refused rather than stored. A
        // mapping naming a field that does not exist is ignored at run time, so
        // storing one produces a rule that silently does nothing.
        foreach ($this->rows as $index => $row) {
            if (! array_key_exists((string) $row['target_field'], $targets)) {
                $this->addError('rows.'.$index.'.target_field', 'That is not a field on '.$source->targetLabel().'.');

                return;
            }
        }

        // Replaced wholesale rather than diffed: the rows on screen are the
        // mapping, and reconciling by id would leave a row somebody deleted
        // alive if its id never reached the browser.
        $source->mappings()->delete();

        foreach (array_values($this->rows) as $position => $row) {
            $field = (string) $row['target_field'];

            $source->mappings()->create([
                'source_path' => trim((string) $row['source_path']),
                'target_field' => $field,
                'is_custom_field' => array_key_exists($field, $custom),
                'transform' => $row['transform'] === '' ? null : $row['transform'],
                'transform_options' => $this->transformOptionsFor($row),
                'default_value' => $row['default_value'] === '' ? null : $row['default_value'],
                'is_required' => (bool) ($row['is_required'] ?? false),
                'position' => $position,
            ]);
        }

        $this->loadRows();
        $this->dispatch('notify', type: 'success', message: 'Mapping saved.');
    }

    /**
     * The options a row's transform actually uses, and none it does not.
     *
     * Filtered by what the transform declares it needs, so switching a row from
     * a value map to a date does not leave the old translation table sitting in
     * the column waiting to confuse somebody.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function transformOptionsFor(array $row): ?array
    {
        $transform = (string) ($row['transform'] ?? '');

        if ($transform === '') {
            return null;
        }

        $needed = app(ValueTransformer::class)->configurableOptions($transform);
        $options = [];

        if (in_array('map', $needed, true)) {
            $map = $this->textToMap((string) ($row['transform_map'] ?? ''));

            if ($map !== []) {
                $options['map'] = $map;
            }
        }

        if (in_array('fallback', $needed, true) && filled($row['transform_fallback'] ?? null)) {
            $options['fallback'] = (string) $row['transform_fallback'];
        }

        if (in_array('from', $needed, true) && filled($row['transform_from'] ?? null)) {
            $options['from'] = (string) $row['transform_from'];
        }

        return $options === [] ? null : $options;
    }

    /**
     * `their value = ours`, one per line.
     *
     * A textarea rather than a repeater of paired inputs: a translation table
     * is usually pasted in from somewhere, and twelve rows of two inputs each
     * is a screen nobody wants to fill in.
     *
     * @return array<string, string>
     */
    private function textToMap(string $text): array
    {
        $map = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$from, $to] = explode('=', $line, 2);
            $from = trim($from);

            if ($from !== '') {
                $map[$from] = trim($to);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>|null  $map
     */
    private function mapToText(?array $map): ?string
    {
        if (! is_array($map) || $map === []) {
            return null;
        }

        $lines = [];

        foreach ($map as $from => $to) {
            $lines[] = $from.' = '.(is_scalar($to) ? $to : '');
        }

        return implode("\n", $lines);
    }

    // -- Matching ---------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function dedupeFieldOptions(): array
    {
        $writer = IngestionWriters::for($this->source()->target_module);
        $options = [];

        foreach ($writer?->fields() ?? [] as $key => $field) {
            $options[$key] = $field->label;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function dedupeActionOptions(): array
    {
        return DedupeAction::options();
    }

    public function saveMatching(): void
    {
        $source = $this->source();

        $this->authorize('update', $source);

        $this->validate([
            'externalIdPath' => ['nullable', 'string', 'max:255'],
            'dedupeFields' => ['array'],
            'dedupeFields.*' => ['string', Rule::in(array_keys($this->dedupeFieldOptions()))],
            'dedupeAction' => ['required', Rule::in(array_keys(DedupeAction::options()))],
        ], [], [
            'externalIdPath' => 'their id',
            'dedupeFields' => 'matching fields',
            'dedupeAction' => 'what to do',
        ]);

        $source->forceFill([
            'external_id_path' => filled($this->externalIdPath) ? trim((string) $this->externalIdPath) : null,
            'dedupe_fields' => $this->dedupeFields === [] ? null : array_values($this->dedupeFields),
            'dedupe_action' => $this->dedupeAction,
        ])->save();

        $this->dispatch('notify', type: 'success', message: 'Matching rules saved.');
    }

    // -- Dry run ---------------------------------------------------------------

    /**
     * What a delivery would produce, without producing it.
     *
     * Runs against what is **saved**, not what is on screen, and says so: a
     * preview of unsaved rows would answer a question nobody asked, because the
     * next real delivery uses the saved ones.
     */
    public function dryRun(): void
    {
        $source = $this->source();

        $this->authorize('view', $source);

        $result = app(DryRunMappingAction::class)($source, $this->testPayload ?: null);

        $this->dryRunErrors = $result['errors'];
        $this->dryRunResult = $result['mapped']?->all();
    }

    /**
     * @return Collection<int, DataSourceMapping>
     */
    public function saved(): Collection
    {
        return $this->source()->mappings;
    }

    public function render(): View
    {
        return view('livewire.settings.source-mapping', ['source' => $this->source()]);
    }
}
