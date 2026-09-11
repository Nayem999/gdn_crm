@php
    use App\Domain\Workflows\Enums\WorkflowActionType;
    use App\Domain\Shared\Filters\FilterGroup;

    $trigger = $this->trigger();
    $matchOptions = [
        FilterGroup::MATCH_ALL => 'all of these',
        FilterGroup::MATCH_ANY => 'any of these',
    ];
@endphp

<div class="mx-auto max-w-4xl space-y-6 pb-16">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-foreground">
                {{ $workflowId ? 'Edit workflow' : 'New workflow' }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                What fires it, what it checks, and what it does.
            </p>
        </div>

        <a
            href="{{ route('workflows.index') }}"
            wire:navigate
            class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
            <x-icon name="lucide-arrow-left" />
            All workflows
        </a>
    </div>

    @if ($saved)
        <x-alert variant="success">{{ $saved }}</x-alert>
    @endif

    <form wire:submit="save" class="space-y-6">
        {{-- 1. What it is ------------------------------------------------- --}}
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What it is</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="name" required>Name</x-form.label>
                    <x-form.input wire:model="name" placeholder="Chase high-value leads" />
                    <x-form.error for="name" />
                </div>

                <div>
                    <x-form.label for="module" required>Module</x-form.label>
                    @if ($workflowId)
                        {{-- Fixed after create: every condition and every step
                             names a field of this module, so changing it would
                             invalidate the whole definition. --}}
                        <p class="flex h-10 items-center rounded-lg border border-border bg-muted px-3 text-sm text-muted-foreground">
                            {{ App\Domain\Workflows\WorkflowModules::label($module) }}
                        </p>
                    @else
                        <div wire:key="module-{{ $module }}">
                            <x-select
                                name="module"
                                :options="$this->moduleOptions()"
                                :selected="$module"
                                wire:model.live="module"
                            />
                        </div>
                    @endif
                    <x-form.error for="module" />
                </div>
            </div>

            <div class="mt-4">
                <div>
                    <x-form.label for="description">Description</x-form.label>
                    <textarea rows="2" wire:model="description" placeholder="What this is for, so the next person knows." class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"></textarea>
                    <x-form.error for="description" />
                </div>
            </div>
        </section>

        {{-- 2. When it fires ----------------------------------------------- --}}
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">When it fires</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="triggerEvent" required>Trigger</x-form.label>
                    <div wire:key="trigger-{{ $triggerEvent }}">
                        <x-select
                            name="triggerEvent"
                            :options="$this->triggerOptions()"
                            :selected="$triggerEvent"
                            wire:model.live="triggerEvent"
                        />
                    </div>
                    <x-form.error for="triggerEvent" />
                </div>

                @if ($trigger->needsField())
                    <div>
                        <x-form.label for="triggerField" required>
                            {{ $trigger->needsDateField() ? 'Date to count from' : 'Field to watch' }}
                        </x-form.label>
                        <div wire:key="trigger-field-{{ $triggerEvent }}-{{ $triggerField }}">
                            <x-select
                                name="triggerField"
                                :options="$this->triggerFieldOptions()"
                                :selected="$triggerField"
                                placeholder="Choose a field"
                                wire:model.live="triggerField"
                            />
                        </div>
                        <x-form.error for="triggerField" />
                    </div>
                @endif
            </div>

            @if ($trigger->needsDateField())
                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <div class="w-32">
                        <x-form.label for="dateOffset">Minutes</x-form.label>
                        <x-form.input type="number" min="0" wire:model="dateOffset" />
                        <x-form.error for="dateOffset" />
                    </div>

                    <div class="w-40" wire:key="offset-direction-{{ $dateOffsetDirection }}">
                        <div>
                            <x-form.label for="dateOffsetDirection">Before or after</x-form.label>
                            <x-select
                                name="dateOffsetDirection"
                                :options="['after' => 'after the date', 'before' => 'before the date']"
                                :selected="$dateOffsetDirection"
                                wire:model.live="dateOffsetDirection"
                            />
                            <x-form.error for="dateOffsetDirection" />
                        </div>
                    </div>

                    <p class="pb-2 text-xs text-muted-foreground">
                        Checked every minute. A record whose moment passed before this workflow was written is never chased.
                    </p>
                </div>
            @endif

            @if ($trigger->needsSchedule())
                <div class="mt-4">
                    <div>
                        <x-form.label for="scheduleExpression" required>Schedule</x-form.label>
                        <x-form.input wire:model="scheduleExpression" placeholder="0 9 * * 1-5" />
                        <x-form.error for="scheduleExpression" />
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        A cron expression, read in this office's timezone. <code>0 9 * * 1-5</code> is weekdays at nine.
                    </p>
                </div>
            @endif

            <label class="mt-4 flex items-start gap-2 text-sm text-foreground">
                <input type="checkbox" wire:model="runOncePerRecord" class="mt-0.5 rounded border-border text-accent focus:ring-accent/40" />
                <span>
                    Only once per record
                    <span class="block text-xs text-muted-foreground">
                        Useful for a welcome or a hand-off that should happen the first time and never again.
                    </span>
                </span>
            </label>
        </section>

        {{-- 3. What it checks ---------------------------------------------- --}}
        <section class="rounded-xl border border-border bg-card p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-foreground">What it checks</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        The same conditions the list filters use, and they mean the same thing here. No conditions means every record.
                    </p>
                </div>

                <div class="w-40" wire:key="match-{{ $filters['match'] ?? FilterGroup::MATCH_ALL }}">
                    <x-select
                        name="filters.match"
                        :options="$matchOptions"
                        :selected="$filters['match'] ?? FilterGroup::MATCH_ALL"
                        aria-label="Match all or any condition"
                        wire:model.live="filters.match"
                    />
                </div>
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($filters['conditions'] ?? [] as $index => $condition)
                    <div wire:key="condition-{{ $index }}">
                        <x-filter-builder.condition
                            :condition="$condition"
                            :index="$index"
                            :siblings="count($filters['conditions'] ?? [])"
                            :fields="$this->conditionFields()"
                        />
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground">No conditions — this runs for every record.</p>
                @endforelse
            </div>

            @foreach ($filters['groups'] ?? [] as $groupIndex => $group)
                <div class="mt-3 rounded-lg border border-border bg-muted/40 p-3" wire:key="group-{{ $groupIndex }}">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Group &mdash; match</span>

                        <div class="w-40" wire:key="group-{{ $groupIndex }}-match-{{ $group['match'] ?? FilterGroup::MATCH_ANY }}">
                            <x-select
                                name="filters.groups.{{ $groupIndex }}.match"
                                :options="$matchOptions"
                                :selected="$group['match'] ?? FilterGroup::MATCH_ANY"
                                aria-label="Match all or any condition in this group"
                                wire:model.live="filters.groups.{{ $groupIndex }}.match"
                            />
                        </div>

                        <button
                            type="button"
                            class="ml-auto rounded-lg p-1.5 text-muted-foreground hover:bg-card hover:text-destructive"
                            wire:click="removeFilterGroup({{ $groupIndex }})"
                            aria-label="Remove this group"
                        >
                            <x-icon name="lucide-trash-2" />
                        </button>
                    </div>

                    <div class="mt-2 space-y-2">
                        @foreach ($group['conditions'] ?? [] as $index => $condition)
                            <div wire:key="group-{{ $groupIndex }}-condition-{{ $index }}">
                                <x-filter-builder.condition
                                    :condition="$condition"
                                    :index="$index"
                                    :siblings="count($group['conditions'] ?? [])"
                                    :group-index="$groupIndex"
                                    :fields="$this->conditionFields()"
                                />
                            </div>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline"
                        wire:click="addCondition({{ $groupIndex }})"
                    >
                        <x-icon name="lucide-plus" class="h-3.5 w-3.5" />
                        Add condition
                    </button>
                </div>
            @endforeach

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-border pt-3">
                <button type="button" class="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline" wire:click="addCondition">
                    <x-icon name="lucide-plus" />
                    Add condition
                </button>

                <button type="button" class="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline" wire:click="addFilterGroup">
                    <x-icon name="lucide-group" />
                    Add group
                </button>
            </div>
        </section>

        {{-- 4. What it does ------------------------------------------------- --}}
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What it does</h2>
            <p class="mt-1 text-xs text-muted-foreground">Steps run in this order.</p>

            @error('steps')
                <p class="mt-2 text-sm text-destructive">{{ $message }}</p>
            @enderror

            <ol class="mt-4 space-y-3">
                @foreach ($steps as $index => $step)
                    <li class="rounded-lg border border-border p-4" wire:key="step-{{ $index }}-{{ $step['type'] }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                                {{ $index + 1 }}
                            </span>

                            <div class="w-56" wire:key="step-type-{{ $index }}-{{ $step['type'] }}">
                                <x-select
                                    name="steps.{{ $index }}.type"
                                    :options="$this->actionTypeOptions()"
                                    :selected="$step['type']"
                                    aria-label="What this step does"
                                    wire:model.live="steps.{{ $index }}.type"
                                />
                            </div>

                            <div class="ml-auto flex items-center gap-1">
                                <button type="button" class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted disabled:opacity-30" wire:click="moveStep({{ $index }}, -1)" @disabled($index === 0) aria-label="Move up">
                                    <x-icon name="lucide-chevron-up" />
                                </button>
                                <button type="button" class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted disabled:opacity-30" wire:click="moveStep({{ $index }}, 1)" @disabled($index === count($steps) - 1) aria-label="Move down">
                                    <x-icon name="lucide-chevron-down" />
                                </button>
                                <button type="button" class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive" wire:click="removeStep({{ $index }})" aria-label="Remove this step">
                                    <x-icon name="lucide-trash-2" />
                                </button>
                            </div>
                        </div>

                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ (App\Domain\Workflows\Enums\WorkflowActionType::tryFrom($step['type']) ?? App\Domain\Workflows\Enums\WorkflowActionType::UpdateField)->description() }}
                        </p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @switch($step['type'])
                                @case(WorkflowActionType::UpdateField->value)
                                    <div wire:key="step-{{ $index }}-field-{{ $step['config']['field'] ?? '' }}">
                                        <div>
                                            <x-form.label :for="'steps.'.$index.'.config.field'">Field</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.field'"
                                                :options="$this->writableFieldOptions()"
                                                :selected="$step['config']['field'] ?? ''"
                                                placeholder="Choose a field"
                                                wire:model.live="steps.{{ $index }}.config.field"
                                            />
                                            <x-form.error :for="'steps.'.$index.'.config.field'" />
                                        </div>
                                    </div>
                                    <div>
                                        <x-form.label :for="'steps.'.$index.'.config.value'">Set it to</x-form.label>
                                        <x-form.input wire:model="steps.{{ $index }}.config.value" placeholder="Leave empty to clear it" />
                                        <x-form.error :for="'steps.'.$index.'.config.value'" />
                                    </div>
                                    @break

                                @case(WorkflowActionType::AssignOwner->value)
                                    @php
                                        $strategy = $step['config']['assign_to_strategy']
                                            ?? App\Domain\Workflows\Assignment\AssignmentStrategy::Fixed->value;
                                        $people = collect($this->userOptions())->mapWithKeys(fn ($name, $id) => ['user:'.$id => $name])->all();
                                    @endphp

                                    <div wire:key="step-{{ $index }}-strategy-{{ $strategy }}">
                                        <x-form.label :for="'steps.'.$index.'.config.assign_to_strategy'">How</x-form.label>
                                        <x-select
                                            :name="'steps.'.$index.'.config.assign_to_strategy'"
                                            :options="App\Domain\Workflows\Assignment\AssignmentStrategy::options()"
                                            :selected="$strategy"
                                            wire:model.live="steps.{{ $index }}.config.assign_to_strategy"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            {{ (App\Domain\Workflows\Assignment\AssignmentStrategy::tryFrom($strategy) ?? App\Domain\Workflows\Assignment\AssignmentStrategy::Fixed)->description() }}
                                        </p>
                                    </div>

                                    @if ($strategy === App\Domain\Workflows\Assignment\AssignmentStrategy::Fixed->value)
                                        <div wire:key="step-{{ $index }}-assign-{{ $step['config']['assign_to'] ?? '' }}">
                                            <x-form.label :for="'steps.'.$index.'.config.assign_to'">To</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.assign_to'"
                                                :options="$people"
                                                :selected="$step['config']['assign_to'] ?? ''"
                                                placeholder="Choose a person"
                                                wire:model.live="steps.{{ $index }}.config.assign_to"
                                            />
                                        </div>
                                    @endif

                                    @if (in_array($strategy, [
                                        App\Domain\Workflows\Assignment\AssignmentStrategy::RoundRobin->value,
                                        App\Domain\Workflows\Assignment\AssignmentStrategy::LoadBased->value,
                                    ], true))
                                        <div class="sm:col-span-2" wire:key="step-{{ $index }}-pool">
                                            <x-form.label :for="'steps.'.$index.'.config.pool'">Between</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.pool'"
                                                :options="$people"
                                                :selected="$step['config']['pool'] ?? []"
                                                multiple
                                                wire:model.live="steps.{{ $index }}.config.pool"
                                            />
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Round robin takes turns in this order. Load-based ignores the order and counts what each is already carrying.
                                            </p>
                                        </div>
                                    @endif

                                    @if ($strategy === App\Domain\Workflows\Assignment\AssignmentStrategy::Territory->value)
                                        <div wire:key="step-{{ $index }}-territory-field-{{ $step['config']['territory_field'] ?? '' }}">
                                            <x-form.label :for="'steps.'.$index.'.config.territory_field'">Match on</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.territory_field'"
                                                :options="$this->conditionFieldOptions()"
                                                :selected="$step['config']['territory_field'] ?? ''"
                                                placeholder="Choose a field"
                                                wire:model.live="steps.{{ $index }}.config.territory_field"
                                            />
                                        </div>

                                        <div wire:key="step-{{ $index }}-territory-fallback-{{ $step['config']['territory_fallback'] ?? '' }}">
                                            <x-form.label :for="'steps.'.$index.'.config.territory_fallback'">Anything unlisted goes to</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.territory_fallback'"
                                                :options="$people"
                                                :selected="$step['config']['territory_fallback'] ?? ''"
                                                placeholder="Nobody — leave it be"
                                                wire:model.live="steps.{{ $index }}.config.territory_fallback"
                                            />
                                        </div>

                                        <div class="sm:col-span-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Territories</p>

                                            <div class="mt-2 space-y-2">
                                                @foreach ($step['config']['territories'] ?? [] as $tIndex => $territory)
                                                    <div class="flex flex-wrap items-end gap-2" wire:key="step-{{ $index }}-t-{{ $tIndex }}">
                                                        <div class="w-44">
                                                            <x-form.input
                                                                wire:model="steps.{{ $index }}.config.territories.{{ $tIndex }}.value"
                                                                placeholder="Bangladesh"
                                                            />
                                                        </div>

                                                        <div class="w-52" wire:key="step-{{ $index }}-t-{{ $tIndex }}-user-{{ $territory['user'] ?? '' }}">
                                                            <x-select
                                                                :name="'steps.'.$index.'.config.territories.'.$tIndex.'.user'"
                                                                :options="$people"
                                                                :selected="$territory['user'] ?? ''"
                                                                placeholder="Goes to"
                                                                wire:model.live="steps.{{ $index }}.config.territories.{{ $tIndex }}.user"
                                                            />
                                                        </div>

                                                        <button
                                                            type="button"
                                                            class="mb-1 rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                                            wire:click="removeTerritory({{ $index }}, {{ $tIndex }})"
                                                            aria-label="Remove this territory"
                                                        >
                                                            <x-icon name="lucide-trash-2" />
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>

                                            <button
                                                type="button"
                                                class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline"
                                                wire:click="addTerritory({{ $index }})"
                                            >
                                                <x-icon name="lucide-plus" class="h-3.5 w-3.5" />
                                                Add a territory
                                            </button>
                                        </div>
                                    @endif
                                    @break

                                @case(WorkflowActionType::CreateRecord->value)
                                    <div wire:key="step-{{ $index }}-createmodule-{{ $step['config']['module'] ?? '' }}">
                                        <div>
                                            <x-form.label :for="'steps.'.$index.'.config.module'">Create in</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.module'"
                                                :options="$this->moduleOptions()"
                                                :selected="$step['config']['module'] ?? 'activities'"
                                                wire:model.live="steps.{{ $index }}.config.module"
                                            />
                                            <x-form.error :for="'steps.'.$index.'.config.module'" />
                                        </div>
                                    </div>
                                    <div>
                                        <x-form.label :for="'steps.'.$index.'.config.subject'">Subject</x-form.label>
                                        <x-form.input wire:model="steps.{{ $index }}.config.subject" placeholder="Call the new lead" />
                                        <x-form.error :for="'steps.'.$index.'.config.subject'" />
                                    </div>
                                    <div>
                                        <x-form.label :for="'steps.'.$index.'.config.due_in_days'">Due in days</x-form.label>
                                        <x-form.input type="number" min="0" wire:model="steps.{{ $index }}.config.due_in_days" placeholder="1" />
                                        <x-form.error :for="'steps.'.$index.'.config.due_in_days'" />
                                    </div>
                                    @break

                                @case(WorkflowActionType::SendEmail->value)
                                    <div wire:key="step-{{ $index }}-emailto-{{ $step['config']['recipient'] ?? '' }}">
                                        <div>
                                            <x-form.label :for="'steps.'.$index.'.config.recipient'">Send to</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.recipient'"
                                                :options="$this->emailRecipientOptions()"
                                                :selected="$step['config']['recipient'] ?? 'record_email'"
                                                wire:model.live="steps.{{ $index }}.config.recipient"
                                            />
                                            <x-form.error :for="'steps.'.$index.'.config.recipient'" />
                                        </div>
                                    </div>
                                    <div>
                                        <x-form.label :for="'steps.'.$index.'.config.subject'">Subject</x-form.label>
                                        <x-form.input wire:model="steps.{{ $index }}.config.subject" placeholder="Thanks for getting in touch" />
                                        <x-form.error :for="'steps.'.$index.'.config.subject'" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <div>
                                            <x-form.label :for="'steps.'.$index.'.config.template'">Message</x-form.label>
                                            <textarea rows="4" wire:model="steps.{{ $index }}.config.template" placeholder="Hello @{{record.first_name}}, thanks for getting in touch." class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"></textarea>
                                            <x-form.error :for="'steps.'.$index.'.config.template'" />
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Use <code>@{{record.first_name}}</code> and the like. Unknown fields render empty.
                                        </p>
                                    </div>
                                    @break

                                @case(WorkflowActionType::SendNotification->value)
                                    <div wire:key="step-{{ $index }}-notifyto-{{ $step['config']['recipient'] ?? '' }}">
                                        <div>
                                            <x-form.label :for="'steps.'.$index.'.config.recipient'">Notify</x-form.label>
                                            <x-select
                                                :name="'steps.'.$index.'.config.recipient'"
                                                :options="$this->recipientOptions()"
                                                :selected="$step['config']['recipient'] ?? 'record_owner'"
                                                wire:model.live="steps.{{ $index }}.config.recipient"
                                            />
                                            <x-form.error :for="'steps.'.$index.'.config.recipient'" />
                                        </div>
                                    </div>
                                    <div>
                                        <x-form.label :for="'steps.'.$index.'.config.message'">Message</x-form.label>
                                        <x-form.input wire:model="steps.{{ $index }}.config.message" placeholder="A lead worth calling has arrived." />
                                        <x-form.error :for="'steps.'.$index.'.config.message'" />
                                    </div>
                                    @break

                                @case(WorkflowActionType::RequestApproval->value)
                                    <div class="sm:col-span-2" wire:key="step-{{ $index }}-approvers">
                                        <x-form.label :for="'steps.'.$index.'.config.approvers'" required>Ask, in this order</x-form.label>
                                        <x-select
                                            :name="'steps.'.$index.'.config.approvers'"
                                            :options="collect($this->userOptions())->mapWithKeys(fn ($name, $id) => ['user:'.$id => $name])->all()"
                                            :selected="$step['config']['approvers'] ?? []"
                                            multiple
                                            wire:model.live="steps.{{ $index }}.config.approvers"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            One at a time, in order. Every step below this one waits for the answer.
                                        </p>
                                    </div>

                                    <div>
                                        <x-form.label :for="'steps.'.$index.'.config.hours_to_respond'">Hours to answer</x-form.label>
                                        <x-form.input type="number" min="1" wire:model="steps.{{ $index }}.config.hours_to_respond" placeholder="Leave empty to wait indefinitely" />
                                    </div>

                                    <div wire:key="step-{{ $index }}-timeout-{{ $step['config']['on_timeout'] ?? '' }}">
                                        <x-form.label :for="'steps.'.$index.'.config.on_timeout'">If nobody answers</x-form.label>
                                        <x-select
                                            :name="'steps.'.$index.'.config.on_timeout'"
                                            :options="App\Domain\Approvals\Enums\EscalationOutcome::options()"
                                            :selected="$step['config']['on_timeout'] ?? App\Domain\Approvals\Enums\EscalationOutcome::Escalate->value"
                                            wire:model.live="steps.{{ $index }}.config.on_timeout"
                                        />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <x-form.label :for="'steps.'.$index.'.config.summary'">What they are agreeing to</x-form.label>
                                        <x-form.input wire:model="steps.{{ $index }}.config.summary" placeholder="Left empty, this is written from the steps below" />
                                    </div>
                                    @break

                                @case(WorkflowActionType::CallWebhook->value)
                                    <div class="sm:col-span-2">
                                        <div>
                                            <x-form.label :for="'steps.'.$index.'.config.url'">URL</x-form.label>
                                            <x-form.input wire:model="steps.{{ $index }}.config.url" placeholder="https://example.com/hook" />
                                            <x-form.error :for="'steps.'.$index.'.config.url'" />
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Must be a public https address. Addresses inside this network are refused.
                                        </p>
                                    </div>
                                    @break
                            @endswitch
                        </div>

                        <div class="mt-3 flex flex-wrap gap-4 border-t border-border pt-3 text-xs">
                            <label class="flex items-center gap-2 text-muted-foreground">
                                <input type="checkbox" wire:model="steps.{{ $index }}.is_active" class="rounded border-border text-accent focus:ring-accent/40" />
                                Switched on
                            </label>

                            <label class="flex items-center gap-2 text-muted-foreground">
                                <input type="checkbox" wire:model="steps.{{ $index }}.stop_on_failure" class="rounded border-border text-accent focus:ring-accent/40" />
                                Stop the workflow if this step fails
                            </label>
                        </div>
                    </li>
                @endforeach
            </ol>

            <button type="button" class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline" wire:click="addStep">
                <x-icon name="lucide-plus" />
                Add a step
            </button>
        </section>

        {{-- 5. Try it ------------------------------------------------------- --}}
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">Try it against a record</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Answers "would this fire for that one?" without firing. Conditions are evaluated for real, against what is on screen; the steps are only described.
            </p>

            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div class="w-40">
                    <x-form.label for="dryRunRecordId">Record id</x-form.label>
                    <x-form.input type="number" min="1" wire:model="dryRunRecordId" />
                    <x-form.error for="dryRunRecordId" />
                </div>

                <button
                    type="button"
                    class="mb-0.5 inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
                    wire:click="testAgainst"
                >
                    <x-icon name="lucide-play" />
                    Test
                </button>
            </div>

            @if ($dryRun !== null)
                <div class="mt-4 rounded-lg border border-border bg-muted/40 p-4 text-sm">
                    @if (! $dryRun['found'])
                        <p class="text-muted-foreground">No such record in this module.</p>
                    @else
                        <p class="font-medium text-foreground">{{ $dryRun['label'] }}</p>

                        @if ($dryRun['matches'])
                            <p class="mt-1 text-emerald-600 dark:text-emerald-400">The conditions match — this would run.</p>

                            <ul class="mt-2 list-inside list-disc space-y-1 text-muted-foreground">
                                @foreach ($dryRun['steps'] as $description)
                                    <li>{{ $description }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 text-muted-foreground">The conditions do not match — this would not run.</p>
                        @endif
                    @endif
                </div>
            @endif
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit">Save workflow</x-button>

            <label class="flex items-center gap-2 text-sm text-foreground">
                <input type="checkbox" wire:model="isActive" class="rounded border-border text-accent focus:ring-accent/40" />
                Switched on
            </label>

            <span class="text-xs text-muted-foreground">
                A workflow that is on starts acting on records as soon as it is saved.
            </span>
        </div>
    </form>
</div>
