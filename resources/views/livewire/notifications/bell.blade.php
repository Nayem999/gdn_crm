<div class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false">
    <button
        type="button"
        x-on:click="open = ! open"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="true"
        class="relative flex h-10 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
        aria-label="Notifications{{ $unread > 0 ? ' ('.$unread.' unread)' : '' }}"
    >
        <x-icon name="lucide-bell" class="h-5 w-5" />

        @if ($unread > 0)
            <span class="absolute right-1.5 top-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-none text-destructive-foreground">
                {{ $unread > 9 ? '9+' : $unread }}
            </span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-transition.origin.top.right
        class="absolute right-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-border bg-card shadow-lg"
        role="dialog"
        aria-label="Notifications"
    >
        <div class="flex items-center justify-between border-b border-border px-4 py-2.5">
            <span class="text-sm font-semibold text-foreground">Notifications</span>

            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-accent hover:underline">
                    Mark all read
                </button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            @forelse ($notifications as $notification)
                @php
                    $isUnread = $notification->read_at === null;
                    $url = $notification->data['url'] ?? null;
                @endphp

                <div
                    @class([
                        'flex items-start gap-3 border-b border-border px-4 py-3 last:border-b-0',
                        'bg-accent/5' => $isUnread,
                    ])
                    wire:key="notification-{{ $notification->id }}"
                >
                    <span @class([
                        'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                        'bg-accent' => $isUnread,
                        'bg-transparent' => ! $isUnread,
                    ]) aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        @if ($notification->data['subject'] ?? null)
                            <p class="text-sm font-medium text-foreground">{{ $notification->data['subject'] }}</p>
                        @endif

                        <p class="mt-0.5 text-sm text-muted-foreground">{{ $notification->data['message'] ?? '' }}</p>

                        <div class="mt-1.5 flex items-center gap-3">
                            <span class="text-xs text-muted-foreground">{{ $notification->created_at->diffForHumans() }}</span>

                            @if ($url)
                                <a href="{{ $url }}" wire:navigate class="text-xs font-medium text-accent hover:underline">
                                    Open
                                </a>
                            @endif

                            @if ($isUnread)
                                <button
                                    type="button"
                                    wire:click="markRead('{{ $notification->id }}')"
                                    class="text-xs font-medium text-muted-foreground hover:text-foreground"
                                >
                                    Mark read
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-4 py-10 text-center">
                    <x-icon name="lucide-bell-off" class="mx-auto h-6 w-6 text-muted-foreground" />
                    <p class="mt-2 text-sm text-muted-foreground">Nothing yet.</p>
                </div>
            @endforelse
        </div>

        <div class="border-t border-border px-4 py-2.5">
            <a href="{{ route('profile') }}#notifications" wire:navigate class="text-xs font-medium text-accent hover:underline">
                Notification preferences
            </a>
        </div>
    </div>
</div>
