<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('tickets.index') }}" wire:navigate class="hover:text-foreground">Support</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $this->isEditing() ? 'Edit' : 'New ticket' }}</span>
    </nav>

    <h1 class="mb-6 text-2xl font-semibold text-foreground">
        {{ $this->isEditing() ? 'Edit ticket' : 'New ticket' }}
    </h1>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">What is wrong</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="subject" required>Subject</x-form.label>
                    <x-form.input id="subject" wire:model="subject" :invalid="$errors->has('subject')" placeholder="Roof lantern leaking after the storm" />
                    <x-form.error for="subject" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="description">What they told us</x-form.label>
                    <textarea
                        id="description"
                        wire:model="description"
                        rows="5"
                        @class([
                            'w-full rounded-lg border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground',
                            'focus:outline-none focus:ring-2',
                            'border-destructive focus:border-destructive focus:ring-destructive/40' => $errors->has('description'),
                            'border-border focus:border-accent focus:ring-accent/40' => ! $errors->has('description'),
                        ])
                        placeholder="In their words, as far as possible."
                    ></textarea>
                    <x-form.error for="description" />
                </div>

                <x-select
                    name="priority"
                    label="Priority"
                    :options="$this->priorityOptions()"
                    :selected="$priority"
                    required
                    :error="$errors->first('priority')"
                    hint="What the queue is sorted by."
                    wire:model="priority"
                />

                <x-select
                    name="source"
                    label="How it came in"
                    :options="$this->sourceOptions()"
                    :selected="$source"
                    required
                    :error="$errors->first('source')"
                    wire:model="source"
                />
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Who it is for</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Server-side search: there can be far more accounts than a
                     dropdown should hold, so it pages as the user scrolls. --}}
                <x-select
                    name="account_id"
                    label="Account"
                    :options="[]"
                    :selected="$account_id"
                    placeholder="Search accounts…"
                    search-method="searchAccounts"
                    preload
                    clearable
                    :error="$errors->first('account_id')"
                    hint="Leave empty if you do not know yet."
                    wire:model.live="account_id"
                />

                {{-- Keyed on the account so the field behind wire:ignore is
                     rebuilt when the parent moves. --}}
                <div wire:key="ticket-contact-{{ $account_id ?? 'any' }}">
                    <x-select
                        name="contact_id"
                        label="Contact"
                        :options="[]"
                        :selected="$contact_id"
                        placeholder="Search people…"
                        search-method="searchContacts"
                        depends-on="account_id"
                        preload
                        clearable
                        :error="$errors->first('contact_id')"
                        hint="Narrowed to the account once one is chosen."
                        wire:model="contact_id"
                    />
                </div>

                @if ($this->canAssign())
                    <x-select
                        name="owner_id"
                        label="Agent"
                        :options="$this->agentOptions()"
                        :selected="$owner_id"
                        placeholder="Choose an agent…"
                        required
                        :error="$errors->first('owner_id')"
                        hint="Whose queue it appears on. Ticket visibility follows this."
                        wire:model="owner_id"
                    />
                @endif
            </div>
        </section>

        @if ($this->hasCustomFields())
            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">More</h2>
                <div class="mt-4">
                    <x-custom-fields :form="$this" />
                </div>
            </section>
        @endif

        <div class="flex items-center gap-2">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <x-icon name="lucide-check" class="h-4 w-4" />
                {{ $this->isEditing() ? 'Save ticket' : 'Raise ticket' }}
            </x-button>

            <a
                href="{{ $this->isEditing() ? route('tickets.show', $ticketId) : route('tickets.index') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
            >
                Cancel
            </a>
        </div>
    </form>
</div>
