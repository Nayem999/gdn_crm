<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('knowledge.index') }}" wire:navigate class="hover:text-foreground">Knowledge base</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">Sections</span>
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

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">Sections</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                One level of nesting: a section and its subsections. Deeper than that and nobody finds anything.
            </p>
        </div>

        @can('create', App\Domain\Knowledge\Models\Category::class)
            <button type="button" wire:click="create"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                <x-icon name="lucide-plus" />
                New section
            </button>
        @endcan
    </div>

    @if ($editing)
        <form wire:submit="save" class="mb-6 space-y-4 rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">
                {{ $editingId === null ? 'A new section' : 'Editing this section' }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="section-name" class="mb-1 block text-sm font-medium text-foreground">Name</label>
                    <input id="section-name" type="text" wire:model="name"
                           class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                    @error('name') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>

                <div wire:key="section-parent-{{ $editingId ?? 'new' }}">
                    <x-select
                        name="parentId"
                        label="Inside"
                        :options="$this->parentOptions()"
                        :selected="$parentId"
                        placeholder="Nothing — a top-level section"
                        clearable
                        wire:model="parentId"
                        :error="$errors->first('parentId')"
                    />
                </div>
            </div>

            <div>
                <label for="section-description" class="mb-1 block text-sm font-medium text-foreground">Description</label>
                <input id="section-description" type="text" wire:model="description"
                       class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                @error('description') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                    Save section
                </button>
                <button type="button" wire:click="cancel"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    @if ($this->sections->isEmpty())
        <div class="rounded-xl border border-dashed border-border p-10 text-center">
            <x-icon name="lucide-folder-tree" class="mx-auto h-8 w-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold text-foreground">No sections yet</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                Articles work perfectly well unfiled &mdash; search finds them either way. Sections are for browsing.
            </p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($this->sections as $section)
                <li class="rounded-xl border border-border bg-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-foreground">{{ $section->name }}</h2>
                            @if ($section->description)
                                <p class="mt-1 text-sm text-muted-foreground">{{ $section->description }}</p>
                            @endif
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ $section->articles_count }}
                                {{ \Illuminate\Support\Str::plural('article', $section->articles_count) }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @can('update', $section)
                                <button type="button" wire:click="edit({{ $section->id }})"
                                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-muted">
                                    Edit
                                </button>
                            @endcan
                            @can('delete', $section)
                                <button type="button" wire:click="delete({{ $section->id }})"
                                        wire:confirm="Remove this section? Its articles stay, unfiled."
                                        class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-destructive transition-colors hover:bg-destructive/10">
                                    Remove
                                </button>
                            @endcan
                        </div>
                    </div>

                    @if ($section->children->isNotEmpty())
                        <ul class="mt-3 space-y-2 border-l border-border pl-4">
                            @foreach ($section->children as $child)
                                <li class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm text-foreground">
                                        {{ $child->name }}
                                        <span class="ml-1 text-xs text-muted-foreground">
                                            {{ $child->articles_count }}
                                        </span>
                                    </span>
                                    <span class="flex items-center gap-2">
                                        @can('update', $child)
                                            <button type="button" wire:click="edit({{ $child->id }})"
                                                    class="text-xs font-medium text-muted-foreground hover:text-foreground">
                                                Edit
                                            </button>
                                        @endcan
                                        @can('delete', $child)
                                            <button type="button" wire:click="delete({{ $child->id }})"
                                                    wire:confirm="Remove this section? Its articles stay, unfiled."
                                                    class="text-xs font-medium text-muted-foreground hover:text-destructive">
                                                Remove
                                            </button>
                                        @endcan
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
