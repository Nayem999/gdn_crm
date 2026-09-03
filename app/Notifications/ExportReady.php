<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the user their queued export finished and where to fetch it.
 */
class ExportReady extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $module,
        public readonly string $path,
        public readonly string $filename,
        public readonly int $rows,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'export_ready',
            'module' => $this->module,
            'path' => $this->path,
            'filename' => $this->filename,
            'rows' => $this->rows,
            'message' => "Your {$this->module} export is ready ({$this->rows} rows).",
        ];
    }
}
