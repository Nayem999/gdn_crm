@php
    use App\Domain\Activities\ActivityRelations;
    use App\Domain\Settings\DisplayTime;
    use App\Domain\Shared\UI\ChipPalette;

    $tasks = $this->tasks;
@endphp

<div class="rounded-xl border border-border bg-card p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-base font-semibold text-foreground">My tasks</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
                @if ($this->canSeeActivities())
                    {{ $this->openCount }} open, soonest first
                @else
                    Your open work
                @endif
            </p>
        </div>

        @if ($this->canSeeActivities())
            <a href="{{ route('activities.index', ['chip' => 'mine']) }}" wire:navigate
               class="text-xs font-medium text-accent hover:underline">See all</a>
        @endif
    </div>

    @if (! $this->canSeeActivities())
        <x-empty-state
            icon="lock"
            heading="Activities are not yours to see"
            description="Ask an administrator for the activities permission to keep a task list here."
        />
    @elseif ($tasks === [])
        <x-empty-state
            icon="check-check"
            heading="Nothing on your list"
            description="No open tasks, calls or meetings assigned to you."
        >
            <x-slot:actions>
                @can('create', App\Domain\Activities\Models\Activity::class)
                    <a href="{{ route('activities.create') }}" wire:navigate
                       class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                        <x-icon name="lucide-plus" />
                        Add an activity
                    </a>
                @endcan
            </x-slot:actions>
        </x-empty-state>
    @else
        <ul class="space-y-1.5" wire:loading.class="opacity-60" wire:target="complete">
            @foreach ($tasks as $task)
                <li
                    wire:key="task-{{ $task->id }}"
                    @class([
                        'flex items-start gap-2.5 rounded-lg border p-2.5',
                        'border-destructive/40 bg-destructive/5' => $task->isOverdue(),
                        'border-border' => ! $task->isOverdue(),
                    ])
                >
                    @can('update', $task)
                        <button
                            type="button"
                            wire:click="complete({{ $task->id }})"
                            wire:loading.attr="disabled"
                            wire:target="complete({{ $task->id }})"
                            class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-muted-foreground/50 text-transparent transition-colors hover:border-emerald-500 hover:text-emerald-500"
                            aria-label="Mark {{ $task->subject }} as done"
                        >
                            <x-icon name="lucide-check" class="h-3 w-3" />
                        </button>
                    @else
                        <span class="mt-0.5 h-4 w-4 shrink-0 rounded-full border border-muted-foreground/30" aria-hidden="true"></span>
                    @endcan

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <a
                                href="{{ route('activities.edit', $task->id) }}"
                                wire:navigate
                                class="truncate text-sm font-medium text-foreground hover:text-accent hover:underline"
                            >{{ $task->subject }}</a>

                            <span class="{{ ChipPalette::BASE }} {{ ChipPalette::classes($task->type()->color()) }}">
                                {{ $task->type()->label() }}
                            </span>
                        </div>

                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                            <span @class(['tabular-nums', 'font-medium text-destructive' => $task->isOverdue()])>
                                {{ $task->all_day ? DisplayTime::date($task->due_at) : DisplayTime::dateTime($task->due_at) }}
                            </span>

                            @if ($task->related)
                                <span aria-hidden="true">&middot;</span>
                                <span class="truncate">{{ ActivityRelations::label($task->related) }}</span>
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($this->openCount > count($tasks))
            <p class="mt-3 text-center text-xs text-muted-foreground">
                {{ $this->openCount - count($tasks) }} more on your list.
            </p>
        @endif
    @endif
</div>
