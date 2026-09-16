<div>
    <x-settings-shell
        heading="Meta campaigns"
        description="What Meta is spending, and which CRM campaign each piece of it belongs to."
        active="settings.meta.campaigns"
    >
        @if ($error)
            <div class="mb-6"><x-alert variant="error">{{ $error }}</x-alert></div>
        @endif

        @if ($notice)
            <div class="mb-6"><x-alert variant="success">{{ $notice }}</x-alert></div>
        @endif

        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="w-full max-w-xs">
                <x-form.input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search campaigns"
                    aria-label="Search Meta campaigns"
                />
            </div>

            @can('sync', App\Domain\Meta\Models\MetaCampaign::class)
                <x-button type="button" variant="secondary" wire:click="sync" wire:loading.attr="disabled">
                    <x-icon name="lucide-refresh-cw" />
                    Read Meta now
                </x-button>
            @endcan
        </div>

        @if ($campaigns->isEmpty())
            <x-empty-state
                icon="megaphone"
                :filtered="$search !== ''"
                heading="{{ $search !== '' ? 'No campaign matches that' : 'No Meta campaigns yet' }}"
                description="{{ $search !== ''
                    ? 'Try a shorter search.'
                    : 'Campaigns appear here once a connected ad account has been read. That happens every fifteen minutes, or now if you ask.' }}"
            />
        @else
            <div class="overflow-x-auto rounded-xl border border-border bg-card">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Campaign</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">Spend</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">Impressions</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">Reach</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">Clicks</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">CTR</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">CPC</th>
                            <th scope="col" class="px-4 py-3 font-medium text-right">Leads</th>
                            <th scope="col" class="px-4 py-3 font-medium">CRM campaign</th>
                            <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">Link</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($campaigns as $campaign)
                            @php($figure = $figures[$campaign->meta_campaign_id] ?? null)

                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-foreground">{{ $campaign->name }}</div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ $campaign->objective() ?? 'No objective' }}
                                    </div>
                                </td>

                                <td class="px-4 py-3">
                                    {{-- Meta's effective status, which is the one
                                         that says whether money is actually
                                         moving: a campaign set ACTIVE inside a
                                         disabled ad account spends nothing. --}}
                                    <x-status-chip
                                        :label="$campaign->effective_status ?? $campaign->status() ?? 'Unknown'"
                                        :color="$campaign->isRunning() ? 'emerald' : 'slate'"
                                    />
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-foreground">
                                    @if ($figure)
                                        {{ $figure['currency'] }} {{ number_format($figure['spend'], 2) }}
                                        @if ($figure['read_at'])
                                            {{-- Meta restates a day's figures for
                                                 up to 72 hours, so the screen says
                                                 when it asked rather than implying
                                                 a live number. --}}
                                            <div class="text-xs font-normal text-muted-foreground">
                                                as read {{ \Illuminate\Support\Carbon::parse($figure['read_at'])->diffForHumans() }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted-foreground">&mdash;</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {{ $figure ? number_format($figure['impressions']) : '—' }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {{ $figure ? number_format($figure['reach']) : '—' }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {{ $figure ? number_format($figure['clicks']) : '—' }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {{-- A dash where there is no denominator: a
                                         campaign nobody has seen has no
                                         click-through rate, and 0% would be a
                                         claim about how it performed. --}}
                                    {{ $figure && $figure['ctr'] !== null ? number_format($figure['ctr'], 2).'%' : '—' }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {{ $figure && $figure['cpc'] !== null ? trim(($figure['currency'] ?? '').' '.number_format($figure['cpc'], 2)) : '—' }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                    {{ $figure['leads'] ?? '—' }}
                                </td>

                                <td class="px-4 py-3">
                                    @if ($campaign->campaign)
                                        <a href="{{ route('campaigns.show', $campaign->campaign) }}" wire:navigate
                                           class="font-medium text-foreground underline decoration-border underline-offset-4">
                                            {{ $campaign->campaign->name }}
                                        </a>
                                    @else
                                        <span class="text-muted-foreground">Not linked</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right">
                                    @can('update', $campaign)
                                        @if ($campaign->isLinked())
                                            <button
                                                type="button"
                                                wire:click="unlink({{ $campaign->id }})"
                                                class="text-sm font-medium text-muted-foreground hover:text-foreground"
                                            >
                                                Unlink
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="startLinking({{ $campaign->id }})"
                                                class="text-sm font-medium text-accent hover:underline"
                                            >
                                                Link
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>

                            @if ($linking === $campaign->id)
                                <tr class="bg-muted/40">
                                    <td colspan="11" class="px-4 py-4">
                                        {{-- wire:key includes the row, so Livewire
                                             replaces the node and Tom Select
                                             rebuilds rather than keeping the
                                             previous row's options — see
                                             .ai/rules/leads.md. --}}
                                        <div wire:key="link-{{ $campaign->id }}" class="flex flex-wrap items-end gap-3">
                                            <div class="w-full max-w-sm">
                                                <x-select
                                                    name="chosenCampaignId"
                                                    label="Part of which CRM campaign?"
                                                    :options="$campaignOptions"
                                                    :selected="$chosenCampaignId"
                                                    placeholder="Choose a campaign..."
                                                    wire:model="chosenCampaignId"
                                                />
                                            </div>

                                            <x-button type="button" wire:click="link">Link</x-button>

                                            <x-button type="button" variant="secondary" wire:click="cancelLinking">Cancel</x-button>
                                        </div>

                                        @if ($campaignOptions === [])
                                            <p class="mt-3 text-sm text-muted-foreground">
                                                Every campaign you can see is already linked to a Meta campaign. One
                                                campaign, one link.
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-xs text-muted-foreground">
                Spend and leads are Meta's own figures, read every fifteen minutes. Linking a campaign never changes
                the cost you typed into the CRM campaign — the two are shown side by side rather than merged.
            </p>
        @endif
    </x-settings-shell>
</div>
