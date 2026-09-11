<?php

namespace App\Domain\Shared\Exports;

use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\CustomFields\CustomFieldColumns;
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
        $query = $this->source->exportQuery($this->request);

        if (in_array(HasCustomFields::class, class_uses_recursive($query->getModel()), true)) {
            $query->with('customFieldValues');
        }

        return $query;
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
        $cells = $this->source->exportRow($row, $this->request);

        // Custom field cells are filled here rather than in each module's
        // exportRow: the source has no attribute to read for them and would
        // return null, and doing it once is what stops one module quietly
        // exporting a blank column. Positional, because a row is an ordered
        // array matching the requested column keys.
        foreach ($this->request->columnKeys() as $index => $key) {
            if (CustomFieldColumns::isCustom($key)) {
                $cells[$index] = CustomFieldColumns::exportValue($row, $key);
            }
        }

        return $cells;
    }
}
