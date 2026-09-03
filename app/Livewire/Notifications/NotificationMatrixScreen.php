<?php

namespace App\Livewire\Notifications;

use App\Domain\Notifications\ChannelManager;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\NotificationMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The event x recipient type x channel switchboard.
 */
#[Title('Notification matrix')]
class NotificationMatrixScreen extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', NotificationLog::class);
    }

    public function toggle(string $eventKey, string $recipientType, string $channel): void
    {
        $this->authorize('update', NotificationLog::class);

        $type = RecipientType::tryFrom($recipientType);
        $resolved = NotificationChannel::tryFrom($channel);

        // The registry decides what exists; a tampered payload cannot invent a
        // cell for an event or a recipient type that was never declared.
        if ($type === null || $resolved === null) {
            return;
        }

        app(NotificationMatrix::class)->toggle($eventKey, $type, $resolved);
    }

    public function isEnabled(string $eventKey, RecipientType $type, NotificationChannel $channel): bool
    {
        return app(NotificationMatrix::class)->isEnabled($eventKey, $type, $channel);
    }

    public function isAvailable(NotificationChannel $channel): bool
    {
        return app(ChannelManager::class)->driver($channel)->isConfigured();
    }

    public function unavailableReason(NotificationChannel $channel): ?string
    {
        return app(ChannelManager::class)->driver($channel)->unavailableReason();
    }

    public function canUpdate(): bool
    {
        return auth()->user()?->can('notifications.update') ?? false;
    }

    public function render(): View
    {
        return view('livewire.notifications.matrix', [
            'grouped' => NotificationEventRegistry::grouped(),
            'channels' => NotificationChannel::cases(),
        ]);
    }
}
