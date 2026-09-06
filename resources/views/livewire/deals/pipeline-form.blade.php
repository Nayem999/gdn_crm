<div>
    <x-settings-shell
        :heading="$this->isEditing() ? 'Edit pipeline' : 'Add pipeline'"
        description="A pipeline is the route a deal is worked along. Its stages are the checkpoints, in order."
        active="settings.pipelines"
    >

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Details</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="pipeline-name" required>Name</x-form.label>
                    <x-form.input id="pipeline-name" wire:model="name" :invalid="$errors->has('name')" placeholder="Standard sales" />
                    <x-form.error for="name" />
                </div>

                <div>
                    <x-form.label for="pipeline-description">Description</x-form.label>
                    <x-form.input id="pipeline-description" wire:model="description" :invalid="$errors->has('description')" placeholder="When to use this one" />
                    <x-form.error for="description" />
                </div>
            </div>

            <label class="mt-4 flex items-start gap-3">
                <input
                    type="checkbox"
                    wire:model="isDefault"
                    class="mt-0.5 h-4 w-4 rounded border-border text-accent focus:ring-2 focus:ring-accent/40"
                >
                <span>
                    <span class="block text-sm font-medium text-foreground">Make this the default pipeline</span>
                    <span class="block text-xs text-muted-foreground">
                        Deals go on the default pipeline when nobody chooses one. Only one pipeline can hold it.
                    </span>
                </span>
            </label>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">Stages</h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        In the order a deal moves through them. Drag a row by its handle to reorder.
                    </p>
                </div>

                <x-button type="button" variant="secondary" wire:click="addStage">
                    <x-icon name="lucide-plus" class="h-4 w-4" />
                    Add stage
                </x-button>
            </div>

            <x-form.error for="stages" class="mt-3" />

            <ul class="mt-4 space-y-3" x-data="sortableList({ method: 'reorderStages' })">
                @foreach ($stages as $index => $stage)
                    <li
                        class="rounded-lg border border-border p-3"
                        data-sortable-item
                        data-sortable-id="{{ $stage['key'] ?: 'new-'.$index }}"
                        wire:key="stage-{{ $generation }}-{{ $index }}"
                    >
                        <div class="flex items-start gap-3">
                            <button
                                type="button"
                                data-sortable-handle
                                class="mt-7 flex h-8 w-8 shrink-0 cursor-grab items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                                aria-label="Reorder stage {{ $index + 1 }}"
                            >
                                <x-icon name="lucide-grip-vertical" class="h-4 w-4" />
                            </button>

                            <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <x-form.label :for="'stage-name-'.$index" required>Name</x-form.label>
                                    <x-form.input
                                        :id="'stage-name-'.$index"
                                        wire:model="stages.{{ $index }}.name"
                                        :invalid="$errors->has('stages.'.$index.'.name')"
                                        placeholder="Qualification"
                                    />
                                    <x-form.error :for="'stages.'.$index.'.name'" />
                                </div>

                                <div wire:key="stage-outcome-{{ $generation }}-{{ $index }}">
                                    <x-select
                                        :name="'stages.'.$index.'.outcome'"
                                        label="Outcome"
                                        :options="$this->outcomeOptions()"
                                        :selected="$stage['outcome']"
                                        placeholder="Choose an outcome…"
                                        :error="$errors->first('stages.'.$index.'.outcome')"
                                        wire:model.live="stages.{{ $index }}.outcome"
                                    />
                                </div>

                                <div>
                                    <x-form.label :for="'stage-probability-'.$index" required>Probability</x-form.label>
                                    <div class="relative">
                                        <x-form.input
                                            :id="'stage-probability-'.$index"
                                            type="number"
                                            min="0"
                                            max="100"
                                            class="pr-8"
                                            wire:model="stages.{{ $index }}.probability"
                                            :invalid="$errors->has('stages.'.$index.'.probability')"
                                            :readonly="$this->isProbabilityFixed($index)"
                                        />
                                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">%</span>
                                    </div>

                                    @if ($this->isProbabilityFixed($index))
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Fixed by the outcome — a won deal is certain, a lost one is not happening.
                                        </p>
                                    @endif

                                    <x-form.error :for="'stages.'.$index.'.probability'" />
                                </div>

                                <div wire:key="stage-color-{{ $generation }}-{{ $index }}">
                                    <x-select
                                        :name="'stages.'.$index.'.color'"
                                        label="Colour"
                                        :options="$this->colorOptions()"
                                        :selected="$stage['color']"
                                        placeholder="Choose a colour…"
                                        :error="$errors->first('stages.'.$index.'.color')"
                                        wire:model="stages.{{ $index }}.color"
                                    />
                                </div>
                            </div>

                            <button
                                type="button"
                                wire:click="removeStage({{ $index }})"
                                class="mt-7 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                aria-label="Remove stage {{ $index + 1 }}"
                            >
                                <x-icon name="lucide-trash-2" class="h-4 w-4" />
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($stages === [])
                <p class="mt-4 rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                    A pipeline needs at least one stage. Add one to continue.
                </p>
            @endif
        </section>

        <div class="flex items-center gap-2">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <x-icon name="lucide-check" class="h-4 w-4" />
                {{ $this->isEditing() ? 'Save pipeline' : 'Create pipeline' }}
            </x-button>

            <a href="{{ route('settings.pipelines') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                Cancel
            </a>
        </div>
    </form>

    </x-settings-shell>
</div>
