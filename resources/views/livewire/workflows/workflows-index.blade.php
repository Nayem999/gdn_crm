<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-foreground">Workflows</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                Automations that watch a module and act on the records that match. Within a module they run in the order shown.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <div class="w-56" wire:key="module-filter-{{ $module }}">
                <x-select
                    name="module"
                    :options="$this->moduleOptions()"
                    :selected="$module"
                    aria-label="Filter by module"
                    wire:model.live="module"
                />
            </div>

            @can('create', App\Domain\Workflows\Models\Workflow::class)
                <a
                    href="{{ route('workflows.create') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
                >
                    <x-icon name="lucide-plus" />
                    New workflow
                </a>
            @endcan
        </div>
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    @php
        $grouped = $this->workflows->groupBy('module');
    @endphp

    @forelse ($grouped as $moduleKey => $workflows)
        <section class="rounded-xl border border-border bg-card">
            <h2 class="border-b border-border px-4 py-3 text-sm font-semibold text-foreground">
                {{ App\Domain\Workflows\WorkflowModules::label($moduleKey) }}
            </h2>

            <ul class="divide-y divide-border">
                @foreach ($workflows as $workflow)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="workflow-{{ $workflow->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <a
                                    href="{{ route('workflows.edit', $workflow) }}"
                                    wire:navigate
                                    class="truncate text-sm font-medium text-foreground hover:text-accent hover:underline"
                                >{{ $workflow->name }}</a>

                                <x-status-chip :color="$workflow->is_active ? 'emerald' : 'slate'" dot>
                                    {{ $workflow->is_active ? 'On' : 'Off' }}
                                </x-status-chip>
                            </div>

                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ $workflow->trigger()->label() }}
                                @if ($workflow->trigger_field)
                                    &mdash; {{ App\Domain\Workflows\WorkflowModules::fieldOptions($workflow->module())[$workflow->trigger_field] ?? $workflow->trigger_field }}
                                @endif
                                &middot; {{ $workflow->actions->count() }} {{ str('step')->plural($workflow->actions->count()) }}
                                &middot; {{ $workflow->runs_count }} {{ str('run')->plural($workflow->runs_count) }}
                            </p>
                        </div>

                        <div class="flex items-center gap-1">
                            @can('toggle', $workflow)
                                <button
                                    type="button"
                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                                    wire:click="toggle({{ $workflow->id }})"
                                >{{ $workflow->is_active ? 'Switch off' : 'Switch on' }}</button>
                            @endcan

                            @can('delete', $workflow)
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                    wire:click="delete({{ $workflow->id }})"
                                    wire:confirm="Remove {{ $workflow->name }}? Its history is kept."
                                    aria-label="Remove {{ $workflow->name }}"
                                >
                                    <x-icon name="lucide-trash-2" />
                                </button>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <x-empty-state
            icon="zap"
            heading="No workflows yet"
            description="A workflow watches a module and acts on the records that match — set a field, assign an owner, send a note."
        />
    @endforelse
</div>
