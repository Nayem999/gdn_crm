@php
    use App\Domain\Workflows\Enums\WorkflowRunStatus;
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-foreground">Workflow log</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                Every firing, what it did, and what went wrong. A run is kept even after the workflow that made it is removed.
            </p>
        </div>

        <a
            href="{{ route('workflows.index') }}"
            wire:navigate
            class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
            <x-icon name="lucide-zap" />
            Workflows
        </a>
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    {{-- The tally follows the filters, minus the outcome one: it is what the
         outcome filter is chosen from, so filtering by it would leave a single
         figure describing itself. --}}
    <div class="flex flex-wrap gap-2">
        @foreach (WorkflowRunStatus::cases() as $case)
            @php $count = $tally[$case->value] ?? 0; @endphp

            <button
                type="button"
                @class([
                    'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition-colors',
                    'border-accent bg-accent/10 text-accent' => $status === $case->value,
                    'border-border bg-card text-muted-foreground hover:bg-muted' => $status !== $case->value,
                ])
                wire:click="$set('status', '{{ $status === $case->value ? '' : $case->value }}')"
            >
                <x-status-chip :color="$case->color()" dot>{{ $case->label() }}</x-status-chip>
                <span class="font-semibold tabular-nums">{{ $count }}</span>
            </button>
        @endforeach
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-52" wire:key="filter-module-{{ $module }}">
            <x-form.label for="module">Module</x-form.label>
            <x-select name="module" :options="$this->moduleOptions()" :selected="$module" wire:model.live="module" />
        </div>

        <div class="w-56" wire:key="filter-workflow-{{ $workflow }}">
            <x-form.label for="workflow">Workflow</x-form.label>
            <x-select name="workflow" :options="$this->workflowOptions()" :selected="$workflow" wire:model.live="workflow" />
        </div>

        <div class="w-56" wire:key="filter-trigger-{{ $trigger }}">
            <x-form.label for="trigger">Trigger</x-form.label>
            <x-select name="trigger" :options="$this->triggerOptions()" :selected="$trigger" wire:model.live="trigger" />
        </div>

        @if ($this->hasFilters())
            <button type="button" class="mb-1 text-sm font-medium text-muted-foreground hover:text-destructive" wire:click="clearFilters">
                Clear
            </button>
        @endif

        @can('retry', App\Domain\Workflows\Models\Workflow::class)
            @if (($tally[WorkflowRunStatus::Failed->value] ?? 0) > 0)
                <button
                    type="button"
                    class="mb-0.5 ml-auto inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
                    wire:click="retryFiltered"
                    wire:confirm="Put every failed run matching these filters back on the queue?"
                >
                    <x-icon name="lucide-refresh-cw" />
                    Retry the failures shown
                </button>
            @endif
        @endcan
    </div>

    <div class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[52rem] text-sm">
            <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th class="px-4 py-2 font-semibold">Workflow</th>
                    <th class="px-4 py-2 font-semibold">Trigger</th>
                    <th class="px-4 py-2 font-semibold">Outcome</th>
                    <th class="px-4 py-2 font-semibold">When</th>
                    <th class="px-4 py-2 font-semibold">Took</th>
                    <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>

            <tbody class="divide-y divide-border">
                @forelse ($runs as $run)
                    <tr wire:key="run-{{ $run->id }}" @class(['bg-destructive/5' => $run->status() === WorkflowRunStatus::Failed])>
                        <td class="px-4 py-2">
                            <button type="button" class="text-left font-medium text-foreground hover:text-accent hover:underline" wire:click="expand({{ $run->id }})">
                                {{ $run->workflow_name }}
                            </button>
                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                {{ App\Domain\Workflows\WorkflowModules::label($run->module) }}
                                @if ($run->subject_id)
                                    &middot; record #{{ $run->subject_id }}
                                @endif
                                @unless ($run->workflow_id)
                                    &middot; workflow since removed
                                @endunless
                            </span>
                        </td>

                        <td class="px-4 py-2 text-muted-foreground">{{ $run->trigger()->label() }}</td>

                        <td class="px-4 py-2">
                            <x-status-chip :color="$run->status()->color()" dot>{{ $run->status()->label() }}</x-status-chip>
                            @if ($run->message)
                                <span class="mt-0.5 block max-w-md text-xs text-muted-foreground">{{ $run->message }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-2 text-muted-foreground">{{ $run->started_at->toDayDateTimeString() }}</td>

                        <td class="px-4 py-2 tabular-nums text-muted-foreground">
                            {{ $run->duration_ms === null ? '—' : $run->duration_ms.' ms' }}
                        </td>

                        <td class="px-4 py-2 text-right">
                            @can('retry', App\Domain\Workflows\Models\Workflow::class)
                                @if ($run->status()->isRetryable())
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
                                        wire:click="retry({{ $run->id }})"
                                    >
                                        <x-icon name="lucide-refresh-cw" class="h-3.5 w-3.5" />
                                        Retry
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>

                    @if ($expanded === $run->id)
                        <tr wire:key="run-steps-{{ $run->id }}" class="bg-muted/30">
                            <td colspan="6" class="px-4 py-3">
                                @if ($run->steps->isEmpty())
                                    <p class="text-xs text-muted-foreground">This run recorded no steps.</p>
                                @else
                                    <ol class="space-y-2">
                                        @foreach ($run->steps as $step)
                                            <li class="flex flex-wrap items-start gap-3 text-xs">
                                                <span class="w-5 shrink-0 text-right tabular-nums text-muted-foreground">{{ $loop->iteration }}.</span>

                                                <x-status-chip :color="$step->status()->color()">
                                                    {{ $step->actionType()->label() }}
                                                </x-status-chip>

                                                <span class="min-w-0 flex-1 text-foreground">{{ $step->message ?? '—' }}</span>

                                                @if ($step->duration_ms !== null)
                                                    <span class="tabular-nums text-muted-foreground">{{ $step->duration_ms }} ms</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif

                                @if ($run->context)
                                    <details class="mt-3">
                                        <summary class="cursor-pointer text-xs font-medium text-muted-foreground">What the trigger saw</summary>
                                        <pre class="mt-2 overflow-x-auto rounded-lg bg-card p-3 text-xs text-muted-foreground">{{ json_encode($run->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">
                            {{ $this->hasFilters() ? 'Nothing matches these filters.' : 'Nothing has run yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $runs->links() }}</div>
</div>
