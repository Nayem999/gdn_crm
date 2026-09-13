<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Models\DataSource;

class DeleteDataSourceAction
{
    /**
     * Removes a source, and stops anything further arriving at its address.
     *
     * Soft-deleted, and the event log is kept. What an outside system sent, and
     * what the CRM did with it, is the answer to "where did this record come
     * from" — and that question outlives the integration. `forIngest()` refuses
     * a deleted source, so keeping the rows does not keep the door open.
     */
    public function __invoke(DataSource $source): void
    {
        $source->delete();
    }
}
