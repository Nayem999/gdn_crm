<?php

namespace App\Jobs;

use App\Domain\Shared\Exports\DataViewExport;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Models\User;
use App\Notifications\ExportFailed;
use App\Notifications\ExportReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Builds a large export off the request cycle and notifies the user when the
 * file is ready.
 */
class GenerateDataViewExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(private readonly ExportRequest $request) {}

    public function handle(): void
    {
        $user = User::query()->find($this->request->userId);

        if ($user === null) {
            return;
        }

        $source = app($this->request->source);

        if (! $source instanceof DataViewExportSource) {
            Log::warning('Export source is not a data view source.', [
                'source' => $this->request->source,
                'user_id' => $user->id,
            ]);

            $user->notify(new ExportFailed($this->request->module));

            return;
        }

        $path = 'exports/'.$user->id.'/'.$this->request->filename();

        Excel::store(
            new DataViewExport($this->request, $source),
            $path,
            'local',
            $this->request->format->writerType()
        );

        $user->notify(new ExportReady(
            module: $this->request->module,
            path: $path,
            filename: $this->request->filename(),
            rows: $source->exportQuery($this->request)->toBase()->getCountForPagination(),
        ));
    }

    public function failed(?\Throwable $exception): void
    {
        $user = User::query()->find($this->request->userId);

        $user?->notify(new ExportFailed($this->request->module));
    }
}
