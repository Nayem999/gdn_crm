<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('activities.index') }}" wire:navigate class="hover:text-foreground">Activities</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $this->isEditing() ? 'Edit' : 'Add activity' }}</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <h1 class="text-2xl font-semibold text-foreground">
            {{ $this->isEditing() ? 'Edit activity' : 'Add activity' }}
        </h1>

        @if ($this->isEditing())
            @php($record = $this->activity())
            <div class="flex flex-wrap items-center gap-2">
                @if ($record?->isOpen())
                    <x-button type="button" wire:click="complete" wire:loading.attr="disabled" wire:target="complete">
                        <x-icon name="lucide-check" class="h-4 w-4" />
                        Mark done
                    </x-button>

                    <button
                        type="button"
                        wire:click="cancelActivity"
                        wire:confirm="Call this off? It stays on the record as cancelled."
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
                    >
                        <x-icon name="lucide-ban" class="h-4 w-4" />
                        Call off
                    </button>
                @else
                    <x-button type="button" variant="secondary" wire:click="reopen" wire:loading.attr="disabled" wire:target="reopen">
                        <x-icon name="lucide-rotate-ccw" class="h-4 w-4" />
                        Reopen
                    </x-button>
                @endif
            </div>
        @endif
    </div>

    @if ($this->isEditing() && $this->isOccurrence())
        <x-alert variant="info" class="mb-4">
            This is one appointment out of a repeating series. Changing it here changes
            this one only — edit the series itself to change the rest.
        </x-alert>
    @endif

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">What needs doing</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-select
                    name="type"
                    label="Kind"
                    :options="$this->typeOptions()"
                    :selected="$type"
                    required
                    :error="$errors->first('type')"
                    hint="A call and a meeting take a slot; a task does not."
                    wire:model.live="type"
                />

                <x-select
                    name="priority"
                    label="Priority"
                    :options="$this->priorityOptions()"
                    :selected="$priority"
                    required
                    :error="$errors->first('priority')"
                    wire:model="priority"
                />

                <div class="sm:col-span-2">
                    <x-form.label for="subject" required>Subject</x-form.label>
                    <x-form.input id="subject" wire:model="subject" :invalid="$errors->has('subject')" placeholder="Call Dana about the renewal" />
                    <x-form.error for="subject" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="description">Notes</x-form.label>
                    <textarea
                        id="description"
                        wire:model="description"
                        rows="3"
                        @class([
                            'w-full rounded-lg border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground',
                            'focus:outline-none focus:ring-2',
                            'border-destructive focus:border-destructive focus:ring-destructive/40' => $errors->has('description'),
                            'border-border focus:border-accent focus:ring-accent/40' => ! $errors->has('description'),
                        ])
                        placeholder="Anything the person doing this needs to know."
                    ></textarea>
                    <x-form.error for="description" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">When</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="due_date" required>Due date</x-form.label>
                    <x-form.input id="due_date" type="date" wire:model="due_date" :invalid="$errors->has('due_date')" />
                    <x-form.error for="due_date" />
                </div>

                @if (! $all_day)
                    <div>
                        <x-form.label for="due_time" required>Time</x-form.label>
                        <x-form.input id="due_time" type="time" wire:model="due_time" :invalid="$errors->has('due_time')" />
                        <x-form.error for="due_time" />
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input
                            type="checkbox"
                            wire:model.live="all_day"
                            class="rounded border-border text-accent focus:ring-accent/40"
                        />
                        All day
                    </label>
                    <p class="mt-1 text-xs text-muted-foreground">
                        An all-day activity is not overdue until the day itself has gone.
                    </p>
                </div>

                @if ($this->chosenType()->hasDuration())
                    <div>
                        <x-form.label for="duration_minutes">Duration</x-form.label>
                        <x-form.input
                            id="duration_minutes"
                            type="number"
                            min="1"
                            max="1440"
                            wire:model="duration_minutes"
                            :invalid="$errors->has('duration_minutes')"
                            placeholder="30"
                        />
                        <x-form.error for="duration_minutes" />
                        <p class="mt-1 text-xs text-muted-foreground">Minutes.</p>
                    </div>
                @endif

                @if ($this->chosenType()->hasLocation())
                    <div>
                        <x-form.label for="location">Where</x-form.label>
                        <x-form.input id="location" wire:model="location" :invalid="$errors->has('location')" placeholder="Their office, or a link" />
                        <x-form.error for="location" />
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <x-select
                        name="reminder_minutes_before"
                        label="Remind me"
                        :options="$this->reminderOptions()"
                        :selected="$reminder_minutes_before"
                        placeholder="No reminder"
                        clearable
                        :error="$errors->first('reminder_minutes_before')"
                        hint="Sent once, to whoever owns the activity."
                        wire:model="reminder_minutes_before"
                    />
                </div>
            </div>
        </section>

        @unless ($this->isOccurrence())
            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">Repeating</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Occurrences are created up to three months ahead and roll forward from there.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select
                        name="recurrence_frequency"
                        label="Repeat"
                        :options="$this->frequencyOptions()"
                        :selected="$recurrence_frequency"
                        placeholder="Does not repeat"
                        clearable
                        :error="$errors->first('recurrence_frequency')"
                        wire:model.live="recurrence_frequency"
                    />

                    @if ($this->repeats())
                        <div>
                            <x-form.label for="recurrence_interval" required>Every</x-form.label>
                            <x-form.input
                                id="recurrence_interval"
                                type="number"
                                min="1"
                                max="365"
                                wire:model="recurrence_interval"
                                :invalid="$errors->has('recurrence_interval')"
                            />
                            <x-form.error for="recurrence_interval" />
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ $this->intervalHint() }}
                            </p>
                        </div>

                        <x-select
                            name="recurrence_end"
                            label="Ends"
                            :options="$this->recurrenceEndOptions()"
                            :selected="$recurrence_end"
                            required
                            :error="$errors->first('recurrence_end')"
                            wire:model.live="recurrence_end"
                        />

                        @if ($recurrence_end === 'after')
                            <div>
                                <x-form.label for="recurrence_count" required>How many times</x-form.label>
                                <x-form.input
                                    id="recurrence_count"
                                    type="number"
                                    min="2"
                                    max="365"
                                    wire:model="recurrence_count"
                                    :invalid="$errors->has('recurrence_count')"
                                />
                                <x-form.error for="recurrence_count" />
                                <p class="mt-1 text-xs text-muted-foreground">Counting this first one.</p>
                            </div>
                        @endif

                        @if ($recurrence_end === 'on')
                            <div>
                                <x-form.label for="recurrence_until" required>Until</x-form.label>
                                <x-form.input
                                    id="recurrence_until"
                                    type="date"
                                    wire:model="recurrence_until"
                                    :invalid="$errors->has('recurrence_until')"
                                />
                                <x-form.error for="recurrence_until" />
                            </div>
                        @endif
                    @endif
                </div>
            </section>
        @endunless

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Who and what it is about</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-select
                    name="related_module"
                    label="About a"
                    :options="$this->relatedModuleOptions()"
                    :selected="$related_module"
                    placeholder="Nothing in particular"
                    clearable
                    :error="$errors->first('related_module')"
                    wire:model.live="related_module"
                />

                {{-- Depends on the kind of record: the kit clears and reloads it
                     when the parent changes, so an id from the previous table
                     cannot be left selected. Keyed on the parent so the field
                     behind wire:ignore is rebuilt when it moves. --}}
                @if ($related_module)
                    <div wire:key="related-picker-{{ $related_module }}">
                        <x-select
                            name="related_id"
                            label="Which one"
                            :options="[]"
                            :selected="$related_id"
                            placeholder="Search…"
                            search-method="searchRelated"
                            depends-on="related_module"
                            preload
                            clearable
                            :error="$errors->first('related_id')"
                            wire:model="related_id"
                        />
                    </div>
                @endif

                @if ($this->canAssign())
                    <x-select
                        name="owner_id"
                        label="Owner"
                        :options="$this->ownerOptions()"
                        :selected="$owner_id"
                        placeholder="Choose an owner…"
                        required
                        :error="$errors->first('owner_id')"
                        hint="Whose list it appears on, and whose reminder it is."
                        wire:model="owner_id"
                    />
                @endif
            </div>
        </section>

        {{-- The fields an administrator has added to this module. Renders
             nothing at all until there are some, so a module with none looks
             exactly as it did before custom fields existed. --}}
        <x-custom-fields :form="$this" />

        <div class="flex items-center gap-2">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <x-icon name="lucide-check" class="h-4 w-4" />
                {{ $this->isEditing() ? 'Save activity' : 'Create activity' }}
            </x-button>

            <a
                href="{{ route('activities.index') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
            >
                Cancel
            </a>
        </div>
    </form>
</div>
