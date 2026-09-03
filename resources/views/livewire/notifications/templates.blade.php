<div>
    <x-settings-shell
        heading="Notification templates"
        description="The wording for each event on each channel. Drop a merge field in with double braces."
        active="settings.notifications"
    >
        <div
            x-data="{ show: false }"
            x-on:template-saved.window="show = true; setTimeout(() => show = false, 3000)"
            x-show="show"
            x-cloak
            x-transition
            class="mb-4"
        >
            <x-alert variant="success">Template saved.</x-alert>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="rounded-xl border border-border bg-card p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-form.label for="template-event">Event</x-form.label>
                            <select
                                id="template-event"
                                wire:model.live="eventKey"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent/40"
                            >
                                @foreach ($events as $key => $event)
                                    <option value="{{ $key }}">{{ $event->group }} &mdash; {{ $event->label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <x-form.label for="template-channel">Channel</x-form.label>
                            <select
                                id="template-channel"
                                wire:model.live="channel"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent/40"
                            >
                                @foreach (\App\Domain\Notifications\Enums\NotificationChannel::cases() as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <form wire:submit="save" class="mt-5 space-y-4">
                        @if ($this->currentChannel()->hasSubject())
                            <div>
                                <x-form.label for="template-subject" required>Subject</x-form.label>
                                <x-form.input id="template-subject" wire:model="subject" :invalid="$errors->has('subject')" />
                                <x-form.error for="subject" />
                            </div>
                        @endif

                        <div>
                            <x-form.label for="template-body" required>Message</x-form.label>
                            <textarea
                                id="template-body"
                                rows="5"
                                wire:model.live.debounce.500ms="body"
                                @class([
                                    'w-full rounded-lg border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2',
                                    'border-destructive focus:ring-destructive/40' => $errors->has('body'),
                                    'border-border focus:border-accent focus:ring-accent/40' => ! $errors->has('body'),
                                ])
                            ></textarea>
                            <x-form.error for="body" />
                        </div>

                        <div class="rounded-lg border border-border bg-muted/40 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Preview</p>
                            <p class="mt-1.5 whitespace-pre-line text-sm text-foreground">{{ $this->preview() }}</p>
                        </div>

                        @if ($this->canUpdate())
                            <div class="flex flex-wrap items-center gap-3">
                                <x-button type="submit">Save template</x-button>

                                @if ($this->isCustomised())
                                    <button
                                        type="button"
                                        class="text-sm font-medium text-muted-foreground hover:text-destructive"
                                        wire:click="resetToDefault"
                                        wire:confirm="Put the original wording back?"
                                    >
                                        Reset to the default wording
                                    </button>
                                @else
                                    <span class="text-xs text-muted-foreground">Using the default wording.</span>
                                @endif
                            </div>
                        @else
                            <p class="text-sm text-muted-foreground">You have read-only access to templates.</p>
                        @endif
                    </form>
                </div>
            </div>

            <aside class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Merge fields</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Anything else in double braces is left exactly as typed.
                </p>

                <dl class="mt-3 space-y-2.5">
                    @foreach ($this->mergeFields() as $field => $meaning)
                        <div>
                            <dt>
                                <code class="rounded bg-muted px-1.5 py-0.5 text-xs text-foreground">&#123;&#123;{{ $field }}&#125;&#125;</code>
                            </dt>
                            <dd class="mt-0.5 text-xs text-muted-foreground">{{ $meaning }}</dd>
                        </div>
                    @endforeach
                </dl>
            </aside>
        </div>

        <x-slot:actions>
            <a href="{{ route('settings.notifications') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                <x-icon name="lucide-table-2" />
                Matrix
            </a>
        </x-slot:actions>
    </x-settings-shell>
</div>
