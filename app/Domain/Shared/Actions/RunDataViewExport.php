<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Exports\DataViewExport;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Jobs\GenerateDataViewExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Small exports download immediately; anything large enough to time out a
 * request is queued and the user is notified when it lands.
 */
class RunDataViewExport
{
    /**
     * Row count above which an export goes to the queue instead.
     */
    public const QUEUE_THRESHOLD = 1000;

    /**
     * @return array{queued: bool, rows: int, download: ?callable(): BinaryFileResponse}
     */
    public function handle(ExportRequest $request, DataViewExportSource $source): array
    {
        $rows = $source->exportQuery($request)->toBase()->getCountForPagination();

        if ($rows > self::QUEUE_THRESHOLD) {
            GenerateDataViewExport::dispatch($request);

            return ['queued' => true, 'rows' => $rows, 'download' => null];
        }

        return [
            'queued' => false,
            'rows' => $rows,
            'download' => fn (): BinaryFileResponse => Excel::download(
                new DataViewExport($request, $source),
                $request->filename(),
                $request->format->writerType()
            ),
        ];
    }
}
