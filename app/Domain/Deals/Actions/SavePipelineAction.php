<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\DTOs\PipelineData;
use App\Domain\Deals\DTOs\StageData;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use App\Domain\Deals\PipelineModules;
use App\Domain\Deals\PipelineStatusCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create or update a pipeline together with its stages.
 *
 * One action for both because a pipeline without stages is not a usable thing:
 * saving the two separately would leave a window where a deal could be created
 * against a pipeline that has nowhere to put it.
 */
class SavePipelineAction
{
    public function __construct(private readonly SetDefaultPipelineAction $setDefault) {}

    /**
     * @throws RuntimeException when the submitted stages would leave the
     *                          pipeline unusable
     */
    public function __invoke(PipelineData $data, ?Pipeline $pipeline = null): Pipeline
    {
        $this->guard($data);

        $saved = DB::transaction(function () use ($data, $pipeline) {
            // `module` is written on create and never afterwards. Moving a
            // pipeline between modules would strand every record sitting in its
            // stages — the rows would keep a key that no longer names anything
            // on their own module. Same rule a custom field's module follows.
            $pipeline = $pipeline ?? new Pipeline([
                'module' => $data->module,
                'position' => (int) Pipeline::query()->forModule($data->module)->max('position') + 1,
            ]);

            $pipeline->fill([
                'name' => $data->name,
                'description' => $data->description,
            ])->save();

            $this->syncStages($pipeline, $data->stages, $data->module);

            return $pipeline;
        });

        // After the commit, so a failed stage sync cannot leave another
        // pipeline stripped of the default flag.
        if ($data->isDefault) {
            $saved = ($this->setDefault)($saved);
        } else {
            $this->setDefault->ensureOneExists();
        }

        // The status sets are memoised per request; a renamed, reordered or
        // removed stage has to show on the very next render.
        app(PipelineStatusCache::class)->flush();

        return $saved->refresh()->load('stages');
    }

    /**
     * Bring the pipeline's stages in line with what was submitted.
     *
     * A stage the form still carries is updated in place, keeping its key, so
     * the deals sitting in it stay where they are. A stage the form dropped is
     * removed only if nothing is in it — refusing rather than silently
     * stranding deals in a stage that no longer exists.
     *
     * @param  array<int, StageData>  $stages
     *
     * @throws RuntimeException
     */
    private function syncStages(Pipeline $pipeline, array $stages, string $module): void
    {
        /** @var array<string, PipelineStage> $existing */
        $existing = $pipeline->stages()->get()->keyBy('key')->all();

        $keptKeys = [];
        $takenKeys = array_keys($existing);

        // On an enum-backed module the key set is closed and already known, so
        // a submitted key that names one of its values is as safe as a derived
        // one — and honouring it is the only way to *rename* a status. Without
        // this, calling "New" something else would derive a key the guard then
        // refuses, making a rename impossible on exactly the modules where
        // renaming is the point.
        // From the data rather than the model: on a create the row has not
        // been refreshed yet, and an older pipeline may predate the column.
        $allowedKeys = PipelineModules::fallbackKeys($module);

        foreach (array_values($stages) as $position => $stage) {
            // Otherwise a submitted key is honoured only when it names a stage
            // already on this pipeline. Anything else is a new stage and gets a
            // key derived from its name, so the browser cannot choose one.
            $current = $stage->key !== null ? ($existing[$stage->key] ?? null) : null;

            if ($current === null && $stage->key !== null && in_array($stage->key, $allowedKeys, true)) {
                $current = new PipelineStage([
                    'pipeline_id' => $pipeline->getKey(),
                    'key' => $stage->key,
                ]);
                $takenKeys[] = $stage->key;
            }

            if ($current === null) {
                $key = PipelineStage::keyFrom($stage->name, $takenKeys);
                $takenKeys[] = $key;
                $current = new PipelineStage(['pipeline_id' => $pipeline->getKey(), 'key' => $key]);
            }

            $current->fill([
                'name' => $stage->name,
                'color' => $stage->color,
                'probability' => $stage->probability,
                'outcome' => $stage->outcome->value,
                'position' => $position,
            ])->save();

            $keptKeys[] = $current->key;
        }

        foreach ($existing as $key => $stage) {
            if (in_array($key, $keptKeys, true)) {
                continue;
            }

            $deals = $stage->deals()->withTrashed()->count();

            if ($deals > 0) {
                throw new RuntimeException(
                    '"'.$stage->name.'" still has '.$deals.' '.str('deal')->plural($deals)
                    .' in it, so it cannot be removed. Move them on first.'
                );
            }

            $stage->delete();
        }
    }

    /**
     * @throws RuntimeException
     */
    private function guard(PipelineData $data): void
    {
        if (! PipelineModules::has($data->module)) {
            throw new RuntimeException('That is not a module a pipeline can be configured for.');
        }

        if ($data->stages === []) {
            throw new RuntimeException('A pipeline needs at least one stage.');
        }

        $names = [];

        foreach ($data->stages as $stage) {
            $name = mb_strtolower($stage->name);

            if (in_array($name, $names, true)) {
                throw new RuntimeException('Two stages cannot both be called "'.$stage->name.'".');
            }

            $names[] = $name;
        }

        $this->guardEnumBackedKeys($data);
    }

    /**
     * A stage on an enum-backed module must name one of that enum's values.
     *
     * Deals store a free-form stage key, so a deals pipeline may invent any
     * stage it likes. Leads and activities do not: `Lead::status()` resolves
     * the column through `LeadStatus::tryFrom` and falls back to New, so a
     * stage keyed `working` would make every lead in it read as "New" on the
     * record page, in the export and in conversion — silently, and everywhere
     * except the board that put them there.
     *
     * So those modules can be **renamed, recoloured, reordered and trimmed**,
     * which is what configuring a status set is for, but not extended. Lifting
     * that means giving leads a free-form status column and reworking
     * conversion, qualification and scoring around it — a Phase 2 change, not
     * a Phase 4 one.
     */
    private function guardEnumBackedKeys(PipelineData $data): void
    {
        $allowed = PipelineModules::fallbackKeys($data->module);

        // Deals, and anything else with no enum behind it, are unconstrained.
        if ($allowed === []) {
            return;
        }

        foreach ($data->stages as $stage) {
            $key = $stage->key !== null && $stage->key !== ''
                ? $stage->key
                : PipelineStage::keyFrom($stage->name);

            if (! in_array($key, $allowed, true)) {
                throw new RuntimeException(
                    '"'.$stage->name.'" is not a status '.strtolower(PipelineModules::label($data->module))
                    .' recognise. You can rename, recolour, reorder and remove the ones they have — '
                    .implode(', ', $allowed).' — but not add new ones.'
                );
            }
        }
    }
}
