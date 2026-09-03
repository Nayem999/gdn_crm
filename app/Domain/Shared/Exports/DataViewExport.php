<?php

namespace App\Domain\Shared\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Streams one list export straight from the query, so a large export does not
 * load every row into memory at once.
 *
 * @implements WithMapping<Model>
 */
class DataViewExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(
        private readonly ExportRequest $request,
        private readonly DataViewExportSource $source,
    ) {}

    /**
     * @return Builder<covariant Model>
     */
    public function query(): Builder
    {
        return $this->source->exportQuery($this->request);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->request->headings();
    }

    /**
     * @param  Model  $row
     * @return array<int, string|int|float|null>
     */
    public function map($row): array
    {
        return $this->source->exportRow($row, $this->request);
    }
}
