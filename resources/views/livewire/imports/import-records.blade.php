@php
    use App\Domain\Shared\Imports\ImportStatus;
    use App\Livewire\Imports\ImportRecords;

    $preview = $step === ImportRecords::STEP_MAP ? $this->preview() : null;
    $missing = $this->missingRequired();
    $duplicated = $this->duplicatedFields();
@endphp

<div>
    {{-- The poll lives on its own element, never in the root tag's attributes:
         Livewire wraps every @if in HTML block comments, and inside a tag that
         produces markup the Blade compiler cannot parse. --}}
    @if ($run && ! $run->status()->isSettled())
        <div wire:poll.2s></div>
    @endif

    <div
        x-data="{ message: '' }"
        x-on:notify.window="message = $event.detail.message; setTimeout(() => message = '', 6000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="error"><span x-text="message"></span></x-alert>
    </div>

    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ $source->indexRoute() }}" wire:navigate class="hover:text-foreground">{{ $source->label() }}</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">Import</span>
    </nav>

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-foreground">Import {{ strtolower($source->label()) }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            A CSV or spreadsheet with a heading row. Nothing is written until you have seen what will happen.
        </p>
    </div>

    {{-- Step one --------------------------------------------------------------}}
    @if ($step === ImportRecords::STEP_UPLOAD)
        <form wire:submit="readFile" class="space-y-6">
            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">The file</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    CSV, or an Excel file up to {{ number_format(\App\Domain\Shared\Imports\ImportReader::SPREADSHEET_ROW_LIMIT) }} rows. 10&nbsp;MB at most.
                </p>

                <div class="mt-4 max-w-lg">
                    <x-form.label for="import-file" required>Choose a file</x-form.label>
                    <input
                        id="import-file"
                        type="file"
                        wire:model="file"
                        accept=".csv,.txt,.xlsx,.xls"
                        class="block w-full text-sm text-foreground file:mr-4 file:rounded-lg file:border-0 file:bg-accent file:px-4 file:py-2 file:text-sm file:font-semibold file:text-accent-foreground hover:file:bg-accent/90"
                    />
                    <x-form.error for="file" />

                    <div wire:loading wire:target="file" class="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                        Reading the file
                    </div>
                </div>

                <div class="mt-5 rounded-lg border border-border bg-muted/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">What a row can carry</p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {{ implode(', ', array_map(fn ($f) => $f->label, $source->fields())) }}.
                        Required: {{ implode(', ', array_map(fn ($f) => $f->label, $source->requiredFields())) }}.
                    </p>
                </div>
            </section>

            <x-button type="submit" wire:loading.attr="disabled" wire:target="readFile">
                <x-icon name="lucide-arrow-right" />
                Continue
            </x-button>
        </form>
    @endif

    {{-- Step two --------------------------------------------------------------}}
    @if ($step === ImportRecords::STEP_MAP)
        <section class="mb-6 rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">Which column is which?</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ $originalFilename }} &middot; {{ count($headers) }} {{ Str::plural('column', count($headers)) }}.
                        Headings that matched a field are already set.
                    </p>
                </div>

                <x-button type="button" variant="secondary" wire:click="startOver">
                    <x-icon name="lucide-rotate-ccw" />
                    Use a different file
                </x-button>
            </div>

            <ul class="mt-5 grid gap-4 sm:grid-cols-2">
                @foreach ($headers as $index => $heading)
                    <li wire:key="column-{{ $index }}">
                        <div wire:key="column-select-{{ $index }}-{{ $mapping[$index] ?? '' }}">
                            <x-select
                                name="mapping.{{ $index }}"
                                :label="$heading !== '' ? $heading : 'Column '.($index + 1)"
                                :options="$fields"
                                :selected="$mapping[$index] ?? ''"
                                wire:model.live="mapping.{{ $index }}"
                            />
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($missing !== [] || $duplicated !== [])
            <x-alert variant="error" class="mb-6">
                @if ($missing !== [])
                    <p>Map a column to {{ implode(' and ', $missing) }} before importing.</p>
                @endif
                @if ($duplicated !== [])
                    <p>{{ implode(' and ', $duplicated) }} {{ count($duplicated) === 1 ? 'is' : 'are' }} mapped to more than one column.</p>
                @endif
            </x-alert>
        @endif

        @if ($missing === [] && $duplicated === [] && $preview)
            <section class="mb-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">What will happen</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        'Rows in the file' => [$preview['total'], 'text-foreground'],
                        'Will be imported' => [$preview['valid'], 'text-emerald-600 dark:text-emerald-400'],
                        'Will be refused' => [$preview['total'] - $preview['valid'], 'text-destructive'],
                    ] as $label => $figure)
                        <div class="rounded-lg border border-border p-4">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</p>
                            <p class="mt-1 text-2xl font-semibold {{ $figure[1] }}">{{ number_format($figure[0]) }}</p>
                        </div>
                    @endforeach
                </div>

                @if ($preview['errors'] !== [])
                    <p class="mt-5 text-sm font-medium text-foreground">Why rows will be refused</p>

                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full min-w-[32rem] text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th class="w-20 py-2 pr-3 font-semibold">Row</th>
                                    <th class="py-2 pr-3 font-semibold">Problem</th>
                                    <th class="py-2 font-semibold">Starts with</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['errors'] as $error)
                                    <tr class="border-b border-border/60 last:border-0" wire:key="preview-error-{{ $error->row }}">
                                        <td class="py-2 pr-3 text-muted-foreground">{{ $error->row }}</td>
                                        <td class="py-2 pr-3 text-foreground">{{ $error->joined() }}</td>
                                        <td class="py-2 text-muted-foreground">{{ $error->summary }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if (($preview['total'] - $preview['valid']) > count($preview['errors']))
                        <p class="mt-3 text-xs text-muted-foreground">
                            Showing the first {{ count($preview['errors']) }}. The rest are in the report after importing.
                        </p>
                    @endif
                @endif

                @if ($preview['valid'] > ImportRecords::QUEUE_THRESHOLD)
                    <p class="mt-4 text-sm text-muted-foreground">
                        That is more than {{ number_format(ImportRecords::QUEUE_THRESHOLD) }} rows, so it will run in the
                        background and you will be told when it is done.
                    </p>
                @endif
            </section>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            {{-- :disabled, not @disabled: the directive compiles to raw PHP
                 inside the component tag, which the component compiler then
                 cannot parse. --}}
            <x-button type="button" wire:click="import" wire:loading.attr="disabled" :disabled="! $this->canImport()">
                <x-icon name="lucide-upload" />
                Import {{ $preview ? number_format($preview['valid']).' '.Str::plural('row', $preview['valid']) : 'rows' }}
            </x-button>

            <a href="{{ $source->indexRoute() }}" wire:navigate class="text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </a>
        </div>
    @endif

    {{-- Step three ------------------------------------------------------------}}
    @if ($step === ImportRecords::STEP_RESULT && $run)
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-foreground">{{ $run->original_filename }}</h2>
                <x-status-chip :color="$run->status()->color()" dot>{{ $run->status()->label() }}</x-status-chip>
            </div>

            @if ($run->status() === ImportStatus::Failed)
                <x-alert variant="error" class="mt-4">
                    {{ $run->failure_reason ?? 'The import could not be finished.' }}
                </x-alert>
            @elseif (! $run->status()->isSettled())
                <p class="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                    Working through the file. This page keeps itself up to date.
                </p>
            @else
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        'Rows read' => [$run->total_rows, 'text-foreground'],
                        'Imported' => [$run->imported_rows, 'text-emerald-600 dark:text-emerald-400'],
                        'Refused' => [$run->failed_rows, 'text-destructive'],
                    ] as $label => $figure)
                        <div class="rounded-lg border border-border p-4">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</p>
                            <p class="mt-1 text-2xl font-semibold {{ $figure[1] }}">{{ number_format($figure[0]) }}</p>
                        </div>
                    @endforeach
                </div>

                @if ($run->rowErrors() !== [])
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm font-medium text-foreground">
                            Rows that were refused
                            @if ($run->errorsTruncated())
                                <span class="font-normal text-muted-foreground">
                                    &mdash; the first {{ count($run->rowErrors()) }} of {{ number_format($run->failed_rows) }}
                                </span>
                            @endif
                        </p>

                        <x-button type="button" variant="secondary" wire:click="downloadErrors">
                            <x-icon name="lucide-download" />
                            Download the report
                        </x-button>
                    </div>

                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full min-w-[32rem] text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th class="w-20 py-2 pr-3 font-semibold">Row</th>
                                    <th class="py-2 pr-3 font-semibold">Problem</th>
                                    <th class="py-2 font-semibold">Starts with</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($run->rowErrors(), 0, 25) as $error)
                                    <tr class="border-b border-border/60 last:border-0" wire:key="run-error-{{ $error->row }}">
                                        <td class="py-2 pr-3 text-muted-foreground">{{ $error->row }}</td>
                                        <td class="py-2 pr-3 text-foreground">{{ $error->joined() }}</td>
                                        <td class="py-2 text-muted-foreground">{{ $error->summary }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <a href="{{ $source->indexRoute() }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-accent-foreground hover:bg-accent/90">
                    <x-icon name="lucide-arrow-right" />
                    Go to {{ strtolower($source->label()) }}
                </a>

                <x-button type="button" variant="secondary" wire:click="startOver">
                    <x-icon name="lucide-upload" />
                    Import another file
                </x-button>
            </div>
        </section>
    @endif
</div>
