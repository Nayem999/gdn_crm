<?php

namespace App\Domain\Shared\Exports;

use App\Domain\Shared\Enums\ExportFormat;

/**
 * Everything needed to reproduce one list export away from the request that
 * asked for it — so a queued job builds exactly the rows the user was looking
 * at, not the whole table.
 */
readonly class ExportRequest
{
    /**
     * Fixed at construction: a queued job writes the file, then names it again
     * in the notification, and the two must agree even across a second
     * boundary or a retry.
     */
    public string $filename;

    /**
     * @param  class-string<DataViewExportSource>  $source
     * @param  array<string, string>  $columns  Column key => heading, in display order.
     * @param  array<string, mixed>  $filters  The raw filter tree.
     * @param  array<int, int>  $selectedIds
     */
    public function __construct(
        public string $source,
        public ExportFormat $format,
        public string $module,
        public array $columns,
        public array $filters = [],
        public string $search = '',
        public string $sortBy = '',
        public string $sortDirection = 'asc',
        public array $selectedIds = [],
        public bool $onlySelected = false,
        public int $userId = 0,
    ) {
        $this->filename = $module.'-'.now()->format('Y-m-d-His').'.'.$format->extension();
    }

    /**
     * @return array<int, string>
     */
    public function columnKeys(): array
    {
        return array_keys($this->columns);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_values($this->columns);
    }

    public function filename(): string
    {
        return $this->filename;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'format' => $this->format->value,
            'module' => $this->module,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'search' => $this->search,
            'sort_by' => $this->sortBy,
            'sort_direction' => $this->sortDirection,
            'selected_ids' => $this->selectedIds,
            'only_selected' => $this->onlySelected,
            'user_id' => $this->userId,
            'filename' => $this->filename,
        ];
    }
}
