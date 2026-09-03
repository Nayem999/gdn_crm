<?php

namespace App\Livewire\Notifications;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The topbar bell.
 *
 * Everyone sees their own notifications, so there is no permission here — but
 * every read and write is scoped to the signed-in user's own rows, never an id
 * the browser supplies on its own.
 */
class NotificationBell extends Component
{
    public bool $open = false;

    /**
     * How many entries the dropdown shows before pointing at the full list.
     */
    public const VISIBLE = 8;

    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function latest(): Collection
    {
        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        return $user->notifications()
            ->latest()
            ->limit(self::VISIBLE)
            ->get();
    }

    public function unreadCount(): int
    {
        return auth()->user()?->unreadNotifications()->count() ?? 0;
    }

    public function markRead(string $id): void
    {
        // Scoped to this user's own notifications: another person's id simply
        // does not resolve.
        auth()->user()?->notifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllRead(): void
    {
        auth()->user()?->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        return view('livewire.notifications.bell', [
            'notifications' => $this->latest(),
            'unread' => $this->unreadCount(),
        ]);
    }
}
