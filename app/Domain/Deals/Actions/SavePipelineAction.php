<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\DTOs\PipelineData;
use App\Domain\Deals\DTOs\StageData;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
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
            $pipeline = $pipeline ?? new Pipeline([
                'position' => (int) Pipeline::query()->max('position') + 1,
            ]);

            $pipeline->fill([
                'name' => $data->name,
                'description' => $data->description,
            ])->save();

            $this->syncStages($pipeline, $data->stages);

            return $pipeline;
        });

        // After the commit, so a failed stage sync cannot leave another
        // pipeline stripped of the default flag.
        if ($data->isDefault) {
            $saved = ($this->setDefault)($saved);
        } else {
            $this->setDefault->ensureOneExists();
        }

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
    private function syncStages(Pipeline $pipeline, array $stages): void
    {
        /** @var array<string, PipelineStage> $existing */
        $existing = $pipeline->stages()->get()->keyBy('key')->all();

        $keptKeys = [];
        $takenKeys = array_keys($existing);

        foreach (array_values($stages) as $position => $stage) {
            // A submitted key is only honoured when it names a stage already on
            // this pipeline. Anything else is a new stage and gets a key
            // derived from its name, so the browser cannot choose one.
            $current = $stage->key !== null ? ($existing[$stage->key] ?? null) : null;

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
    }
}
