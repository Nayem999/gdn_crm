<div>
    <x-settings-shell
        :heading="$groupLabel"
        :description="$groupDescription"
        active="settings.group"
        :active-params="['group' => $group]"
    >
        <div
            x-data="{ show: false }"
            x-on:settings-saved.window="show = true; setTimeout(() => show = false, 3000)"
            x-show="show"
            x-cloak
            x-transition
            class="mb-6"
        >
            <x-alert variant="success">Settings saved.</x-alert>
        </div>

        @if (! $this->canManageSecrets() && collect($this->fields())->contains(fn ($field) => $field->secret))
            <div class="mb-6">
                <x-alert variant="info">
                    This group stores credentials. You need the
                    &ldquo;Read and replace stored credentials&rdquo; permission to see or change them.
                </x-alert>
            </div>
        @endif

        <form wire:submit="save" class="space-y-6">
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    @foreach ($this->visibleFields() as $key => $field)
                        <div @class(['sm:col-span-2' => $field->secret || $field->type->value === 'text'])>
                            <x-form.label :for="'setting-' . $key" :required="$field->required">
                                {{ $field->label }}
                            </x-form.label>

                            @if ($field->secret)
                                <x-form.secret
                                    :id="'setting-' . $key"
                                    :field="$key"
                                    :stored="$this->hasStoredSecret($key)"
                                    :replacing="$this->isReplacing($key)"
                                    :invalid="$errors->has('values.' . $key)"
                                    wire:model="values.{{ $key }}"
                                />
                            @elseif ($field->options !== [])
                                <x-select
                                    :name="'setting-' . $key"
                                    :options="$field->options"
                                    :selected="$this->values[$key] ?? null"
                                    :error="$errors->first('values.' . $key)"
                                    :hint="$field->help"
                                    wire:model="values.{{ $key }}"
                                />
                            @else
                                <x-form.input
                                    :id="'setting-' . $key"
                                    wire:model="values.{{ $key }}"
                                    :invalid="$errors->has('values.' . $key)"
                                />
                            @endif

                            @unless ($field->options !== [] && ! $field->secret)
                                <x-form.error :for="'values.' . $key" />

                                @if ($field->help)
                                    <p class="mt-1.5 text-xs text-muted-foreground">{{ $field->help }}</p>
                                @endif
                            @endunless
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($this->canUpdate())
                <div class="flex items-center gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="save">Save settings</span>
                        <span wire:loading wire:target="save">Saving&hellip;</span>
                    </x-button>
                </div>
            @else
                <p class="text-sm text-muted-foreground">You have read-only access to these settings.</p>
            @endif
        </form>
    </x-settings-shell>
</div>
