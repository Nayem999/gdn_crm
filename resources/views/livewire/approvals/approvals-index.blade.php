<div class="space-y-8">
    <div>
        <h1 class="text-xl font-semibold text-foreground">Approvals</h1>
        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
            A workflow is standing still until each of these is answered.
        </p>
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    <section>
        <h2 class="text-sm font-semibold text-foreground">Waiting on you</h2>

        <div class="mt-3 space-y-3">
            @forelse ($this->waitingOnMe as $request)
                <article class="rounded-xl border border-border bg-card p-4" wire:key="waiting-{{ $request->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground">{{ $request->summary }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ $request->workflow_name }} &middot; {{ $request->moduleLabel() }}
                                &middot; asked {{ $request->requested_at->diffForHumans() }}
                                @php $level = $request->currentLevel(); @endphp
                                @if ($level?->due_at)
                                    &middot; needed by {{ $level->due_at->toDayDateTimeString() }}
                                @endif
                            </p>
                        </div>

                        <x-status-chip :color="$request->status()->color()" dot>
                            {{ $request->status()->label() }}
                        </x-status-chip>
                    </div>

                    @if ($request->levels->count() > 1)
                        <ol class="mt-3 flex flex-wrap gap-2 text-xs text-muted-foreground">
                            @foreach ($request->levels as $step)
                                <li class="rounded-full border border-border px-2 py-0.5">
                                    {{ $loop->iteration }}. {{ $step->approver?->name ?? 'Nobody' }}
                                    &mdash; {{ $step->status()->label() }}
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <x-form.label for="comment-{{ $request->id }}">Comment</x-form.label>
                            <x-form.input
                                id="comment-{{ $request->id }}"
                                wire:model="comments.{{ $request->id }}"
                                placeholder="Optional, and kept with the decision"
                            />
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90"
                                wire:click="approve({{ $request->id }})"
                            >
                                <x-icon name="lucide-check" />
                                Approve
                            </button>

                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-destructive hover:bg-muted"
                                wire:click="reject({{ $request->id }})"
                            >
                                <x-icon name="lucide-x" />
                                Reject
                            </button>
                        </div>
                    </div>
                </article>
            @empty
                <x-empty-state
                    icon="check-check"
                    heading="Nothing waiting on you"
                    description="Approvals a workflow asks you for will appear here."
                />
            @endforelse
        </div>
    </section>

    <section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-foreground">Already settled</h2>

            @if ($this->canAudit())
                <label class="flex items-center gap-2 text-xs text-muted-foreground">
                    <input type="checkbox" wire:model.live="showAll" class="rounded border-border text-accent focus:ring-accent/40" />
                    Every approval, not only mine
                </label>
            @endif
        </div>

        <div class="mt-3 overflow-x-auto rounded-xl border border-border">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-semibold">What</th>
                        <th class="px-4 py-2 font-semibold">Outcome</th>
                        <th class="px-4 py-2 font-semibold">Who</th>
                        <th class="px-4 py-2 font-semibold">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->history as $request)
                        <tr wire:key="settled-{{ $request->id }}">
                            <td class="px-4 py-2">
                                <span class="text-foreground">{{ $request->summary }}</span>
                                @if ($request->decision_comment)
                                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ $request->decision_comment }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <x-status-chip :color="$request->status()->color()">
                                    {{ $request->status()->label() }}
                                </x-status-chip>
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ $request->levels->firstWhere('decided_by', '!=', null)?->decider?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ $request->completed_at?->toDayDateTimeString() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-muted-foreground">Nothing settled yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
