<?php

namespace App\Livewire\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Jobs\SendNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What the engine tried to deliver, and what happened.
 */
#[Title('Notification log')]
class NotificationLogIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $channel = '';

    #[Url(except: '')]
    public string $event = '';

    public int $perPage = 25;

    public function mount(): void
    {
        $this->authorize('viewAny', NotificationLog::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'channel', 'event', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'channel', 'event']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->channel !== '' || $this->event !== '';
    }

    /**
     * Put a failed delivery back on the queue.
     */
    public function retry(int $logId): void
    {
        $log = NotificationLog::query()->find($logId);

        if ($log === null) {
            return;
        }

        // The policy refuses anything that is not actually failed, so a tampered
        // id cannot re-send something already delivered.
        $this->authorize('retry', $log);

        $log->forceFill(['status' => NotificationStatus::Queued->value, 'error' => null])->save();

        SendNotification::dispatch($log->id);

        $this->dispatch('notification-retried');
    }

    public function retryAllFailed(): void
    {
        $this->authorize('update', NotificationLog::class);

        NotificationLog::query()->retryable()->each(function (NotificationLog $log) {
            $log->forceFill(['status' => NotificationStatus::Queued->value, 'error' => null])->save();
            SendNotification::dispatch($log->id);
        });

        $this->dispatch('notification-retried');
    }

    public function failedCount(): int
    {
        return NotificationLog::query()->retryable()->count();
    }

    /**
     * @return LengthAwarePaginator<int, NotificationLog>
     */
    public function logs(): LengthAwarePaginator
    {
        return NotificationLog::query()
            ->with('user')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->channel !== '', fn ($query) => $query->where('channel', $this->channel))
            ->when($this->event !== '', fn ($query) => $query->where('event', $this->event))
            ->when($this->search !== '', function ($query) {
                $query->where(function ($inner) {
                    $inner->where('recipient', 'like', '%'.$this->search.'%')
                        ->orWhere('subject', 'like', '%'.$this->search.'%')
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', '%'.$this->search.'%')
                            ->orWhere('email', 'like', '%'.$this->search.'%'));
                });
            })
            // Same-second rows would otherwise come back in arbitrary order and
            // make paging unstable.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    /**
     * @return array<string, string>
     */
    public function eventOptions(): array
    {
        $options = [];

        foreach (NotificationEventRegistry::events() as $key => $event) {
            $options[$key] = $event->label;
        }

        return $options;
    }

    public function render(): View
    {
        return view('livewire.notifications.log', [
            'logs' => $this->logs(),
            'statuses' => NotificationStatus::options(),
            'channels' => NotificationChannel::options(),
        ]);
    }
}
