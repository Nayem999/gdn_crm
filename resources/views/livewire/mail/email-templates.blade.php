<div>
    <x-settings-shell
        heading="Email templates"
        description="Wording a salesperson can pick and send. Merge fields are filled in from the record the message is about."
        active="settings.email-templates"
    >
        <div
            x-data="{ show: false }"
            x-on:template-saved.window="show = true; setTimeout(() => show = false, 3000)"
            x-show="show"
            x-cloak
            x-transition
            class="mb-4"
        >
            <x-alert variant="success">Template saved.</x-alert>
        </div>

        @if ($showForm)
            <form wire:submit="save" class="mb-6 space-y-5 rounded-xl border border-border bg-card p-5 sm:p-6">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="template-name" required>Name</x-form.label>
                        <x-form.input id="template-name" wire:model="name" :invalid="$errors->has('name')" />
                        <x-form.error for="name" />
                    </div>

                    <div>
                        <x-form.label for="template-module" required>About a</x-form.label>
                        <x-select name="template-module" :options="$modules" :selected="$module" wire:model.live="module" />
                        <p class="mt-1.5 text-xs text-muted-foreground">Decides which merge fields are available.</p>
                    </div>

                    <div class="sm:col-span-2">
                        <x-form.label for="template-subject" required>Subject</x-form.label>
                        <x-form.input id="template-subject" wire:model="subject" :invalid="$errors->has('subject')" />
                        <x-form.error for="subject" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-form.label for="template-body" required>Body</x-form.label>
                        <textarea
                            id="template-body"
                            wire:model="body"
                            rows="10"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        ></textarea>
                        <x-form.error for="body" />
                    </div>
                </div>

                @if ($this->unknownFields() !== [])
                    <x-alert variant="error">
                        These fields are not available for a {{ strtolower($modules[$module] ?? $module) }} and will come out blank:
                        {{ implode(', ', $this->unknownFields()) }}.
                    </x-alert>
                @endif

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Available fields</h3>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($this->mergeFields() as $example => $label)
                            <span class="rounded bg-muted px-2 py-1 font-mono text-xs text-muted-foreground" title="{{ $label }}">{{ $example }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap gap-5">
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="is_active" class="rounded border-border text-accent focus:ring-accent/40">
                        Active
                    </label>
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="track_opens" class="rounded border-border text-accent focus:ring-accent/40">
                        Track opens
                    </label>
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="track_clicks" class="rounded border-border text-accent focus:ring-accent/40">
                        Track clicks
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <x-button type="submit">{{ $editing === null ? 'Create template' : 'Save changes' }}</x-button>
                    <x-button type="button" variant="secondary" wire:click="previewDraft">Preview</x-button>
                    <button type="button" wire:click="cancel" class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Cancel
                    </button>
                </div>

                @if ($preview !== null)
                    <div class="rounded-lg border border-border bg-background p-4">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Preview, with nothing merged in</h3>
                        {{-- Escaped: a template is authored text, and the point of
                             the preview is to see the wording, not to run it. --}}
                        <pre class="whitespace-pre-wrap break-words text-sm text-foreground">{{ $preview }}</pre>
                    </div>
                @endif
            </form>
        @elseif ($this->canUpdate())
            <div class="mb-4">
                <x-button type="button" wire:click="create">New template</x-button>
            </div>
        @endif

        <div class="rounded-xl border border-border bg-card">
            @if ($templates->isEmpty())
                <x-empty-state
                    icon="mail-plus"
                    heading="No templates yet"
                    description="A template saves rewriting the same message for every customer."
                />
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Name</th>
                            <th scope="col" class="px-4 py-3 font-medium">About</th>
                            <th scope="col" class="px-4 py-3 font-medium">Subject</th>
                            <th scope="col" class="px-4 py-3 font-medium">Tracking</th>
                            <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($templates as $template)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="font-medium text-foreground">{{ $template->name }}</span>
                                    @unless ($template->is_active)
                                        <span class="ml-2 text-xs text-muted-foreground">(inactive)</span>
                                    @endunless
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $template->moduleLabel() }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $template->getAttributeValue('subject') }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ implode(', ', array_filter([
                                        $template->track_opens ? 'opens' : null,
                                        $template->track_clicks ? 'clicks' : null,
                                    ])) ?: '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($this->canUpdate())
                                        <button type="button" wire:click="edit({{ $template->id }})" class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground">
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="delete({{ $template->id }})"
                                            wire:confirm="Delete this template?"
                                            class="ml-3 text-sm text-destructive underline underline-offset-4"
                                        >
                                            Delete
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </x-settings-shell>
</div>
