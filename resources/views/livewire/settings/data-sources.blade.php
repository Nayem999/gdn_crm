<div>
    <x-settings-shell
        heading="Data sources"
        description="Where records are allowed to come in from. Each source writes into one module and nowhere else."
        active="settings.data-sources"
    >
        <div
            x-data="{ message: '', tone: 'success' }"
            x-on:data-source-saved.window="tone = 'success'; message = 'Data source saved.'; setTimeout(() => message = '', 3000)"
            x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
            x-show="message"
            x-cloak
            x-transition
            class="mb-4"
        >
            <template x-if="tone === 'error'">
                <x-alert variant="error"><span x-text="message"></span></x-alert>
            </template>
            <template x-if="tone !== 'error'">
                <x-alert variant="success"><span x-text="message"></span></x-alert>
            </template>
        </div>

        @if ($revealedKey !== null)
            {{-- The only time either value is on a screen. The key is stored
                 hashed and cannot be recovered; the signing secret is stored
                 encrypted because HMAC verification needs it, and is
                 deliberately never read back here. --}}
            <div class="mb-6 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4">
                <h2 class="text-sm font-semibold text-foreground">Copy these now — they cannot be shown again</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Give both to whoever is building the integration. The key goes in the
                    <code>X-CRM-Key</code> header; the signing secret is what they sign the request body with.
                </p>

                <p class="mt-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Key</p>
                <code class="mt-1 block overflow-x-auto rounded-lg bg-background px-3 py-2 font-mono text-xs text-foreground">{{ $revealedKey }}</code>

                <p class="mt-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Signing secret</p>
                <code class="mt-1 block overflow-x-auto rounded-lg bg-background px-3 py-2 font-mono text-xs text-foreground">{{ $revealedSigningSecret }}</code>

                <button type="button" wire:click="dismissSecret" class="mt-3 text-xs font-medium text-muted-foreground underline underline-offset-4 hover:text-foreground">
                    I have copied them
                </button>
            </div>
        @endif

        @if ($editing)
            <form wire:submit="save" class="mb-6 space-y-5 rounded-xl border border-border bg-card p-5 sm:p-6">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="source-name" required>Name</x-form.label>
                        <x-form.input id="source-name" wire:model="name" placeholder="Project system" />
                        <x-form.error for="name" />
                    </div>

                    @if ($editingId === null)
                        <div class="sm:col-span-2">
                            <x-select
                                name="blueprint"
                                label="Start from a template"
                                :options="$this->blueprintOptions()"
                                :selected="$blueprint"
                                placeholder="Set it up from scratch"
                                clearable
                                :error="$errors->first('blueprint')"
                                hint="Fills in the filter, the mapping and the matching rules for a common integration. Everything stays editable afterwards."
                                wire:model.live="blueprint"
                            />
                        </div>
                    @endif

                    <x-select
                        name="target_module"
                        label="Brings data into"
                        :options="$this->targetOptions()"
                        :selected="$target_module"
                        placeholder="Choose a module…"
                        required
                        :error="$errors->first('target_module')"
                        hint="A source writes into this module and nowhere else."
                        wire:model="target_module"
                    />

                    <x-select
                        name="type"
                        label="Direction"
                        :options="$this->typeOptions()"
                        :selected="$type"
                        required
                        :error="$errors->first('type')"
                        :hint="$this->chosenType()->description()"
                        wire:model.live="type"
                    />

                    <div class="sm:col-span-2">
                        <x-form.label for="source-description">What it is</x-form.label>
                        <x-form.input id="source-description" wire:model="description" placeholder="Tasks raised in the delivery tool become leads" />
                        <x-form.error for="description" />
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="is_active" class="rounded border-border text-accent focus:ring-accent/40">
                        Enabled
                    </label>
                    <p class="ml-6 text-xs text-muted-foreground">A source that is switched off refuses every delivery.</p>

                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="is_sandbox" class="rounded border-border text-accent focus:ring-accent/40">
                        Sandbox mode
                    </label>
                    <p class="ml-6 text-xs text-muted-foreground">
                        Deliveries are accepted and recorded, but no records are created. Use it while an integration is being built.
                    </p>
                </div>

                <div class="space-y-2 border-t border-border pt-4">
                    <p class="text-sm font-medium text-foreground">How a sender proves who they are</p>

                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="requires_key" class="rounded border-border text-accent focus:ring-accent/40">
                        Require a key in the <code class="text-xs">X-CRM-Key</code> header
                    </label>

                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="requires_signature" class="rounded border-border text-accent focus:ring-accent/40">
                        Require a signature over the request body
                    </label>
                    <x-form.error for="requires_key" />

                    <p class="text-xs text-muted-foreground">
                        A signature also carries a timestamp, so a captured request stops working within five minutes.
                        Leave both on unless the sending system cannot manage one of them.
                    </p>
                </div>

                <div>
                    <x-form.label for="source-allowlist">Allowed addresses</x-form.label>
                    <textarea
                        id="source-allowlist"
                        wire:model="ip_allowlist"
                        rows="3"
                        @class([
                            'w-full rounded-lg border bg-background px-3 py-2 font-mono text-xs text-foreground placeholder:text-muted-foreground',
                            'focus:outline-none focus:ring-2',
                            'border-destructive focus:border-destructive focus:ring-destructive/40' => $errors->has('ip_allowlist'),
                            'border-border focus:border-accent focus:ring-accent/40' => ! $errors->has('ip_allowlist'),
                        ])
                        placeholder="203.0.113.7&#10;198.51.100.0/24"
                    ></textarea>
                    <x-form.error for="ip_allowlist" />
                    <p class="mt-1 text-xs text-muted-foreground">
                        One address or range per line. Leave empty to accept from anywhere &mdash; most integrations
                        run somewhere with no fixed address, and a list nobody can keep correct is a list that gets switched off.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                        {{ $editingId === null ? 'Create source' : 'Save changes' }}
                    </x-button>
                    <button type="button" wire:click="cancel" class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Cancel
                    </button>
                </div>
            </form>
        @elseif ($this->canManage())
            <div class="mb-4">
                <x-button type="button" wire:click="add">New source</x-button>
            </div>
        @endif

        @if ($sources->isEmpty())
            <div class="rounded-xl border border-border bg-card">
                <x-empty-state
                    icon="antenna"
                    heading="No data sources yet"
                    description="A source is how another system puts records into the CRM without anybody typing them in."
                />
            </div>
        @else
            <div class="space-y-4">
                @foreach ($sources as $source)
                    <div class="rounded-xl border border-border bg-card p-5" wire:key="source-{{ $source->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-sm font-semibold text-foreground">{{ $source->name }}</h2>
                                    {!! \App\Domain\Shared\UI\ChipPalette::chip($source->type()->label(), $source->type()->color()) !!}
                                    {!! \App\Domain\Shared\UI\ChipPalette::chip($source->targetLabel(), 'slate') !!}
                                    @if ($source->is_sandbox)
                                        {!! \App\Domain\Shared\UI\ChipPalette::chip('Sandbox', 'amber') !!}
                                    @endif
                                    @unless ($source->is_active)
                                        {!! \App\Domain\Shared\UI\ChipPalette::chip('Off', 'rose') !!}
                                    @endunless
                                </div>

                                @if ($source->description)
                                    <p class="mt-1 text-xs text-muted-foreground">{{ $source->description }}</p>
                                @endif

                                @if ($source->type() === \App\Domain\Ingestion\Enums\DataSourceType::Push)
                                    <p class="mt-2 break-all font-mono text-xs text-muted-foreground">
                                        <span class="font-sans">Posts to</span> {{ $source->ingestUrl() }}
                                    </p>
                                @endif

                                <p class="mt-2 text-xs text-muted-foreground">
                                    @if ($source->hasSecret())
                                        Key &hellip;{{ $source->secret_hint }}, issued {{ $source->secret_created_at?->format('j M Y') }}
                                        @if ($source->isInGrace())
                                            <span class="text-amber-600 dark:text-amber-400">
                                                &middot; previous key works until {{ $source->graceEndsAt()?->format('j M Y, H:i') }}
                                            </span>
                                        @endif
                                    @elseif ($source->secretWasRevoked())
                                        <span class="text-destructive">Key revoked &mdash; nothing can authenticate as this source</span>
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400">No key yet &mdash; this source cannot be used until one is issued</span>
                                    @endif
                                </p>

                                <p class="mt-1 text-xs text-muted-foreground">
                                    Checks
                                    @if ($source->requires_key && $source->requires_signature)
                                        a key and a signature
                                    @elseif ($source->requires_key)
                                        a key
                                    @elseif ($source->requires_signature)
                                        a signature
                                    @else
                                        nothing
                                    @endif
                                    @if (filled($source->ip_allowlist))
                                        &middot; only from {{ implode(', ', array_slice($source->ip_allowlist, 0, 3)) }}@if (count($source->ip_allowlist) > 3)&hellip;@endif
                                    @endif
                                </p>

                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ number_format($source->events_count) }}
                                    {{ \Illuminate\Support\Str::plural('delivery', $source->events_count) }}
                                    @if ($source->createdBy)
                                        &middot; added by {{ $source->createdBy->name }}
                                    @endif
                                </p>
                            </div>

                            @if ($this->canManage())
                                <div class="flex flex-wrap gap-3 text-sm">
                                    <button type="button" wire:click="edit({{ $source->id }})" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                                        Edit
                                    </button>
                                    <a href="{{ route('settings.data-sources.mapping', $source->id) }}" wire:navigate class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                                        Mapping
                                    </a>
                                    <button type="button" wire:click="toggleActive({{ $source->id }})" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                                        {{ $source->is_active ? 'Switch off' : 'Switch on' }}
                                    </button>
                                    <button type="button" wire:click="toggleSandbox({{ $source->id }})" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                                        {{ $source->is_sandbox ? 'Go live' : 'Sandbox' }}
                                    </button>
                                    @if ($this->canManageSecrets())
                                        <button
                                            type="button"
                                            wire:click="issueSecret({{ $source->id }})"
                                            @if ($source->hasSecret())
                                                wire:confirm="Rotate the key? The old one keeps working for the grace window set under Inbound data settings, then stops."
                                            @endif
                                            class="text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                        >{{ $source->hasSecret() ? 'Rotate key' : 'Issue key' }}</button>

                                        @if ($source->hasSecret())
                                            <button
                                                type="button"
                                                wire:click="revokeSecret({{ $source->id }})"
                                                wire:confirm="Revoke the key now? Every delivery stops immediately — there is no grace window on a revoke."
                                                class="text-destructive underline underline-offset-4"
                                            >Revoke key</button>
                                        @endif
                                    @endif

                                    <button
                                        type="button"
                                        wire:click="delete({{ $source->id }})"
                                        wire:confirm="Remove this source? Nothing further will be accepted at its address. What it has already brought in is kept."
                                        class="text-destructive underline underline-offset-4"
                                    >Remove</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-settings-shell>
</div>
