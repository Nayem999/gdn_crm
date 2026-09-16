<div>
    <x-settings-shell
        heading="Meta conversions"
        description="What this CRM has told Meta about the leads it sent, and what Meta said back."
        active="settings.meta.conversions"
    >
        @if ($error)
            <div class="mb-6"><x-alert variant="error">{{ $error }}</x-alert></div>
        @endif

        @if ($notice)
            <div class="mb-6"><x-alert variant="success">{{ $notice }}</x-alert></div>
        @endif

        @if ($this->datasetId() === null)
            <div class="mb-6">
                <x-alert variant="info">
                    No dataset (pixel) ID is set, so nothing is reported back to Meta. Add one under
                    <a href="{{ route('settings.group', 'meta') }}" wire:navigate class="font-medium underline">Settings &rarr; Meta</a>
                    to start sending outcomes.
                </x-alert>
            </div>
        @endif

        <div class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-foreground">Reported outcomes</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Meta answers a rejected event with a 200 and a message inside it, so &ldquo;sent&rdquo; here
                        means Meta said it received the event.
                    </p>
                </div>

                <div class="w-full sm:w-64">
                    <x-select
                        name="conversion-status"
                        :options="$this->statusOptions()"
                        :selected="$status"
                        placeholder="Every event"
                        wire:model.live="status"
                    />
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach (App\Domain\Meta\Conversions\Enums\ConversionStatus::cases() as $case)
                    <x-status-chip
                        :label="$case->label().' — '.($counts[$case->value] ?? 0)"
                        :color="$case->color()"
                    />
                @endforeach
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <th class="py-2 pr-4 font-medium">Outcome</th>
                            <th class="py-2 pr-4 font-medium">Record</th>
                            <th class="py-2 pr-4 font-medium">Value</th>
                            <th class="py-2 pr-4 font-medium">When</th>
                            <th class="py-2 pr-4 font-medium">State</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border">
                        @forelse ($events as $event)
                            <tr>
                                <td class="py-3 pr-4">
                                    <span class="font-medium text-foreground">{{ $event->outcome()?->label() ?? $event->event_name }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $event->event_name }}</span>
                                </td>

                                <td class="py-3 pr-4 text-muted-foreground">
                                    {{ class_basename((string) $event->subject_type) }}
                                    @if ($event->subject_id)
                                        #{{ $event->subject_id }}
                                    @endif
                                </td>

                                <td class="py-3 pr-4 text-muted-foreground">
                                    @if ($event->value !== null)
                                        {{ $event->currency }} {{ number_format((float) $event->value, 2) }}
                                    @else
                                        &mdash;
                                    @endif
                                </td>

                                <td class="py-3 pr-4 text-muted-foreground">
                                    {{ \App\Domain\Settings\DisplayTime::dateTime($event->occurred_at) }}
                                </td>

                                <td class="py-3 pr-4">
                                    <x-status-chip :label="$event->status()->label()" :color="$event->status()->color()" />

                                    @if ($event->error)
                                        <span class="mt-1 block max-w-xs text-xs text-muted-foreground">{{ $event->error }}</span>
                                    @endif
                                </td>

                                <td class="py-3 text-right">
                                    @if ($event->status()->isRetryable())
                                        <x-button type="button" variant="secondary" wire:click="retry({{ $event->id }})">
                                            Try again
                                        </x-button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-sm text-muted-foreground">
                                    Nothing has been reported yet. Outcomes are sent when a lead from Meta is
                                    qualified, becomes a deal, or is won.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $events->links() }}</div>
        </div>
    </x-settings-shell>
</div>
