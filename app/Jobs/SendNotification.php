<?php

namespace App\Jobs;

use App\Domain\Notifications\ChannelManager;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\NotificationThrottle;
use App\Domain\Notifications\TemplateResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Renders and delivers one notification, and records what happened.
 *
 * The job carries the log id and the merge data, not a rendered message: the
 * template may have been edited between queueing and sending, and the newer
 * wording is the one the administrator meant.
 */
class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $logId,
        public readonly array $data = [],
        public readonly ?string $url = null,
    ) {}

    /**
     * Back off between attempts so a provider blip is not hammered.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(
        ChannelManager $channels,
        TemplateResolver $templates,
        NotificationThrottle $throttle,
    ): void {
        $log = NotificationLog::query()->find($this->logId);

        if ($log === null || $log->status() === NotificationStatus::Sent) {
            return;
        }

        $log->forceFill(['attempts' => $log->attempts + 1])->save();

        $event = NotificationEventRegistry::find($log->event);

        if ($event === null) {
            $log->markSkipped('That event is no longer registered.');

            return;
        }

        $driver = $channels->driver($log->channel());

        if (! $driver->isConfigured()) {
            $log->markSkipped($driver->unavailableReason() ?? 'Channel not configured.');

            return;
        }

        $user = $log->user;

        // The ceiling is applied here rather than at dispatch, so a message held
        // through quiet hours is counted against the hour it actually goes out.
        if (! $throttle->attempt($user, $log->recipient, $log->channel())) {
            $log->markSkipped('Hourly notification limit reached for this recipient.');

            return;
        }

        $rendered = $templates->render($log->event, $log->channel(), [
            ...$this->data,
            'recipient' => ['name' => $user === null ? $log->recipient : $user->name],
            'app' => ['name' => config('app.name'), 'url' => config('app.url')],
        ]);

        $message = new NotificationMessage(
            event: $log->event,
            channel: $log->channel(),
            recipientType: $log->recipientType(),
            user: $user,
            address: $log->recipient,
            subject: $rendered['subject'],
            body: $rendered['body'],
            data: $this->data,
            url: $this->url,
        );

        $log->forceFill(['subject' => $rendered['subject']])->save();

        try {
            $driver->send($message);
            $log->markSent();
        } catch (Throwable $exception) {
            $log->markFailed($exception->getMessage());

            // Rethrow so the queue retries; the final failure lands in failed().
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        NotificationLog::query()->find($this->logId)?->markFailed(
            $exception?->getMessage() ?? 'Delivery failed.'
        );
    }
}
