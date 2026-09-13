<div>
    <x-settings-shell
        heading="Field mapping"
        :description="'Where each field on a ' . \Illuminate\Support\Str::singular($source->targetLabel()) . ' gets its value from, for ' . $source->name . '.'"
        active="settings.data-sources"
    >
        <div
            x-data="{ message: '', tone: 'success' }"
            x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
            x-show="message"
            x-cloak
            x-transition
            class="mb-4"
        >
            <template x-if="tone === 'error'">
                <x-alert variant="error"><span x-text="message"></span></x-alert>
            </template>
            <template x-if="tone !== 'error'">
                <x-alert variant="success"><span x-text="message"></span></x-alert>
            </template>
        </div>

        <nav class="mb-4 text-sm">
            <a href="{{ route('settings.data-sources') }}" wire:navigate class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                &larr; Back to data sources
            </a>
        </nav>

        {{-- The sample. Everything else on this screen is built around it,
             because mapping against somebody's documentation is mapping against
             what their documentation says they send. --}}
        <section class="mb-6 rounded-xl border border-border bg-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-foreground">A real payload</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        @if ($source->isListening())
                            Listening until {{ $source->listening_until?->format('H:i') }} &mdash; send one delivery from the other system.
                        @elseif ($source->hasSample())
                            Captured {{ $source->sample_captured_at?->format('j M Y, H:i') }}.
                        @else
                            None yet. Turn listening on, then make the other system send one.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    @if ($source->isListening())
                        <button type="button" wire:click="stopListening" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                            Stop listening
                        </button>
                    @else
                        <x-button type="button" wire:click="listen" wire:loading.attr="disabled" wire:target="listen">
                            <x-icon name="lucide-antenna" class="h-4 w-4" />
                            Listen for a payload
                        </x-button>
                    @endif
                </div>
            </div>

            @if ($source->hasSample())
                @php($paths = $this->samplePaths())

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">What arrived</p>
                        <pre class="max-h-72 overflow-auto rounded-lg bg-background p-3 font-mono text-xs text-foreground">{{ $source->sample_payload }}</pre>
                    </div>

                    <div>
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            {{ count($paths) }} {{ \Illuminate\Support\Str::plural('path', count($paths)) }} in it
                        </p>
                        <div class="max-h-72 overflow-auto rounded-lg border border-border">
                            <ul class="divide-y divide-border">
                                @foreach ($paths as $path)
                                    <li class="px-3 py-1.5 font-mono text-xs text-muted-foreground">{{ $path }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        {{-- The mapping itself. --}}
        <form wire:submit="save" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-foreground">Mapping</h2>

                <div class="flex flex-wrap items-center gap-3 text-sm">
                    @if ($source->hasSample())
                        <button type="button" wire:click="suggest" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                            Suggest from the sample
                        </button>
                    @endif
                    <button type="button" wire:click="addRow" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Add a row
                    </button>
                </div>
            </div>

            @if ($rows === [])
                <div class="rounded-xl border border-border bg-card">
                    <x-empty-state
                        icon="git-branch"
                        heading="Nothing is mapped yet"
                        description="Until a field is mapped, a delivery arrives, is recorded, and makes nothing."
                    />
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($rows as $index => $row)
                        <div class="rounded-xl border border-border bg-card p-4" wire:key="row-{{ $index }}-{{ $row['id'] ?? 'new' }}">
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                                <div class="lg:col-span-4">
                                    <x-form.label :for="'path-' . $index" required>Payload path</x-form.label>
                                    <x-form.input
                                        :id="'path-' . $index"
                                        wire:model="rows.{{ $index }}.source_path"
                                        list="sample-paths"
                                        class="font-mono text-xs"
                                        placeholder="contact.email"
                                    />
                                    <x-form.error :for="'rows.' . $index . '.source_path'" />
                                </div>

                                <div class="lg:col-span-4">
                                    {{-- Keyed on the index so the control behind
                                         wire:ignore is rebuilt when a row is
                                         removed and the rest shift up. --}}
                                    <div wire:key="target-{{ $index }}-{{ count($rows) }}">
                                        <x-select
                                            :name="'rows.' . $index . '.target_field'"
                                            label="Goes into"
                                            :options="$this->targetOptions()"
                                            :selected="$row['target_field']"
                                            placeholder="Choose a field…"
                                            required
                                            :error="$errors->first('rows.' . $index . '.target_field')"
                                            wire:model="rows.{{ $index }}.target_field"
                                        />
                                    </div>
                                </div>

                                <div class="lg:col-span-3">
                                    <div wire:key="transform-{{ $index }}-{{ count($rows) }}">
                                        <x-select
                                            :name="'rows.' . $index . '.transform'"
                                            label="Transform"
                                            :options="$this->transformOptions()"
                                            :selected="$row['transform']"
                                            placeholder="None"
                                            clearable
                                            wire:model="rows.{{ $index }}.transform"
                                        />
                                    </div>
                                </div>

                                <div class="flex items-end lg:col-span-1">
                                    <button
                                        type="button"
                                        wire:click="removeRow({{ $index }})"
                                        class="pb-2 text-sm text-destructive underline underline-offset-4"
                                    >Remove</button>
                                </div>

                                <div class="lg:col-span-4">
                                    <x-form.label :for="'default-' . $index">If it is missing, use</x-form.label>
                                    <x-form.input :id="'default-' . $index" wire:model="rows.{{ $index }}.default_value" placeholder="(leave empty)" />
                                </div>

                                <div class="flex items-end lg:col-span-8">
                                    <label class="flex items-center gap-2 pb-2 text-sm text-foreground">
                                        <input type="checkbox" wire:model="rows.{{ $index }}.is_required" class="rounded border-border text-accent focus:ring-accent/40">
                                        Required &mdash; a delivery without it fails rather than making a half record
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <datalist id="sample-paths">
                    @foreach ($this->samplePaths() as $path)
                        <option value="{{ $path }}"></option>
                    @endforeach
                </datalist>
            @endif

            <div class="flex items-center gap-3">
                <x-button type="submit" wire:loading.attr="disabled" wire:target="save">Save mapping</x-button>
            </div>
        </form>

        {{-- The dry run. Runs the real mapper and the real rules, and stops
             before the write. --}}
        <section class="mt-8 rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">Test it</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Runs the saved mapping against a payload and shows what it would produce. Nothing is created.
            </p>

            <div class="mt-3">
                <x-form.label for="test-payload">Payload to test</x-form.label>
                <textarea
                    id="test-payload"
                    wire:model="testPayload"
                    rows="4"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 font-mono text-xs text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    placeholder="Leave empty to use the captured sample"
                ></textarea>
            </div>

            <div class="mt-3">
                <x-button type="button" variant="secondary" wire:click="dryRun" wire:loading.attr="disabled" wire:target="dryRun">
                    Run the test
                </x-button>
            </div>

            @if ($dryRunErrors !== [])
                <div class="mt-4 space-y-1">
                    @foreach ($dryRunErrors as $error)
                        <p class="text-xs text-destructive">{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if ($dryRunResult !== null)
                <div class="mt-4">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        The {{ \Illuminate\Support\Str::singular(strtolower($source->targetLabel())) }} it would make
                    </p>

                    @if ($dryRunResult === [])
                        <p class="text-xs text-muted-foreground">Nothing — no mapped field found a value in that payload.</p>
                    @else
                        <div class="overflow-hidden rounded-lg border border-border">
                            <table class="min-w-full text-sm">
                                <tbody class="divide-y divide-border">
                                    @foreach ($dryRunResult as $field => $value)
                                        <tr>
                                            <td class="w-1/3 bg-muted/40 px-3 py-1.5 font-mono text-xs text-muted-foreground">{{ $field }}</td>
                                            <td class="px-3 py-1.5 text-foreground">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </section>
    </x-settings-shell>
</div>
