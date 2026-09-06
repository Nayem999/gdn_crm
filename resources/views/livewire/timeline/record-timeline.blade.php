<section class="rounded-xl border border-border bg-card p-5 sm:p-6" aria-labelledby="timeline-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="timeline-heading" class="text-base font-semibold text-foreground">Timeline</h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                Everything written, attached and changed on this record.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canAttach)
                <x-button type="button" variant="secondary" wire:click="startAttaching" wire:loading.attr="disabled">
                    <x-icon name="lucide-paperclip" />
                    Attach
                </x-button>
            @endif
        </div>
    </div>

    {{-- Strand filters. Chips rather than a dropdown: there are three, they are
         multi-select, and the current state has to be readable at a glance. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2" role="group" aria-label="Filter the timeline">
        <button
            type="button"
            wire:click="showAllKinds"
            @class([
                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                'border-accent bg-accent text-accent-foreground' => $kinds === [],
                'border-border text-muted-foreground hover:bg-muted hover:text-foreground' => $kinds !== [],
            ])
            aria-pressed="{{ $kinds === [] ? 'true' : 'false' }}"
        >Everything</button>

        @foreach ($filterKinds as $kind)
            @php($selected = $this->isKindSelected($kind->value))
            <button
                type="button"
                wire:click="toggleKind('{{ $kind->value }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                    'border-accent bg-accent text-accent-foreground' => $selected,
                    'border-border text-muted-foreground hover:bg-muted hover:text-foreground' => ! $selected,
                ])
                aria-pressed="{{ $selected ? 'true' : 'false' }}"
            >
                <x-icon :name="$kind->icon()" class="h-3.5 w-3.5" />
                {{ $kind->pluralLabel() }}
                @if ($kind->value === 'note' && $page->noteCount > 0)
                    <span class="tabular-nums opacity-70">{{ $page->noteCount }}</span>
                @elseif ($kind->value === 'document' && $page->documentCount > 0)
                    <span class="tabular-nums opacity-70">{{ $page->documentCount }}</span>
                @endif
            </button>
        @endforeach

        <span wire:loading wire:target="toggleKind,showAllKinds,loadMore" class="ml-1 text-xs text-muted-foreground">
            Loading…
        </span>
    </div>

    {{-- The note composer, which doubles as the editor for an existing note. --}}
    @if ($canAddNote || $editingNoteId !== null)
        <form wire:submit="saveNote" class="mt-4">
            <label for="timeline-note-body" class="sr-only">
                {{ $editingNoteId === null ? 'Write a note' : 'Edit this note' }}
            </label>

            <textarea
                id="timeline-note-body"
                wire:model="body"
                rows="3"
                placeholder="{{ $editingNoteId === null ? 'Write a note about this record…' : 'Edit this note…' }}"
                @class([
                    'w-full rounded-lg border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground',
                    'focus:outline-none focus:ring-2',
                    'border-destructive focus:border-destructive focus:ring-destructive/40' => $errors->has('body'),
                    'border-border focus:border-accent focus:ring-accent/40' => ! $errors->has('body'),
                ])
            ></textarea>

            <x-form.error for="body" />

            <div class="mt-2 flex items-center gap-2">
                <x-button type="submit" wire:loading.attr="disabled" wire:target="saveNote">
                    <x-icon name="lucide-message-square-plus" />
                    {{ $editingNoteId === null ? 'Add note' : 'Save note' }}
                </x-button>

                @if ($editingNoteId !== null)
                    <x-button type="button" variant="secondary" wire:click="cancelNote">Cancel</x-button>
                @endif
            </div>
        </form>
    @endif

    {{-- The uploader, shown only once somebody asks for it: an always-open file
         field on a detail page is noise. --}}
    @if ($attaching && $canAttach)
        <form wire:submit="attachDocument" class="mt-4 rounded-lg border border-border p-4">
            <h3 class="text-sm font-semibold text-foreground">Attach a document</h3>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="timeline-upload">File</x-form.label>
                    <input
                        id="timeline-upload"
                        type="file"
                        wire:model="upload"
                        class="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-foreground"
                    >
                    <x-form.error for="upload" />

                    <p wire:loading wire:target="upload" class="mt-1 text-xs text-muted-foreground">Uploading…</p>
                </div>

                <div>
                    <x-form.label for="timeline-document-title">Title</x-form.label>
                    <x-form.input
                        id="timeline-document-title"
                        wire:model="documentTitle"
                        class="mt-1"
                        placeholder="Defaults to the file name"
                        :invalid="$errors->has('documentTitle')"
                    />
                    <x-form.error for="documentTitle" />
                </div>

                <div>
                    <x-form.label for="timeline-document-description">Description</x-form.label>
                    <x-form.input
                        id="timeline-document-description"
                        wire:model="documentDescription"
                        class="mt-1"
                        placeholder="What is it for?"
                        :invalid="$errors->has('documentDescription')"
                    />
                    <x-form.error for="documentDescription" />
                </div>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <x-button type="submit" wire:loading.attr="disabled" wire:target="attachDocument,upload">
                    <x-icon name="lucide-upload" />
                    Attach
                </x-button>

                <x-button type="button" variant="secondary" wire:click="cancelAttaching">Cancel</x-button>
            </div>
        </form>
    @endif

    <div class="mt-6" wire:loading.class="opacity-60" wire:target="toggleKind,showAllKinds,loadMore">
        @if ($page->isEmpty())
            <x-empty-state
                icon="history"
                heading="Nothing on the timeline yet"
                :filtered="$kinds !== []"
                :description="$kinds !== []
                    ? 'Nothing of that kind on this record yet.'
                    : ($canAddNote
                        ? 'Write the first note, or attach a document.'
                        : 'Notes, documents and changes will appear here.')"
            />
        @else
            <ol role="list">
                @foreach ($page->entries as $entry)
                    <x-timeline-entry
                        :key="$entry->kind->value.'-'.$entry->id"
                        :entry="$entry"
                        :can-edit="$entry->note !== null && auth()->user()?->can('update', $entry->note)"
                        :can-delete="($entry->note !== null && auth()->user()?->can('delete', $entry->note))
                            || ($entry->document !== null && auth()->user()?->can('delete', $entry->document))"
                        :last="$loop->last && ! $page->hasMore"
                    />
                @endforeach
            </ol>

            @if ($page->hasMore)
                <div class="mt-2 flex justify-center">
                    <x-button type="button" variant="secondary" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">
                        <x-icon name="lucide-chevron-down" />
                        Load more
                    </x-button>
                </div>
            @endif
        @endif
    </div>
</section>
