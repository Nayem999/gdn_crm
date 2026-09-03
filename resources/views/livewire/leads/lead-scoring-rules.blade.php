@php
    use App\Domain\Leads\Enums\LeadRuleKind;
    use App\Domain\Leads\Models\LeadScoringRule;
    use App\Domain\Shared\Enums\FilterFieldType;
    use App\Domain\Shared\Enums\FilterOperator;
    use App\Domain\Shared\Enums\FilterValueMode;
@endphp

<div>
    <x-settings-shell
        heading="Lead scoring"
        description="Points make a lead worth calling; requirements decide when it may be qualified."
        active="settings.lead-scoring"
    >
        @php $fields = $this->fields(); @endphp

        @if ($saved)
            <x-alert variant="success" class="mb-4">{{ $saved }}</x-alert>
        @endif

        <div class="mb-6 rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">How a score reads</h2>

            <div class="mt-3 flex flex-wrap gap-3">
                @foreach ($this->grades() as $grade)
                    <div class="flex items-center gap-2">
                        <x-status-chip :color="$grade->color()" dot>{{ $grade->label() }}</x-status-chip>
                        <span class="text-xs text-muted-foreground">
                            {{ $grade->threshold() }}+ &middot; {{ $grade->description() }}
                        </span>
                    </div>
                @endforeach
            </div>

            <p class="mt-3 text-xs text-muted-foreground">
                Points are summed and then held to the 0&ndash;100 range, so a rule may subtract
                without pushing a lead below zero.
            </p>
        </div>

        @foreach ($this->kinds() as $kind)
            @php $indexes = $this->indexesFor($kind); @endphp

            <section class="mb-6 rounded-xl border border-border bg-card p-5 sm:p-6" wire:key="kind-{{ $kind->value }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-foreground">{{ $kind->label() }}s</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">{{ $kind->description() }}</p>
                    </div>

                    @can('update', LeadScoringRule::class)
                        <x-button type="button" variant="secondary" wire:click="addRule('{{ $kind->value }}')">
                            <x-icon name="lucide-plus" />
                            Add
                        </x-button>
                    @endcan
                </div>

                @if ($indexes === [])
                    <p class="mt-4 rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        @if ($kind === LeadRuleKind::Qualification)
                            No requirements, so any lead may be qualified.
                        @else
                            No scoring rules, so every lead scores zero.
                        @endif
                    </p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($indexes as $index)
                            @php
                                $rule = $rules[$index];
                                $field = $fields[$rule['field']] ?? null;
                                $operator = FilterOperator::tryFrom((string) $rule['operator']);
                                $valueMode = $operator?->valueMode() ?? FilterValueMode::Single;
                                $numeric = $field?->type === FilterFieldType::Number;
                                $dated = $field?->type === FilterFieldType::Date;
                            @endphp

                            <li class="rounded-lg border border-border p-4" wire:key="rule-{{ $index }}">
                                <div class="grid gap-3 sm:grid-cols-12">
                                    <div class="sm:col-span-12">
                                        <x-form.label for="rule-label-{{ $index }}">Name</x-form.label>
                                        <x-form.input
                                            id="rule-label-{{ $index }}"
                                            wire:model="rules.{{ $index }}.label"
                                            placeholder="What this rule recognises"
                                        />
                                        <x-form.error for="rules.{{ $index }}.label" />
                                    </div>

                                    <div class="sm:col-span-4">
                                        <x-select
                                            name="rules.{{ $index }}.field"
                                            label="Field"
                                            :options="$this->fieldOptionsFor($kind)"
                                            :selected="$rule['field']"
                                            placeholder="Choose a field…"
                                            :error="$errors->first('rules.'.$index.'.field')"
                                            wire:model.live="rules.{{ $index }}.field"
                                        />
                                    </div>

                                    {{-- Keyed on the field: <x-select> hides Tom Select behind
                                         wire:ignore, so a re-render cannot update the options in
                                         place. Changing the key makes Livewire replace the node
                                         and Tom Select rebuild with the new field's comparisons. --}}
                                    <div class="sm:col-span-4" wire:key="rule-op-{{ $index }}-{{ $rule['field'] }}">
                                        <x-select
                                            name="rules.{{ $index }}.operator"
                                            label="Comparison"
                                            :options="$this->operatorOptionsFor((string) $rule['field'])"
                                            :selected="$rule['operator']"
                                            placeholder="Choose a comparison…"
                                            :error="$errors->first('rules.'.$index.'.operator')"
                                            wire:model.live="rules.{{ $index }}.operator"
                                        />
                                    </div>

                                    <div class="sm:col-span-4" wire:key="rule-val-{{ $index }}-{{ $rule['field'] }}-{{ $rule['operator'] }}">
                                        @if ($valueMode === FilterValueMode::None)
                                            <x-form.label>Value</x-form.label>
                                            <p class="py-2 text-sm text-muted-foreground">Not needed.</p>
                                        @elseif ($valueMode === FilterValueMode::Multiple)
                                            <x-select
                                                name="rules.{{ $index }}.selected"
                                                label="Values"
                                                :options="$field?->options ?? []"
                                                :selected="$rule['selected']"
                                                multiple
                                                placeholder="Choose one or more…"
                                                :error="$errors->first('rules.'.$index.'.selected')"
                                                wire:model="rules.{{ $index }}.selected"
                                            />
                                        @elseif ($field?->type === FilterFieldType::Select)
                                            <x-select
                                                name="rules.{{ $index }}.value"
                                                label="Value"
                                                :options="$field->options"
                                                :selected="$rule['value']"
                                                placeholder="Choose a value…"
                                                :error="$errors->first('rules.'.$index.'.value')"
                                                wire:model="rules.{{ $index }}.value"
                                            />
                                        @else
                                            <x-form.label for="rule-value-{{ $index }}">
                                                {{ $valueMode === FilterValueMode::Pair ? 'From' : 'Value' }}
                                            </x-form.label>
                                            <x-form.input
                                                id="rule-value-{{ $index }}"
                                                type="{{ $operator === FilterOperator::LastDays || $numeric ? 'number' : ($dated ? 'date' : 'text') }}"
                                                wire:model="rules.{{ $index }}.value"
                                            />

                                            @if ($valueMode === FilterValueMode::Pair)
                                                <x-form.label for="rule-second-{{ $index }}" class="mt-2">To</x-form.label>
                                                <x-form.input
                                                    id="rule-second-{{ $index }}"
                                                    type="{{ $numeric ? 'number' : ($dated ? 'date' : 'text') }}"
                                                    wire:model="rules.{{ $index }}.second_value"
                                                />
                                            @endif

                                            <x-form.error for="rules.{{ $index }}.value" />
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap items-end justify-between gap-4 border-t border-border pt-3">
                                    <div class="flex flex-wrap items-end gap-4">
                                        @if ($kind === LeadRuleKind::Score)
                                            <div class="w-28">
                                                <x-form.label for="rule-points-{{ $index }}">Points</x-form.label>
                                                <x-form.input
                                                    id="rule-points-{{ $index }}"
                                                    type="number"
                                                    step="1"
                                                    wire:model="rules.{{ $index }}.points"
                                                />
                                                <x-form.error for="rules.{{ $index }}.points" />
                                            </div>
                                        @endif

                                        <label class="flex items-center gap-2 pb-2 text-sm text-foreground">
                                            <input
                                                type="checkbox"
                                                wire:model="rules.{{ $index }}.is_active"
                                                class="h-4 w-4 rounded border-border text-accent focus:ring-accent/40"
                                            />
                                            In use
                                        </label>
                                    </div>

                                    @can('update', LeadScoringRule::class)
                                        <button
                                            type="button"
                                            wire:click="removeRule({{ $index }})"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-muted-foreground hover:bg-muted hover:text-destructive"
                                        >
                                            <x-icon name="lucide-trash-2" />
                                            Remove
                                        </button>
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach

        @can('update', LeadScoringRule::class)
            <div class="flex flex-wrap items-center gap-3">
                <x-button type="button" wire:click="save" wire:loading.attr="disabled">
                    <x-icon name="lucide-save" />
                    Save rules
                </x-button>

                <x-button type="button" variant="secondary" wire:click="recalculate" wire:loading.attr="disabled">
                    <x-icon name="lucide-refresh-cw" />
                    Rescore every lead
                </x-button>

                <span class="text-xs text-muted-foreground">
                    Saving rescores every lead in the background.
                </span>
            </div>
        @endcan
    </x-settings-shell>
</div>
