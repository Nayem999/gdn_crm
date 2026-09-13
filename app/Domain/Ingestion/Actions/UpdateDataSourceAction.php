<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\DataSourceData;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\Models\DataSource;
use RuntimeException;

class UpdateDataSourceAction
{
    /**
     * @throws RuntimeException when the target module is unknown, or when it
     *                          would move under records the source has already
     *                          written
     */
    public function __invoke(DataSource $source, DataSourceData $data): DataSource
    {
        if (! IngestionTargets::has($data->targetModule)) {
            throw new RuntimeException('That is not a module data can be brought into.');
        }

        $this->guardRetarget($source, $data->targetModule);

        $source->update($data->toAttributes());

        return $source->refresh();
    }

    /**
     * A source that has written records cannot be pointed at another module.
     *
     * Its event log says what each delivery produced, and re-aiming the source
     * would leave a history of leads hanging off something that now claims to
     * make contacts — and, once 8.5 lands, a set of field mappings that name
     * columns the new module does not have. Neither fails loudly; both quietly
     * stop meaning what they say.
     *
     * Deliveries alone are not enough to lock it: somebody still setting an
     * integration up has sent test payloads and must be able to correct a
     * mistake. It is the first *written record* that fixes the target, which is
     * also the moment the choice starts to matter.
     */
    private function guardRetarget(DataSource $source, string $target): void
    {
        if ($target === $source->target_module) {
            return;
        }

        $written = $source->events()->whereNotNull('record_id')->exists();

        if ($written) {
            throw new RuntimeException(
                'This source has already created records in '.$source->targetLabel()
                .'. Add a new source rather than pointing this one somewhere else.'
            );
        }
    }
}
