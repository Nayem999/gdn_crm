<div class="mx-auto max-w-3xl space-y-6 pb-16">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">
                {{ $campaignId ? 'Edit campaign' : 'Add campaign' }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                What it is, when it runs, and what it costs.
            </p>
        </div>

        <a
            href="{{ $campaignId ? route('campaigns.show', $campaignId) : route('campaigns.index') }}"
            wire:navigate
            class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
            <x-icon name="lucide-arrow-left" />
            {{ $campaignId ? 'Back to campaign' : 'Campaigns' }}
        </a>
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What it is</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="name" required>Name</x-form.label>
                    <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" />
                    <x-form.error for="name" />
                </div>

                <div wire:key="type-{{ $type }}">
                    <x-form.label for="type" required>Type</x-form.label>
                    <x-select name="type" :options="$this->typeOptions()" :selected="$type" wire:model.live="type" />
                    <x-form.error for="type" />
                </div>

                <div wire:key="status-{{ $status }}">
                    <x-form.label for="status" required>Status</x-form.label>
                    <x-select name="status" :options="$this->statusOptions()" :selected="$status" wire:model.live="status" />
                    <x-form.error for="status" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="code">Code</x-form.label>
                    <x-form.input id="code" wire:model="code" :invalid="$errors->has('code')" placeholder="CMP-0042" />
                    <x-form.error for="code" />
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Optional, and unique. This is what a brief, an invoice and a linked Meta campaign all refer to.
                    </p>
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="description">Description</x-form.label>
                    <textarea
                        id="description"
                        wire:model="description"
                        rows="3"
                        class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
                    ></textarea>
                    <x-form.error for="description" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">When it runs</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Every cost-per-lead figure divides by this window, so a campaign that ends before it starts is refused.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="startDate">Starts</x-form.label>
                    <x-form.input id="startDate" type="date" wire:model="startDate" :invalid="$errors->has('startDate')" />
                    <x-form.error for="startDate" />
                </div>

                <div>
                    <x-form.label for="endDate">Ends</x-form.label>
                    <x-form.input id="endDate" type="date" wire:model="endDate" :invalid="$errors->has('endDate')" />
                    <x-form.error for="endDate" />
                    <p class="mt-1.5 text-xs text-muted-foreground">Leave blank while it is still running.</p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What it costs</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <x-form.label for="budget">Budget</x-form.label>
                    <x-form.input id="budget" type="number" step="0.01" min="0" wire:model="budget" :invalid="$errors->has('budget')" />
                    <x-form.error for="budget" />
                </div>

                <div>
                    <x-form.label for="actualCost">Spent so far</x-form.label>
                    <x-form.input id="actualCost" type="number" step="0.01" min="0" wire:model="actualCost" :invalid="$errors->has('actualCost')" />
                    <x-form.error for="actualCost" />
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Advertising spend is added here automatically once a Meta campaign is linked.
                    </p>
                </div>

                <div>
                    <x-form.label for="expectedRevenue">Expected revenue</x-form.label>
                    <x-form.input id="expectedRevenue" type="number" step="0.01" min="0" wire:model="expectedRevenue" :invalid="$errors->has('expectedRevenue')" />
                    <x-form.error for="expectedRevenue" />
                </div>
            </div>
        </section>

        @if ($this->hasCustomFields())
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">More</h2>
                <div class="mt-4">
                    <x-custom-fields :form="$this" />
                </div>
            </section>
        @endif

        <section class="rounded-xl border border-border bg-card p-5">
            <div wire:key="owner-{{ $ownerId }}" class="sm:max-w-sm">
                <x-form.label for="ownerId" required>Owner</x-form.label>
                <x-select
                    name="ownerId"
                    :options="collect($this->ownerOptions())->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()"
                    :selected="$ownerId"
                    wire:model.live="ownerId"
                />
                <x-form.error for="ownerId" />
            </div>
        </section>

        <div class="flex items-center gap-3">
            <x-button type="submit">{{ $campaignId ? 'Save campaign' : 'Add campaign' }}</x-button>

            <a href="{{ route('campaigns.index') }}" wire:navigate class="text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </a>
        </div>
    </form>
</div>
