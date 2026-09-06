<?php

namespace App\Livewire\Deals;

use App\Domain\Deals\Actions\ReorderPipelineStagesAction;
use App\Domain\Deals\Actions\SavePipelineAction;
use App\Domain\Deals\DTOs\PipelineData;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Shared\UI\ChipPalette;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One pipeline and the stages along it, edited together.
 *
 * Stages are edited here rather than on their own screen because a pipeline
 * without stages is not a usable thing — saving the two separately would leave
 * a window where a deal could be created against a pipeline with nowhere to put
 * it.
 */
class PipelineForm extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $pipelineId = null;

    public string $name = '';

    public string $description = '';

    public bool $isDefault = false;

    /**
     * The stage rows as edited. `key` is empty for a row being added and
     * carries the existing key for one being kept — the action only honours a
     * key that already names a stage on this pipeline.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $stages = [];

    /**
     * Changes only when a row is added or removed, so the Tom Select fields
     * behind wire:ignore rebuild then and not on every keystroke.
     */
    public int $generation = 0;

    public function mount(?Pipeline $pipeline = null): void
    {
        if ($pipeline?->exists) {
            $this->authorize('update', $pipeline);

            $this->pipelineId = $pipeline->id;
            $this->name = $pipeline->name;
            $this->description = (string) $pipeline->description;
            $this->isDefault = $pipeline->is_default;

            foreach ($pipeline->stages as $stage) {
                $this->stages[] = [
                    'key' => $stage->key,
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'probability' => (string) $stage->probability,
                    'outcome' => $stage->outcome()->value,
                ];
            }

            return;
        }

        $this->authorize('create', Pipeline::class);

        // A new pipeline starts with something workable rather than a blank
        // list: every pipeline needs an end, and nobody wants to type it.
        $this->stages = [
            $this->blankStage('New', StageOutcome::Open, 10, 'slate'),
            $this->blankStage('Closed won', StageOutcome::Won, 100, 'emerald'),
            $this->blankStage('Closed lost', StageOutcome::Lost, 0, 'rose'),
        ];
    }

    public function pipeline(): ?Pipeline
    {
        return $this->pipelineId === null
            ? null
            : Pipeline::query()->with('stages')->whereKey($this->pipelineId)->first();
    }

    public function isEditing(): bool
    {
        return $this->pipelineId !== null;
    }

    // -- Stage rows ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function blankStage(string $name = '', StageOutcome $outcome = StageOutcome::Open, int $probability = 0, string $color = 'slate'): array
    {
        return [
            'key' => '',
            'name' => $name,
            'color' => $color,
            'probability' => (string) $probability,
            'outcome' => $outcome->value,
        ];
    }

    public function addStage(): void
    {
        $this->stages[] = $this->blankStage();
        $this->generation++;
    }

    public function removeStage(int $index): void
    {
        if (! array_key_exists($index, $this->stages)) {
            return;
        }

        unset($this->stages[$index]);
        $this->stages = array_values($this->stages);
        $this->generation++;
        $this->resetErrorBag();
    }

    /**
     * Keep a closed stage's probability where it belongs the moment the outcome
     * changes, rather than waiting for the save to correct it — a field showing
     * 60% against "Won" is a field somebody will believe.
     */
    public function updated(string $property): void
    {
        if (! preg_match('/^stages\.(\d+)\.outcome$/', $property, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $outcome = StageOutcome::tryFrom((string) ($this->stages[$index]['outcome'] ?? ''));
        $fixed = $outcome?->fixedProbability();

        if ($fixed !== null) {
            $this->stages[$index]['probability'] = (string) $fixed;
        }
    }

    /**
     * The order the drag left the stages in.
     *
     * Reordering an unsaved pipeline only moves the rows on screen; there is
     * nothing to write yet, and the save takes its positions from this order.
     *
     * @param  array<int, string>  $orderedKeys
     */
    public function reorderStages(array $orderedKeys): void
    {
        $byKey = [];
        $unkeyed = [];

        foreach ($this->stages as $stage) {
            $key = (string) ($stage['key'] ?? '');

            if ($key === '') {
                $unkeyed[] = $stage;

                continue;
            }

            $byKey[$key] = $stage;
        }

        $reordered = [];

        foreach ($orderedKeys as $key) {
            $key = (string) $key;

            if (isset($byKey[$key])) {
                $reordered[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }

        // Anything the browser did not name keeps its place behind what it did.
        $this->stages = [...$reordered, ...array_values($byKey), ...$unkeyed];

        $pipeline = $this->pipeline();

        if ($pipeline !== null) {
            $this->authorize('update', $pipeline);

            app(ReorderPipelineStagesAction::class)($pipeline, array_map('strval', $orderedKeys));
        }
    }

    // -- Saving ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('pipelines', 'name')->ignore($this->pipelineId)->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.color' => ['required', 'string', Rule::in(ChipPalette::colors())],
            'stages.*.probability' => ['required', 'integer', 'min:0', 'max:100'],
            'stages.*.outcome' => ['required', Rule::in(array_keys(StageOutcome::options()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = ['name' => 'pipeline name', 'stages' => 'stages'];

        foreach (array_keys($this->stages) as $index) {
            $position = $index + 1;
            $attributes['stages.'.$index.'.name'] = 'stage '.$position.' name';
            $attributes['stages.'.$index.'.probability'] = 'stage '.$position.' probability';
            $attributes['stages.'.$index.'.color'] = 'stage '.$position.' colour';
            $attributes['stages.'.$index.'.outcome'] = 'stage '.$position.' outcome';
        }

        return $attributes;
    }

    public function save(): void
    {
        $pipeline = $this->pipeline();

        if ($pipeline === null) {
            $this->authorize('create', Pipeline::class);
        } else {
            $this->authorize('update', $pipeline);
        }

        $this->validate();

        $data = PipelineData::fromArray([
            'name' => $this->name,
            'description' => $this->description,
            'is_default' => $this->isDefault,
            'stages' => $this->stages,
        ]);

        try {
            $saved = app(SavePipelineAction::class)($data, $pipeline);
        } catch (RuntimeException $exception) {
            $this->addError('stages', $exception->getMessage());

            return;
        }

        session()->flash('status', $saved->name.' was saved.');

        $this->redirectRoute('settings.pipelines', navigate: true);
    }

    // -- Options ---------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function colorOptions(): array
    {
        return array_map(
            fn (string $tone) => ['value' => $tone, 'label' => ucfirst($tone), 'color' => $tone],
            ChipPalette::colors()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function outcomeOptions(): array
    {
        return array_map(
            fn (StageOutcome $outcome) => [
                'value' => $outcome->value,
                'label' => $outcome->label(),
                'description' => $outcome->description(),
                'color' => $outcome->color(),
            ],
            StageOutcome::cases()
        );
    }

    public function isProbabilityFixed(int $index): bool
    {
        $outcome = StageOutcome::tryFrom((string) ($this->stages[$index]['outcome'] ?? ''));

        return $outcome?->fixedProbability() !== null;
    }

    public function render(): View
    {
        return view('livewire.deals.pipeline-form')
            ->title($this->isEditing() ? 'Edit pipeline' : 'Add pipeline');
    }
}
