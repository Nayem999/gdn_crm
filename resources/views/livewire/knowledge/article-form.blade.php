<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('knowledge.index') }}" wire:navigate class="hover:text-foreground">Knowledge base</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $this->isEditing() ? 'Editing' : 'A new article' }}</span>
    </nav>

    <h1 class="mb-6 text-2xl font-semibold text-foreground">
        {{ $this->isEditing() ? 'Edit article' : 'New article' }}
    </h1>

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="space-y-4">
                <div>
                    <label for="title" class="mb-1 block text-sm font-medium text-foreground">Title</label>
                    <input id="title" type="text" wire:model="title"
                           placeholder="What somebody would call the problem"
                           class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                    @error('title') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>

                <div wire:key="kb-category">
                    <x-select
                        name="kb_category_id"
                        label="Section"
                        :options="$this->categoryOptions()"
                        :selected="$kb_category_id"
                        placeholder="Unfiled"
                        clearable
                        wire:model="kb_category_id"
                        :error="$errors->first('kb_category_id')"
                    />
                </div>

                <div>
                    <label for="excerpt" class="mb-1 block text-sm font-medium text-foreground">Summary</label>
                    <textarea id="excerpt" wire:model="excerpt" rows="2"
                              placeholder="The one line shown in a search result"
                              class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                    @error('excerpt') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="body" class="mb-1 block text-sm font-medium text-foreground">The answer</label>
                    <textarea id="body" wire:model="body" rows="16"
                              class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm leading-relaxed text-foreground focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                    @error('body') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="keywords" class="mb-1 block text-sm font-medium text-foreground">Search words</label>
                    <input id="keywords" type="text" wire:model="keywords"
                           placeholder="won't turn on, dead, no power"
                           class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                    <p class="mt-1 text-sm text-muted-foreground">
                        The words a customer types that this article does not contain. Weighted almost as highly as the
                        title, because they are what people actually search for.
                    </p>
                    @error('keywords') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                Save article
            </button>
            <a href="{{ route('knowledge.index') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                Cancel
            </a>
            <p class="text-sm text-muted-foreground">
                Saving leaves it a draft. Publishing happens on the article itself, with the text in front of you.
            </p>
        </div>
    </form>
</div>
