<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300">
                <x-icon name="lucide-book-open" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Knowledge base</h1>
                <p class="mt-1 text-sm text-muted-foreground">What we already know, written down once.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->canEdit())
                <a href="{{ route('knowledge.sections') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    <x-icon name="lucide-folder-tree" class="h-4 w-4" />
                    Sections
                </a>
            @endif

            @can('create', App\Domain\Knowledge\Models\Article::class)
                <a href="{{ route('knowledge.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    New article
                </a>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="mb-6">
        <label for="kb-search" class="sr-only">Search the knowledge base</label>
        <div class="relative">
            <x-icon name="lucide-search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input
                id="kb-search"
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="What are you looking for?"
                class="block w-full rounded-lg border border-border bg-background py-2.5 pl-10 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent"
            >
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-4">
        <aside class="lg:col-span-1">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Sections</h2>
            <ul class="space-y-1 text-sm">
                <li>
                    <button type="button" wire:click="openSection(null)"
                            @class([
                                'w-full rounded-lg px-3 py-1.5 text-left transition-colors',
                                'bg-accent/10 font-medium text-accent' => $categoryId === null,
                                'text-muted-foreground hover:text-foreground' => $categoryId !== null,
                            ])>
                        Everything
                    </button>
                </li>
                @foreach ($this->sections as $section)
                    <li>
                        <button type="button" wire:click="openSection({{ $section->id }})"
                                @class([
                                    'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-left transition-colors',
                                    'bg-accent/10 font-medium text-accent' => $categoryId === $section->id,
                                    'text-muted-foreground hover:text-foreground' => $categoryId !== $section->id,
                                ])>
                            <span>{{ $section->name }}</span>
                            <span class="text-xs tabular-nums">{{ $section->articles_count }}</span>
                        </button>

                        @if ($section->children->isNotEmpty())
                            <ul class="ml-3 mt-1 space-y-1 border-l border-border pl-2">
                                @foreach ($section->children as $child)
                                    <li>
                                        <button type="button" wire:click="openSection({{ $child->id }})"
                                                @class([
                                                    'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-1 text-left text-xs transition-colors',
                                                    'bg-accent/10 font-medium text-accent' => $categoryId === $child->id,
                                                    'text-muted-foreground hover:text-foreground' => $categoryId !== $child->id,
                                                ])>
                                            <span>{{ $child->name }}</span>
                                            <span class="tabular-nums">{{ $child->articles_count }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </aside>

        <div class="lg:col-span-3">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    @if ($this->isSearching())
                        {{ $this->results->count() }} {{ \Illuminate\Support\Str::plural('result', $this->results->count()) }}
                        for &ldquo;{{ $this->search }}&rdquo;
                        @if ($this->category()) in {{ $this->category()->name }} @endif
                    @elseif ($this->category())
                        {{ $this->category()->path() }}
                    @else
                        Most recently published
                    @endif
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($this->canEdit())
                        {{-- Only offered to somebody who can see drafts at all;
                             for everybody else the list is published articles
                             and the control would filter nothing. --}}
                        <div wire:key="kb-status-filter" class="w-48">
                            <x-select
                                name="statusFilter"
                                :options="$this->statusOptions()"
                                :selected="$statusFilter"
                                placeholder="Any status"
                                clearable
                                wire:model.live="statusFilter"
                            />
                        </div>
                    @endif

                    @if ($this->isSearching() || $statusFilter !== '')
                        <button type="button" wire:click="clearSearch"
                                class="text-xs font-medium text-muted-foreground hover:text-destructive">
                            Clear
                        </button>
                    @endif
                </div>
            </div>

            @if ($this->results->isEmpty())
                <div class="rounded-xl border border-dashed border-border p-10 text-center">
                    <x-icon name="lucide-search-x" class="mx-auto h-8 w-8 text-muted-foreground" />
                    <h2 class="mt-3 text-base font-semibold text-foreground">
                        {{ $this->isSearching() ? 'Nothing matched' : 'Nothing here yet' }}
                    </h2>
                    <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                        @if ($this->isSearching())
                            Try fewer words, or the words a customer would use &mdash; an article can carry those as
                            search words even when it does not say them.
                        @else
                            Write down an answer once and everybody has it.
                        @endif
                    </p>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach ($this->results as $article)
                        <li class="rounded-xl border border-border bg-card p-4 transition-colors hover:border-accent/50">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('knowledge.show', $article->slug) }}" wire:navigate
                                       class="text-base font-semibold text-foreground hover:text-accent hover:underline">
                                        {{ $article->title }}
                                    </a>
                                    <p class="mt-1 text-sm text-muted-foreground">{{ $article->summary() }}</p>
                                    <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        @if ($article->category)
                                            <span>{{ $article->category->path() }}</span>
                                            <span>&middot;</span>
                                        @endif
                                        @unless ($article->isPublished())
                                            {!! \App\Domain\Shared\UI\ChipPalette::chip($article->status()->label(), $article->status()->color()) !!}
                                        @endunless
                                        @if ($article->helpfulness() !== null)
                                            <span>{{ $article->helpfulness() }}% of {{ $article->votes() }} found this helpful</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
