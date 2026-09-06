@props([
    'entry',
    'canEdit' => false,
    'canDelete' => false,
    'last' => false,
])

@php
    use App\Domain\Audit\ActivityPresenter;
    use App\Domain\Shared\UI\ChipPalette;
@endphp

<li class="relative flex gap-3 pb-6 last:pb-0">
    {{-- The rail joining one entry to the next. Dropped on the last one so the
         line stops at the final marker rather than trailing into nothing. --}}
    @unless ($last)
        <span class="absolute left-4 top-9 -ml-px h-[calc(100%-1.5rem)] w-px bg-border" aria-hidden="true"></span>
    @endunless

    <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-card {{ ChipPalette::classes($entry->color) }}">
        <x-icon :name="$entry->kind->icon()" class="h-4 w-4" />
        <span class="sr-only">{{ $entry->kind->label() }}</span>
    </span>

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p class="text-sm font-medium text-foreground">{{ $entry->title }}</p>

            <time
                datetime="{{ $entry->occurredAt->toIso8601String() }}"
                title="{{ $entry->occurredAt->format('j M Y, H:i') }}"
                class="shrink-0 text-xs text-muted-foreground"
            >{{ $entry->occurredAt->diffForHumans() }}</time>
        </div>

        @if ($entry->isNote())
            <div class="mt-2 rounded-lg border border-border bg-muted/40 p-3">
                <p class="whitespace-pre-line text-sm text-foreground">{{ $entry->body }}</p>

                @if ($entry->note?->wasEdited())
                    <p class="mt-2 text-xs text-muted-foreground">
                        Edited {{ $entry->note->updated_at?->diffForHumans() }}
                    </p>
                @endif
            </div>

            @if ($canEdit || $canDelete)
                <div class="mt-1.5 flex items-center gap-3">
                    @if ($canEdit)
                        <button
                            type="button"
                            wire:click="editNote({{ $entry->id }})"
                            class="text-xs font-medium text-muted-foreground hover:text-foreground"
                        >Edit</button>
                    @endif

                    @if ($canDelete)
                        <button
                            type="button"
                            wire:click="deleteNote({{ $entry->id }})"
                            wire:confirm="Remove this note?"
                            wire:loading.attr="disabled"
                            class="text-xs font-medium text-muted-foreground hover:text-destructive"
                        >Remove</button>
                    @endif
                </div>
            @endif
        @elseif ($entry->isDocument())
            <div class="mt-2 flex flex-wrap items-center gap-3 rounded-lg border border-border p-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <x-icon :name="$entry->document?->icon() ?? 'lucide-file'" class="h-4 w-4" />
                </span>

                <div class="min-w-0 flex-1">
                    <a
                        href="{{ route('documents.download', $entry->id) }}"
                        class="block truncate text-sm font-medium text-accent hover:underline"
                    >{{ $entry->document?->title }}</a>

                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ $entry->document?->readableSize() }}
                        @if ($entry->document?->fileName())
                            &middot; {{ $entry->document->fileName() }}
                        @endif
                    </p>
                </div>

                @if ($canDelete)
                    <button
                        type="button"
                        wire:click="deleteDocument({{ $entry->id }})"
                        wire:confirm="Remove this document? The file goes with it."
                        wire:loading.attr="disabled"
                        class="shrink-0 text-xs font-medium text-muted-foreground hover:text-destructive"
                    >Remove</button>
                @endif
            </div>

            @if ($entry->body)
                <p class="mt-1.5 text-sm text-muted-foreground">{{ $entry->body }}</p>
            @endif
        @elseif ($entry->changes !== [])
            <dl class="mt-2 space-y-1">
                @foreach ($entry->changes as $attribute => $change)
                    <div class="flex flex-wrap items-baseline gap-x-2 text-xs">
                        <dt class="text-muted-foreground">{{ str($attribute)->headline() }}</dt>
                        <dd class="flex flex-wrap items-baseline gap-x-1.5 text-foreground">
                            <span class="text-muted-foreground line-through">{{ ActivityPresenter::value($change['old']) }}</span>
                            <x-icon name="lucide-arrow-right" class="h-3 w-3 text-muted-foreground" />
                            <span class="font-medium">{{ ActivityPresenter::value($change['new']) }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($entry->actor && ! $entry->isNote())
            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                <x-avatar :user="$entry->actor" size="sm" class="!h-4 !w-4 !text-[9px]" />
                {{ $entry->actor->name }}
            </p>
        @endif
    </div>
</li>
