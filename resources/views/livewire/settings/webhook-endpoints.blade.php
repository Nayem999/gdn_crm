<div>
    <x-settings-shell
        heading="Webhooks"
        description="Where the application tells another system what happened. Every request is signed, and a failed delivery is retried for about an hour."
        active="settings.webhooks"
    >
        <div
            x-data="{ show: false }"
            x-on:webhook-saved.window="show = true; setTimeout(() => show = false, 3000)"
            x-show="show"
            x-cloak
            x-transition
            class="mb-4"
        >
            <x-alert variant="success">Endpoint saved.</x-alert>
        </div>

        @if ($revealedSecret !== null)
            <div class="mb-6 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4">
                <h2 class="text-sm font-semibold text-foreground">Signing secret — copy it now</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Give this to whoever receives the webhook; they verify the
                    <code>{{ \App\Domain\Webhooks\WebhookSignature::HEADER }}</code> header with it.
                    It is stored encrypted and cannot be shown again.
                </p>
                <code class="mt-2 block overflow-x-auto rounded-lg bg-background px-3 py-2 font-mono text-xs text-foreground">{{ $revealedSecret }}</code>
            </div>
        @endif

        @if ($editing)
            <form wire:submit="save" class="mb-6 space-y-5 rounded-xl border border-border bg-card p-5 sm:p-6">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="hook-name" required>Name</x-form.label>
                        <x-form.input id="hook-name" wire:model="name" placeholder="Order system" />
                        <x-form.error for="name" />
                    </div>

                    <div>
                        <x-form.label for="hook-url" required>URL</x-form.label>
                        <x-form.input id="hook-url" wire:model="url" placeholder="https://example.com/hooks/crm" />
                        <x-form.error for="url" />
                    </div>
                </div>

                <div>
                    <x-form.label for="hook-events" required>Send on</x-form.label>
                    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        @foreach ($this->eventOptions() as $event => $label)
                            <label class="flex items-center gap-2 text-sm text-foreground">
                                <input type="checkbox" value="{{ $event }}" wire:model="events" class="rounded border-border text-accent focus:ring-accent/40">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <x-form.error for="events" />
                </div>

                <label class="flex items-center gap-2 text-sm text-foreground">
                    <input type="checkbox" wire:model="isActive" class="rounded border-border text-accent focus:ring-accent/40">
                    Active
                </label>

                <div class="flex items-center gap-3">
                    <x-button type="submit">{{ $editingId === null ? 'Create endpoint' : 'Save changes' }}</x-button>
                    <button type="button" wire:click="cancel" class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Cancel
                    </button>
                </div>
            </form>
        @elseif ($this->canManage())
            <div class="mb-4">
                <x-button type="button" wire:click="add">New endpoint</x-button>
            </div>
        @endif

        @if ($endpoints->isEmpty())
            <div class="rounded-xl border border-border bg-card">
                <x-empty-state
                    icon="webhook"
                    heading="No endpoints yet"
                    description="An endpoint is how another system hears about a change without polling for it."
                />
            </div>
        @else
            <div class="space-y-4">
                @foreach ($endpoints as $endpoint)
                    <div class="rounded-xl border border-border bg-card p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-sm font-semibold text-foreground">
                                    {{ $endpoint->name }}
                                    @unless ($endpoint->is_active)
                                        <span class="ml-2 text-xs font-normal text-muted-foreground">(paused)</span>
                                    @endunless
                                </h2>
                                <p class="mt-0.5 break-all text-xs text-muted-foreground">{{ $endpoint->url }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ implode(', ', $endpoint->events ?? []) }}</p>
                            </div>

                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-3 text-sm">
                                    <button type="button" wire:click="edit({{ $endpoint->id }})" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">Edit</button>
                                    <button
                                        type="button"
                                        wire:click="regenerate({{ $endpoint->id }})"
                                        wire:confirm="Every delivery signed with the old secret stops verifying immediately. Continue?"
                                        class="text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                    >New secret</button>
                                    <button
                                        type="button"
                                        wire:click="delete({{ $endpoint->id }})"
                                        wire:confirm="Delete this endpoint? Nothing further will be sent to it."
                                        class="text-destructive underline underline-offset-4"
                                    >Delete</button>
                                </div>
                            @endif
                        </div>

                        @if ($endpoint->deliveries->isNotEmpty())
                            <ul class="mt-4 space-y-1 border-t border-border pt-3">
                                @foreach ($endpoint->deliveries as $delivery)
                                    <li class="flex flex-wrap items-center gap-2 text-xs">
                                        <x-status-chip :color="$delivery->status->color()">{{ $delivery->status->label() }}</x-status-chip>
                                        <span class="font-medium text-foreground">{{ $delivery->event }}</span>
                                        <span class="text-muted-foreground">{{ $delivery->attempts }} attempt(s)</span>
                                        @if ($delivery->response_status)
                                            <span class="text-muted-foreground">HTTP {{ $delivery->response_status }}</span>
                                        @endif
                                        @if ($delivery->error)
                                            <span class="text-destructive">{{ $delivery->error }}</span>
                                        @endif
                                        @if ($this->canManage() && $delivery->status !== \App\Domain\Webhooks\Enums\WebhookDeliveryStatus::Delivered)
                                            <button type="button" wire:click="retry({{ $delivery->id }})" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">Send again</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-settings-shell>
</div>
