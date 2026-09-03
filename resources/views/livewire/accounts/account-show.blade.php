<div>
    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('accounts.index') }}" wire:navigate class="hover:text-foreground">Accounts</a>

        @foreach ($lineage as $ancestor)
            <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
            <a href="{{ route('accounts.show', $ancestor) }}" wire:navigate class="hover:text-foreground">{{ $ancestor->name }}</a>
        @endforeach

        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $account->name }}</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                <x-icon name="lucide-building-2" class="h-5 w-5" />
            </span>

            <div>
                <h1 class="text-2xl font-semibold text-foreground">{{ $account->name }}</h1>

                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    @if ($account->industry())
                        <x-status-chip :color="$account->industry()->color()">{{ $account->industry()->label() }}</x-status-chip>
                    @endif

                    @if ($account->size())
                        <x-status-chip :color="$account->size()->color()" dot>{{ $account->size()->shortLabel() }}</x-status-chip>
                    @endif

                    @if ($account->trashed())
                        <x-status-chip color="rose">Removed</x-status-chip>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('update', $account)
                <a href="{{ route('accounts.edit', $account) }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    <x-icon name="lucide-pencil" />
                    Edit
                </a>
            @endcan

            @can('delete', $account)
                <button
                    type="button"
                    wire:click="delete"
                    wire:confirm="Remove {{ $account->name }}? Its subsidiaries move to the top level."
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-destructive transition-colors hover:bg-destructive/10"
                >
                    <x-icon name="lucide-trash-2" />
                    Remove
                </button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">Profile</h2>

                <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    @foreach ([
                        'Registered legal name' => $account->legal_name,
                        'Annual revenue' => $account->annual_revenue === null
                            ? null
                            : \App\Domain\Settings\NumberFormat::format((float) $account->annual_revenue, 0),
                        'Email' => $account->email,
                        'Phone' => $account->phone,
                        'City' => $account->city,
                        'State or region' => $account->state,
                        'Postal code' => $account->postal_code,
                        'Country' => $account->country,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach

                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Website</dt>
                        <dd class="mt-0.5 text-sm">
                            @if ($account->websiteUrl())
                                <a href="{{ $account->websiteUrl() }}" target="_blank" rel="noopener noreferrer" class="text-accent hover:underline">
                                    {{ $account->website }}
                                </a>
                            @else
                                <span class="text-foreground">—</span>
                            @endif
                        </dd>
                    </div>

                    @if ($account->address_line_1)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Address</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-sm text-foreground">{{ collect([
                                $account->address_line_1,
                                $account->address_line_2,
                            ])->filter()->join("\n") }}</dd>
                        </div>
                    @endif

                    @if ($account->description)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Description</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $account->description }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-foreground">Subsidiaries</h2>
                    <span class="text-xs text-muted-foreground">{{ $children->count() }}</span>
                </div>

                @if ($children->isEmpty())
                    <p class="mt-3 text-sm text-muted-foreground">
                        No subsidiaries. Set this account as another's parent to build a hierarchy.
                    </p>
                @else
                    <ul class="mt-3 divide-y divide-border">
                        @foreach ($children as $child)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                <a href="{{ route('accounts.show', $child) }}" wire:navigate class="text-sm font-medium text-foreground hover:text-accent hover:underline">
                                    {{ $child->name }}
                                </a>

                                <span class="flex items-center gap-2 text-xs text-muted-foreground">
                                    @if ($child->industry())
                                        <x-status-chip :color="$child->industry()->color()">{{ $child->industry()->label() }}</x-status-chip>
                                    @endif
                                    {{ $child->owner?->name }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Ownership</h2>

                <dl class="mt-3 space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Owner</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm text-foreground">
                            @if ($account->owner)
                                <x-avatar :user="$account->owner" size="sm" />
                                {{ $account->owner->name }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Parent account</dt>
                        <dd class="mt-1 text-sm">
                            @if ($account->parent)
                                <a href="{{ route('accounts.show', $account->parent) }}" wire:navigate class="text-accent hover:underline">
                                    {{ $account->parent->name }}
                                </a>
                            @else
                                <span class="text-foreground">Top level</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Created</dt>
                        <dd class="mt-1 text-sm text-foreground">{{ $account->created_at?->format('j M Y') }}</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>
</div>
