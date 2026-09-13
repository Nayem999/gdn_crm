<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('reports.index') }}" wire:navigate class="hover:text-foreground">Reports</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">Scheduled</span>
    </nav>

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <template x-if="tone === 'error'">
            <x-alert variant="error"><span x-text="message"></span></x-alert>
        </template>
        <template x-if="tone !== 'error'">
            <x-alert variant="success"><span x-text="message"></span></x-alert>
        </template>
    </div>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">Scheduled reports</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                Yours alone. A schedule sends <em>your</em> view of the data, so nobody else can edit where it goes.
            </p>
        </div>

        @can('create', App\Domain\Reports\Models\ReportSchedule::class)
            <button type="button" wire:click="create"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                <x-icon name="lucide-plus" />
                New schedule
            </button>
        @endcan
    </div>

    @if ($editing)
        <form wire:submit="save" class="mb-6 space-y-4 rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">
                {{ $editingId === null ? 'A new schedule' : 'Editing this schedule' }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div wire:key="schedule-report">
                    <x-select
                        name="reportId"
                        label="Report"
                        :options="$this->reportOptions()"
                        :selected="$reportId"
                        placeholder="Choose a report…"
                        wire:model="reportId"
                        :error="$errors->first('reportId')"
                    />
                </div>

                <div wire:key="schedule-format">
                    <x-select
                        name="format"
                        label="Sent as"
                        :options="$this->formatOptions()"
                        :selected="$format"
                        placeholder="PDF"
                        wire:model="format"
                    />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div wire:key="schedule-frequency">
                    <x-select
                        name="frequency"
                        label="How often"
                        :options="$this->frequencyOptions()"
                        :selected="$frequency"
                        placeholder="Every day"
                        wire:model.live="frequency"
                    />
                </div>

                @if ($this->chosenFrequency()->usesDayOfWeek())
                    <div wire:key="schedule-dow">
                        <x-select
                            name="dayOfWeek"
                            label="On"
                            :options="$this->dayOfWeekOptions()"
                            :selected="$dayOfWeek"
                            placeholder="Monday"
                            wire:model="dayOfWeek"
                        />
                    </div>
                @endif

                @if ($this->chosenFrequency()->usesDayOfMonth())
                    <div>
                        <label for="dayOfMonth" class="mb-1 block text-sm font-medium text-foreground">Day of the month</label>
                        <input id="dayOfMonth" type="number" min="1" max="31" wire:model="dayOfMonth"
                               class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                        <p class="mt-1 text-xs text-muted-foreground">
                            The 31st goes out on the last day of a shorter month.
                        </p>
                    </div>
                @endif

                <div wire:key="schedule-hour">
                    <x-select
                        name="hour"
                        label="At"
                        :options="$this->hourOptions()"
                        :selected="$hour"
                        placeholder="08:00"
                        wire:model="hour"
                    />
                    @error('hour') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="recipients" class="mb-1 block text-sm font-medium text-foreground">Send to</label>
                <textarea id="recipients" wire:model="recipients" rows="3"
                          placeholder="one address per line"
                          class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                <p class="mt-1 text-xs text-muted-foreground">
                    Up to {{ \App\Domain\Reports\Models\ReportSchedule::MAX_RECIPIENTS }} addresses. They will see your
                    view of the figures, which may be more than their own.
                </p>
                @error('recipients') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-foreground">
                <input type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-border text-accent focus:ring-accent">
                Sending
            </label>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                    Save schedule
                </button>
                <button type="button" wire:click="cancel"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    @if ($this->schedules->isEmpty())
        <div class="rounded-xl border border-dashed border-border p-10 text-center">
            <x-icon name="lucide-mail-check" class="mx-auto h-8 w-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold text-foreground">Nothing scheduled</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                A scheduled report arrives by email on its own, as a PDF or a spreadsheet.
            </p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($this->schedules as $schedule)
                <li class="rounded-xl border border-border bg-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold text-foreground">
                                {{ $schedule->report?->name ?? 'A report that has gone' }}
                                {!! \App\Domain\Shared\UI\ChipPalette::chip($schedule->format()->label(), 'blue') !!}
                                @unless ($schedule->is_active)
                                    {!! \App\Domain\Shared\UI\ChipPalette::chip('Paused', 'slate') !!}
                                @endunless
                                @if ($schedule->last_status === 'failed')
                                    {!! \App\Domain\Shared\UI\ChipPalette::chip('Failing', 'rose') !!}
                                @endif
                            </h2>

                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ $schedule->summary() }}
                                &middot; {{ count($schedule->addresses()) }}
                                {{ \Illuminate\Support\Str::plural('address', count($schedule->addresses())) }}
                            </p>

                            <p class="mt-1 text-xs text-muted-foreground">
                                @if ($schedule->next_run_at)
                                    Next {{ \App\Domain\Settings\DisplayTime::display($schedule->next_run_at)->format('j M Y, H:i') }}
                                @else
                                    Not scheduled
                                @endif
                                @if ($schedule->last_run_at)
                                    &middot; last sent
                                    {{ \App\Domain\Settings\DisplayTime::display($schedule->last_run_at)->diffForHumans() }}
                                @endif
                            </p>

                            @if ($schedule->last_status === 'failed' && $schedule->last_error)
                                {{-- On the screen, not only in the logs: a schedule
                                     failing silently for a fortnight is the whole
                                     failure mode of an unattended sender. --}}
                                <p class="mt-2 text-xs text-destructive">{{ $schedule->last_error }}</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @can('update', $schedule)
                                <button type="button" wire:click="sendNow({{ $schedule->id }})"
                                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:text-foreground">
                                    Send now
                                </button>
                                <button type="button" wire:click="edit({{ $schedule->id }})"
                                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-muted">
                                    Edit
                                </button>
                            @endcan
                            @can('delete', $schedule)
                                <button type="button" wire:click="delete({{ $schedule->id }})"
                                        wire:confirm="Remove this schedule? It will stop sending."
                                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-destructive transition-colors hover:bg-destructive/10">
                                    Remove
                                </button>
                            @endcan
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
