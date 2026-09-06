<?php

namespace App\Jobs;

use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Domain\Shared\Actions\RunImportAction;
use App\Domain\Shared\Imports\ImportStatus;
use App\Domain\Shared\Models\ImportRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a large import off the request cycle and tells the person when it is done.
 *
 * One attempt: a half-finished import that retries would import the rows that
 * already landed a second time. Anything that goes wrong is recorded on the run
 * for the person who uploaded the file to read.
 */
class RunImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(RunImportAction $import, Notifier $notifier): void
    {
        $run = ImportRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $run = $import($run);

        if ($run->user === null) {
            return;
        }

        $notifier->send('import.finished', [
            Recipient::user($run->user, RecipientType::AssignedAgent),
        ], [
            'import' => [
                'module' => $run->source()?->label() ?? $run->module,
                'filename' => $run->original_filename,
                'imported' => $run->imported_rows,
                'failed' => $run->failed_rows,
                'status' => $run->status()->label(),
            ],
        ]);
    }

    /**
     * The queue gave up: say so on the run rather than leaving it "Importing"
     * for ever.
     */
    public function failed(Throwable $exception): void
    {
        ImportRun::query()->whereKey($this->runId)->update([
            'status' => ImportStatus::Failed->value,
            'failure_reason' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
