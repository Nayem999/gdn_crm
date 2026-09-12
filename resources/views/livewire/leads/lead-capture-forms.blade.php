@php
    use App\Domain\Leads\Capture\CaptureField;
    use App\Domain\Shared\UI\ChipPalette;
    $forms = $this->forms;
@endphp

<div>
    <x-settings-shell
        heading="Lead capture forms"
        description="Public forms you can embed on a website. A submission becomes a lead, owned by whoever the form names."
        active="settings.lead-forms"
    >
        <div
            x-data="{ message: '', tone: 'success' }"
            x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
            x-show="message" x-cloak class="mb-4"
        >
            <template x-if="tone === 'error'"><x-alert variant="error"><span x-text="message"></span></x-alert></template>
            <template x-if="tone !== 'error'"><x-alert variant="success"><span x-text="message"></span></x-alert></template>
        </div>

        @unless ($editing)
            <button type="button" wire:click="add"
                    class="mb-5 inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-primary-foreground hover:opacity-90">
                <x-icon name="lucide-plus" />
                Add form
            </button>
        @endunless

        @if ($editing)
            <form wire:submit="save" class="mb-6 rounded-xl border border-border bg-card p-5">
                <h3 class="text-base font-semibold text-foreground">{{ $editingId === null ? 'New form' : 'Edit form' }}</h3>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-form.label for="lcf-name" required>Name</x-form.label>
                        <x-form.input id="lcf-name" wire:model="name" placeholder="Contact us" class="mt-1" />
                        <x-form.error for="name" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="lcf-kind" required>Interface</x-form.label>
                        <x-select name="lcf_kind" :options="\App\Domain\Leads\Models\LeadCaptureForm::kindOptions()" :selected="$kind" wire:model.live="kind" class="mt-1" />
                        <p class="mt-1 text-xs text-muted-foreground">A chat widget takes messages instead of a form, and makes a lead as soon as the visitor leaves an address.</p>
                        <x-form.error for="kind" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="lcf-owner" required>New leads go to</x-form.label>
                        <x-select name="lcf_owner" :options="$this->ownerOptions()" :selected="$ownerId" wire:model="ownerId" class="mt-1" />
                        <p class="mt-1 text-xs text-muted-foreground">An unowned lead is how a captured lead goes nowhere.</p>
                        <x-form.error for="ownerId" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="lcf-source">Recorded source</x-form.label>
                        <x-select name="lcf_source" :options="$this->sourceOptions()" :selected="$source"
                                  placeholder="None" clearable wire:model="source" class="mt-1" />
                        <x-form.error for="source" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="lcf-submit" required>Button label</x-form.label>
                        <x-form.input id="lcf-submit" wire:model="submitLabel" class="mt-1" />
                        <x-form.error for="submitLabel" class="mt-1" />
                    </div>
                </div>

                <div class="mt-4">
                    <x-form.label for="lcf-description">Intro text</x-form.label>
                    <x-form.input id="lcf-description" wire:model="description" class="mt-1" />
                    <x-form.error for="description" class="mt-1" />
                </div>

                {{-- The fields. Chosen from a fixed catalogue: the key decides
                     which lead column a public submission writes to. --}}
                <div class="mt-5">
                    <x-form.label>Fields</x-form.label>

                    <ul class="mt-1 space-y-2">
                        @foreach ($fields as $index => $field)
                            <li wire:key="lcf-field-{{ $index }}" class="flex flex-wrap items-center gap-2 rounded-lg border border-border p-2">
                                <span class="w-32 shrink-0 font-mono text-xs text-muted-foreground">{{ $field['key'] }}</span>

                                <x-form.input wire:model="fields.{{ $index }}.label" class="flex-1" aria-label="Label for {{ $field['key'] }}" />

                                <label class="flex shrink-0 items-center gap-1.5 text-xs text-foreground">
                                    <input type="checkbox" wire:model="fields.{{ $index }}.required"
                                           class="rounded border-border text-accent focus:ring-accent/40">
                                    Required
                                </label>

                                <button type="button" wire:click="removeField({{ $index }})"
                                        class="shrink-0 rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                        aria-label="Remove {{ $field['key'] }}">
                                    <x-icon name="lucide-x" class="h-4 w-4" />
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    <x-form.error for="fields" class="mt-1" />

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($this->fieldOptions() as $key => $label)
                            @continue(collect($fields)->contains('key', $key))
                            <button type="button" wire:click="addField('{{ $key }}')"
                                    class="rounded-full border border-border px-2.5 py-1 text-xs font-medium text-muted-foreground hover:text-foreground">
                                + {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-form.label for="lcf-success">Thank-you message</x-form.label>
                        <x-form.input id="lcf-success" wire:model="successMessage" class="mt-1" />
                        <x-form.error for="successMessage" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="lcf-redirect">Or send them to</x-form.label>
                        <x-form.input id="lcf-redirect" wire:model="redirectUrl" placeholder="https://example.com/thanks" class="mt-1" />
                        <x-form.error for="redirectUrl" class="mt-1" />
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-2 text-sm text-foreground">
                    <input type="checkbox" wire:model="isActive" class="rounded border-border text-accent focus:ring-accent/40">
                    Accepting submissions
                </label>

                <div class="mt-5 flex items-center justify-end gap-2 border-t border-border pt-4">
                    <button type="button" wire:click="cancel" class="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">Save form</button>
                </div>
            </form>
        @endif

        @if ($forms->isEmpty())
            <x-empty-state icon="clipboard-list" heading="No capture forms yet"
                           description="Add one, put the snippet on your website, and enquiries arrive as leads." />
        @else
            <ul class="space-y-3">
                @foreach ($forms as $form)
                    <li wire:key="lcf-{{ $form->id }}"
                        @class([
                            'rounded-xl border p-4',
                            'border-border bg-card' => $form->is_active,
                            'border-dashed border-border bg-muted/30' => ! $form->is_active,
                        ])>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="font-medium text-foreground">{{ $form->name }}</span>
                                    @unless ($form->is_active)
                                        <span class="{{ ChipPalette::BASE }} {{ ChipPalette::classes('slate') }}">Closed</span>
                                    @endunless
                                </div>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ $form->submission_count }} {{ \Illuminate\Support\Str::plural('submission', $form->submission_count) }}
                                    @if ($form->last_submitted_at)
                                        &middot; last {{ $form->last_submitted_at->diffForHumans() }}
                                    @endif
                                    &middot; goes to {{ $form->owner?->name }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <a href="{{ $form->publicUrl() }}" target="_blank" rel="noopener"
                                   class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">Preview</a>
                                <button type="button" wire:click="edit({{ $form->id }})"
                                        class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">Edit</button>
                                <button type="button" wire:click="delete({{ $form->id }})"
                                        wire:confirm="Remove {{ $form->name }}? Anywhere it is embedded will stop working."
                                        class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-destructive/10 hover:text-destructive">Remove</button>
                            </div>
                        </div>

                        {{-- The snippet to paste. An iframe, not a script: a
                             script tag would run our code on somebody else's
                             page. --}}
                        <div class="mt-3" x-data="{ copied: false }">
                            <label class="text-xs font-medium text-muted-foreground" for="snippet-{{ $form->id }}">Embed on your site</label>
                            <div class="mt-1 flex gap-2">
                                <input id="snippet-{{ $form->id }}" type="text" readonly
                                       value="{{ $form->embedSnippet() }}"
                                       x-ref="snippet"
                                       class="w-full rounded-lg border border-border bg-muted/40 px-3 py-2 font-mono text-xs text-muted-foreground">
                                <button type="button"
                                        x-on:click="$refs.snippet.select(); navigator.clipboard.writeText($refs.snippet.value); copied = true; setTimeout(() => copied = false, 2000)"
                                        class="shrink-0 rounded-lg border border-border px-3 text-xs font-medium text-foreground hover:bg-muted">
                                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                                </button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-settings-shell>
</div>
