<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Actions\RunDataViewExport;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Adds CSV/Excel/PDF export to a list screen already using WithDataView. The
 * export always mirrors what the user can see: their filters, their sort and
 * their visible columns, in their order.
 */
trait ExportsDataView
{
    public bool $exportSelectedOnly = false;

    /**
     * A screen opts into exporting by returning its own source. Returning null
     * leaves the export menu hidden.
     */
    public function dataViewExportSource(): ?DataViewExportSource
    {
        return null;
    }

    public function canExport(): bool
    {
        return $this->dataViewExportSource() !== null;
    }

    public function export(string $format): ?BinaryFileResponse
    {
        $source = $this->dataViewExportSource();
        $resolved = ExportFormat::tryFrom($format);

        if ($source === null || $resolved === null) {
            return null;
        }

        $result = app(RunDataViewExport::class)->handle(
            $this->buildExportRequest($resolved, $source),
            $source
        );

        if ($result['queued']) {
            // Too big to build inside a request; the user gets a notification.
            $this->dispatch('notify',
                type: 'info',
                message: 'Your export of '.number_format($result['rows']).' rows is being prepared. '
                    .'We will notify you when it is ready to download.',
            );

            return null;
        }

        if ($result['rows'] === 0) {
            $this->dispatch('notify', type: 'error', message: 'There is nothing to export.');

            return null;
        }

        return ($result['download'])();
    }

    public function exportRequestForTesting(ExportFormat $format): ?ExportRequest
    {
        $source = $this->dataViewExportSource();

        return $source === null ? null : $this->buildExportRequest($format, $source);
    }

    private function buildExportRequest(ExportFormat $format, DataViewExportSource $source): ExportRequest
    {
        /** @var array<string, string> $columns */
        $columns = collect($this->pinnedThenLooseColumns())
            ->mapWithKeys(fn (Column $column) => [$column->key => $column->label])
            ->all();

        $onlySelected = $this->exportSelectedOnly && $this->selected !== [] && ! $this->selectAllMatching;

        return new ExportRequest(
            source: $source::class,
            format: $format,
            module: $this->dataViewModule(),
            columns: $columns,
            filters: $this->filters,
            search: $this->search,
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            selectedIds: $onlySelected ? array_map('intval', $this->selected) : [],
            onlySelected: $onlySelected,
            userId: (int) auth()->id(),
        );
    }
}
