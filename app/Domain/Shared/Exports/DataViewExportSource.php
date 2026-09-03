<?php

namespace App\Domain\Shared\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A module's own answer to "what rows does this export contain, and what does
 * each cell say?".
 *
 * A queued export cannot serialise an Eloquent builder, and it must not trust
 * the browser to name tables or columns. So the module rebuilds its own query
 * from the saved state, with its own visibility scope still applied.
 */
interface DataViewExportSource
{
    /**
     * @return Builder<covariant Model>
     */
    public function exportQuery(ExportRequest $request): Builder;

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array;

    /**
     * Heading shown above a printed or PDF export.
     */
    public function exportTitle(): string;
}
