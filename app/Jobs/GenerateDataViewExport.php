<?php

namespace App\Jobs;

use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Domain\Shared\Exports\DataViewExport;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Models\User;
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

            $this->announceFailure($user);

            return;
        }

        $path = 'exports/'.$user->id.'/'.$this->request->filename();

        Excel::store(
            new DataViewExport($this->request, $source),
            $path,
            'local',
            $this->request->format->writerType()
        );

        app(Notifier::class)->send('export.ready', [
            Recipient::user($user, RecipientType::AssignedAgent),
        ], [
            'export' => [
                'module' => $this->request->module,
                'rows' => $source->exportQuery($this->request)->toBase()->getCountForPagination(),
                'filename' => $this->request->filename(),
                'path' => $path,
            ],
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $user = User::query()->find($this->request->userId);

        if ($user !== null) {
            $this->announceFailure($user);
        }
    }

    private function announceFailure(User $user): void
    {
        app(Notifier::class)->send('export.failed', [
            Recipient::user($user, RecipientType::AssignedAgent),
        ], ['export' => ['module' => $this->request->module]]);
    }
}
