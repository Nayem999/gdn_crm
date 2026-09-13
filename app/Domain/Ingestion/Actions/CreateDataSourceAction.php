<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\DataSourceData;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\Models\DataSource;
use App\Models\User;
use RuntimeException;

class CreateDataSourceAction
{
    /**
     * @throws RuntimeException when the target module is not one the gateway
     *                          writes into
     */
    public function __invoke(DataSourceData $data, User $actor): DataSource
    {
        $this->guardTarget($data->targetModule);

        $source = DataSource::query()->create([
            ...$data->toAttributes(),
            'created_by_id' => $actor->id,
        ]);

        return $source->refresh();
    }

    /**
     * Checked here and not only in the form's validation rules.
     *
     * The target module is what decides which table an outside system can write
     * into, so the check belongs where every path goes through it rather than
     * where one screen happens to run it. `IngestionTargets` is the only place
     * a module key becomes a class.
     */
    private function guardTarget(string $module): void
    {
        if (! IngestionTargets::has($module)) {
            throw new RuntimeException('That is not a module data can be brought into.');
        }
    }
}
