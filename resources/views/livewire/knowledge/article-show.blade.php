<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('knowledge.index') }}" wire:navigate class="hover:text-foreground">Knowledge base</a>
        @if ($article->category)
            <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
            <span>{{ $article->category->path() }}</span>
        @endif
    </nav>

    <div
        x-data="{ message: '' }"
        x-on:notify.window="message = $event.detail.message; setTimeout(() => message = '', 4000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    @unless ($article->isPublished())
        {{-- Said plainly at the top: an agent reading down the page and quoting
             it to a customer must know it is not finished. --}}
        <x-alert variant="error" class="mb-4">
            This article is {{ strtolower($article->status()->label()) }} and is not visible to anybody without editing rights.
        </x-alert>
    @endunless

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">{{ $article->title }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                {!! \App\Domain\Shared\UI\ChipPalette::chip($article->status()->label(), $article->status()->color()) !!}
                @if ($article->author)
                    <span>by {{ $article->author->name }}</span>
                @endif
                @if ($article->published_at)
                    <span>&middot; published {{ \App\Domain\Settings\DisplayTime::display($article->published_at)->diffForHumans() }}</span>
                @endif
                <span>&middot; {{ number_format($article->view_count) }} {{ \Illuminate\Support\Str::plural('view', $article->view_count) }}</span>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->canEdit())
                <a href="{{ route('knowledge.edit', $article->slug) }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    <x-icon name="lucide-pencil" class="h-4 w-4" />
                    Edit
                </a>
            @endif

            @if ($this->canPublish())
                @if ($article->isPublished())
                    <button type="button" wire:click="unpublish"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                        <x-icon name="lucide-eye-off" class="h-4 w-4" />
                        Unpublish
                    </button>
                    <button type="button" wire:click="archive"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-muted-foreground transition-colors hover:bg-muted">
                        Archive
                    </button>
                @else
                    <button type="button" wire:click="publish"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                        <x-icon name="lucide-send" class="h-4 w-4" />
                        Publish
                    </button>
                @endif
            @endif

            @can('delete', $article)
                <button type="button" wire:click="delete"
                        wire:confirm="Remove this article? It is kept on the record."
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-destructive transition-colors hover:bg-destructive/10">
                    <x-icon name="lucide-trash-2" class="h-4 w-4" />
                    Remove
                </button>
            @endcan
        </div>
    </div>

    <article class="rounded-xl border border-border bg-card p-5 sm:p-8">
        @if ($article->excerpt)
            <p class="mb-4 border-l-2 border-accent pl-4 text-base text-muted-foreground">{{ $article->excerpt }}</p>
        @endif

        {{-- Escaped, always. An article is written by a colleague, not by a
             developer, and a knowledge base that rendered HTML would be a
             stored-XSS hole with an editor attached. --}}
        <div class="whitespace-pre-line text-sm leading-relaxed text-foreground">{{ $article->body }}</div>
    </article>

    <div class="mt-6 rounded-xl border border-border bg-card p-5 text-center">
        @if ($answered)
            <p class="text-sm text-muted-foreground">Thank you.</p>
        @else
            <p class="text-sm font-medium text-foreground">Did this answer the question?</p>
            <div class="mt-3 flex items-center justify-center gap-3">
                <button type="button" wire:click="markHelpful(true)"
                        class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-medium text-foreground transition-colors hover:border-accent hover:text-accent">
                    <x-icon name="lucide-thumbs-up" class="h-4 w-4" />
                    Yes
                </button>
                <button type="button" wire:click="markHelpful(false)"
                        class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-medium text-foreground transition-colors hover:border-destructive hover:text-destructive">
                    <x-icon name="lucide-thumbs-down" class="h-4 w-4" />
                    No
                </button>
            </div>
        @endif

        @if ($article->helpfulness() !== null)
            <p class="mt-3 text-xs text-muted-foreground">
                {{ $article->helpfulness() }}% of {{ $article->votes() }}
                {{ \Illuminate\Support\Str::plural('reader', $article->votes()) }} found this helpful.
            </p>
        @endif
    </div>
</div>
