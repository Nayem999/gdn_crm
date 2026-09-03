<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the user their queued export did not finish, without leaking why.
 */
class ExportFailed extends Notification
{
    use Queueable;

    public function __construct(public readonly string $module) {}

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
            'type' => 'export_failed',
            'module' => $this->module,
            'message' => "Your {$this->module} export could not be generated. Please try again.",
        ];
    }
}
