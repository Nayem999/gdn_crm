<div>
    <x-settings-shell
        heading="SLA policies"
        description="What we promise a customer, and how long we have. The clock runs against a ticket from the moment it arrives, stops while the ticket is on hold, and keeps running while we are waiting on the customer."
        active="settings.sla-policies"
    >
        <div
            x-data="{ message: '' }"
            x-on:notify.window="message = $event.detail.message; setTimeout(() => message = '', 4000)"
            x-show="message"
            x-cloak
            class="mb-4"
        >
            <x-alert variant="success"><span x-text="message"></span></x-alert>
        </div>

        <div class="mb-6 flex flex-wrap items-start justify-end gap-4">
            @can('create', App\Domain\Support\Models\SlaPolicy::class)
                <button
                    type="button"
                    wire:click="create"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
                >
                    <x-icon name="lucide-plus" />
                    New policy
                </button>
            @endcan
        </div>

        @if ($editing)
            <form wire:submit="save" class="mb-6 space-y-5 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">
                    {{ $editingId === null ? 'A new policy' : 'Editing this policy' }}
                </h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="mb-1 block text-sm font-medium text-foreground">Name</label>
                        <input id="name" type="text" wire:model="name"
                               class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                        @error('name') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="description" class="mb-1 block text-sm font-medium text-foreground">Description</label>
                        <input id="description" type="text" wire:model="description"
                               class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                        @error('description') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-sm">
                        <thead>
                            <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <th class="py-2 pr-3 font-medium">Priority</th>
                                <th class="py-2 pr-3 font-medium">Minutes to first reply</th>
                                <th class="py-2 font-medium">Minutes to resolve</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->priorities() as $value => $label)
                                <tr class="border-b border-border/60">
                                    <td class="py-2 pr-3 font-medium text-foreground">{{ $label }}</td>
                                    <td class="py-2 pr-3">
                                        <input type="number" min="1" placeholder="No promise"
                                               wire:model="targets.{{ $value }}.first_response"
                                               class="block w-36 rounded-lg border border-border bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                                        @error('targets.'.$value.'.first_response')
                                            <p class="mt-1 text-sm text-destructive">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="py-2">
                                        <input type="number" min="1" placeholder="No promise"
                                               wire:model="targets.{{ $value }}.resolution"
                                               class="block w-36 rounded-lg border border-border bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                                        @error('targets.'.$value.'.resolution')
                                            <p class="mt-1 text-sm text-destructive">{{ $message }}</p>
                                        @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="text-sm text-muted-foreground">
                    An empty box means no promise of that kind at that priority &mdash; not nought minutes.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="warnAtPercent" class="mb-1 block text-sm font-medium text-foreground">
                            Warn at
                        </label>
                        <div class="flex items-center gap-2">
                            <input id="warnAtPercent" type="number" min="1" max="99" wire:model="warnAtPercent"
                                   class="block w-28 rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                            <span class="text-sm text-muted-foreground">% of the way through</span>
                        </div>
                        @error('warnAtPercent') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col justify-end gap-2">
                        <label class="inline-flex items-center gap-2 text-sm text-foreground">
                            <input type="checkbox" wire:model="isDefault" class="h-4 w-4 rounded border-border text-accent focus:ring-accent">
                            Apply this to new tickets
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-foreground">
                            <input type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-border text-accent focus:ring-accent">
                            In use
                        </label>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                        Save policy
                    </button>
                    <button type="button" wire:click="cancel"
                            class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                        Cancel
                    </button>
                </div>
            </form>
        @endif

        @if ($this->policies->isEmpty())
            <div class="rounded-xl border border-dashed border-border p-10 text-center">
                <x-icon name="lucide-timer" class="mx-auto h-8 w-8 text-muted-foreground" />
                <h2 class="mt-3 text-base font-semibold text-foreground">No promises yet</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Until a policy is the default, tickets run without a clock and nothing can breach.
                </p>
            </div>
        @else
            <ul class="space-y-3">
                @foreach ($this->policies as $policy)
                    <li class="rounded-xl border border-border bg-card p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold text-foreground">
                                    {{ $policy->name }}
                                    @if ($policy->is_default)
                                        {!! \App\Domain\Shared\UI\ChipPalette::chip('Default', 'emerald') !!}
                                    @endif
                                    @unless ($policy->is_active)
                                        {!! \App\Domain\Shared\UI\ChipPalette::chip('Not in use', 'slate') !!}
                                    @endunless
                                </h2>
                                @if ($policy->description)
                                    <p class="mt-1 text-sm text-muted-foreground">{{ $policy->description }}</p>
                                @endif
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Warns at {{ $policy->warn_at_percent }}% &middot;
                                    {{ $policy->tickets_count }} {{ \Illuminate\Support\Str::plural('ticket', $policy->tickets_count) }}
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @can('update', $policy)
                                    @unless ($policy->is_default)
                                        <button type="button" wire:click="makeDefault({{ $policy->id }})"
                                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:text-foreground">
                                            Make default
                                        </button>
                                    @endunless
                                    <button type="button" wire:click="edit({{ $policy->id }})"
                                            class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-muted">
                                        Edit
                                    </button>
                                @endcan
                                @can('delete', $policy)
                                    <button type="button" wire:click="delete({{ $policy->id }})"
                                            wire:confirm="Remove this policy? Tickets already given it keep their deadlines."
                                            class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-destructive transition-colors hover:bg-destructive/10">
                                        Remove
                                    </button>
                                @endcan
                            </div>
                        </div>

                        @if ($policy->targets->isNotEmpty())
                            <div class="mt-4 overflow-x-auto">
                                <table class="w-full min-w-[24rem] text-sm">
                                    <thead>
                                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                            <th class="py-1.5 pr-3 font-medium">Priority</th>
                                            <th class="py-1.5 pr-3 font-medium">First reply</th>
                                            <th class="py-1.5 font-medium">Resolution</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($policy->targets->sortByDesc('priority') as $target)
                                            <tr class="border-b border-border/60">
                                                <td class="py-1.5 pr-3 text-foreground">{{ $target->priority()->label() }}</td>
                                                <td class="py-1.5 pr-3 text-muted-foreground">
                                                    {{ $target->first_response_minutes === null ? '—' : $target->first_response_minutes . ' min' }}
                                                </td>
                                                <td class="py-1.5 text-muted-foreground">
                                                    {{ $target->resolution_minutes === null ? '—' : $target->resolution_minutes . ' min' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="mt-3 text-sm text-muted-foreground">This policy promises nothing at any priority.</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-settings-shell>
</div>
