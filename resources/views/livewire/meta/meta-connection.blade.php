<div>
    <x-settings-shell
        heading="Meta"
        description="Facebook Pages, Lead Ads, advertising figures and WhatsApp — connected once, for the whole CRM."
        active="settings.meta.connect"
    >
        @if ($error)
            <div class="mb-6"><x-alert variant="error">{{ $error }}</x-alert></div>
        @endif

        @if ($notice)
            <div class="mb-6"><x-alert variant="success">{{ $notice }}</x-alert></div>
        @endif

        {{-- The connection itself. --}}
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            @if ($account === null || $account->status()->value === 'disconnected')
                <h2 class="text-base font-semibold text-foreground">Connect Meta</h2>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    You will be sent to Meta to sign in and choose what this CRM may use. Nothing is stored until
                    you come back.
                </p>

                @unless ($this->isAppConfigured())
                    <div class="mt-4">
                        <x-alert variant="info">
                            Add the Meta app ID and secret under
                            <a href="{{ route('settings.group', 'meta') }}" wire:navigate class="font-medium underline">Settings &rarr; Meta</a>
                            before connecting.
                        </x-alert>
                    </div>
                @endunless

                @can('create', App\Domain\Meta\Models\MetaAccount::class)
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        {{-- A form, not a link: starting an OAuth flow writes the
                             state into the session, and a GET that changes state
                             is a GET somebody's browser can be made to make. --}}
                        <form method="POST" action="{{ route('settings.meta.redirect') }}">
                            @csrf
                            <x-button type="submit" :disabled="! $this->isAppConfigured()">
                                <x-icon name="lucide-link" />
                                Connect Meta
                            </x-button>
                        </form>

                        <x-button type="button" variant="secondary" wire:click="$toggle('showToken')">
                            {{ $showToken ? 'Hide the token form' : 'Connect with an access token instead' }}
                        </x-button>
                    </div>

                @endcan
            @else
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-foreground">{{ $account->name }}</h2>
                            <x-status-chip :label="$account->status()->label()" :color="$account->status()->color()" />
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Business {{ $account->business_id }}
                            @if ($account->connectedBy)
                                &middot; connected by {{ $account->connectedBy->name }}
                            @endif
                            @if ($account->connected_at)
                                {{ \App\Domain\Settings\DisplayTime::date($account->connected_at) }}
                            @endif
                        </p>

                        @if ($account->status()->guidance())
                            <p class="mt-2 max-w-xl text-sm text-amber-700 dark:text-amber-300">
                                {{ $account->status()->guidance() }}
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @can('sync', $account)
                            <x-button type="button" variant="secondary" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
                                <span wire:loading.remove wire:target="refresh">Re-read from Meta</span>
                                <span wire:loading wire:target="refresh">Reading&hellip;</span>
                            </x-button>
                        @endcan

                        <x-button type="button" variant="secondary" wire:click="test" wire:loading.attr="disabled" wire:target="test">
                            <span wire:loading.remove wire:target="test">Test connection</span>
                            <span wire:loading wire:target="test">Testing&hellip;</span>
                        </x-button>

                        @can('delete', $account)
                            <x-button
                                type="button"
                                variant="destructive"
                                wire:click="disconnect"
                                wire:confirm="Disconnect Meta? Leads and messages stop arriving, and the app ID, secret and webhook verify token are forgotten — you will need them again to reconnect. Everything already in the CRM is kept."
                            >
                                Disconnect
                            </x-button>
                        @endcan
                    </div>
                </div>
            @endif
        </section>

        @can('create', App\Domain\Meta\Models\MetaAccount::class)
            {{-- Offered whether or not something is connected. Meta issues a
                 token per asset, so a business that connected with one of them
                 must be able to come back and add the others — and a form that
                 disappeared the moment anything was connected left them
                 nowhere to put the other two. --}}
            @if ($showToken)
                <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                        {{-- For the installations Meta cannot redirect to: it only
                             returns to a public HTTPS address, so a CRM on a
                             company network or a laptop has no way through the
                             flow above. Business Manager gives those a system user
                             token and a list of asset ids instead. --}}
                        <div class="mt-5 border-t border-border pt-5">
                            <h3 class="text-sm font-semibold text-foreground">Connect with an access token</h3>
                            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                                For a system user token from Business Manager. Meta only redirects to a public HTTPS
                                address, so this is the way in for a CRM that is not on one. Each identifier is checked
                                against Meta as it is stored, and told about separately.
                            </p>

                        <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                            <strong class="font-medium text-foreground">Holding three tokens is normal.</strong>
                            Meta issues them per asset — one for the ad account, one for the Page, one for the
                            WhatsApp business account — and they are often not the same string. Put the one that
                            identifies the business at the top and each asset's own beside it. Leave an asset's token
                            blank and the one at the top is used for it, which is what a single system user holding
                            everything looks like.
                        </p>

                            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <x-form.label for="meta-token" required>Access token</x-form.label>
                                    <x-form.password id="meta-token" wire:model="token" autocomplete="off" />
                                    <x-form.error for="token" />
                                </div>

                                <div>
                                    <x-form.label for="meta-waba">WhatsApp business account ID</x-form.label>
                                    <x-form.input id="meta-waba" wire:model="wabaId" />
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Business Manager &rarr; WhatsApp accounts. Its numbers are read from Meta, so
                                        the phone number ID is not asked for here.
                                    </p>
                                    <x-form.error for="wabaId" />
                                </div>

                                <div>
                                    <x-form.label for="meta-waba-token">WhatsApp access token</x-form.label>
                                    <x-form.password id="meta-waba-token" wire:model="wabaToken" autocomplete="off" />
                                    <p class="mt-1.5 text-xs text-muted-foreground">Only if it differs from the one above.</p>
                                    <x-form.error for="wabaToken" />
                                </div>

                                <div>
                                    <x-form.label for="meta-page">Facebook Page ID</x-form.label>
                                    <x-form.input id="meta-page" wire:model="pageId" />
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Page settings &rarr; About. Needed for Messenger and Lead Ads.
                                    </p>
                                    <x-form.error for="pageId" />
                                </div>

                                <div>
                                    <x-form.label for="meta-page-token">Page access token</x-form.label>
                                    <x-form.password id="meta-page-token" wire:model="pageToken" autocomplete="off" />
                                    <p class="mt-1.5 text-xs text-muted-foreground">The token Messenger replies are sent with.</p>
                                    <x-form.error for="pageToken" />
                                </div>

                                <div>
                                    <x-form.label for="meta-ad-account">Ad account ID</x-form.label>
                                    <x-form.input id="meta-ad-account" wire:model="adAccountId" />
                                    <p class="mt-1.5 text-xs text-muted-foreground">
                                        Ads Manager &rarr; Account overview, with or without the <code>act_</code>
                                        prefix. This is what campaign spend is read from.
                                    </p>
                                    <x-form.error for="adAccountId" />
                                </div>

                                <div>
                                    <x-form.label for="meta-ads-token">Ads access token</x-form.label>
                                    <x-form.password id="meta-ads-token" wire:model="adsToken" autocomplete="off" />
                                    <p class="mt-1.5 text-xs text-muted-foreground">Only if it differs from the one above.</p>
                                    <x-form.error for="adsToken" />
                                </div>
                            </div>

                            <div class="mt-4">
                                <x-button type="button" wire:click="connectWithToken" wire:loading.attr="disabled" wire:target="connectWithToken">
                                    <span wire:loading.remove wire:target="connectWithToken">Connect with this token</span>
                                    <span wire:loading wire:target="connectWithToken">Checking with Meta&hellip;</span>
                                </x-button>
                            </div>
                        </div>
                </section>
            @else
                <div class="mt-6">
                    <x-button type="button" variant="secondary" wire:click="$toggle('showToken')">
                        Add an asset, or replace a token
                    </x-button>
                </div>
            @endif
        @endcan

        {{-- What still has to happen. §32's steps, as state rather than as a
             sequence: these are the questions somebody comes back with in three
             months, not only on the first day. --}}
        <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-sm font-semibold text-foreground">Setup</h2>

            <ol class="mt-4 space-y-3">
                @foreach ($stages as $index => $stage)
                    <li class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $stage['done'],
                            'border border-border text-muted-foreground' => ! $stage['done'],
                        ])>
                            @if ($stage['done'])
                                <x-icon name="lucide-check" class="h-3.5 w-3.5" />
                            @else
                                {{ $index + 1 }}
                            @endif
                        </span>

                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground">{{ $stage['label'] }}</p>
                            <p class="text-sm text-muted-foreground">{{ $stage['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($tokenResults !== null)
            <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">What that token reached</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    One line per identifier. A wrong one is named rather than throwing away the others.
                </p>

                <ul class="mt-4 divide-y divide-border">
                    @foreach ($tokenResults as $result)
                        <li class="flex items-start gap-3 py-3">
                            <span @class([
                                'mt-0.5 text-xs font-semibold uppercase tracking-wide',
                                'text-emerald-600 dark:text-emerald-400' => $result['ok'],
                                'text-destructive' => ! $result['ok'],
                            ])>
                                {{ $result['ok'] ? 'Ok' : 'Check' }}
                            </span>

                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">{{ $result['label'] }}</p>
                                <p class="text-sm text-muted-foreground">{{ $result['detail'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- The half of the setup that happens in Meta rather than here: where
             a customer's reply actually arrives. --}}
        <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-sm font-semibold text-foreground">Where replies arrive</h2>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                A WhatsApp or Messenger reply reaches this CRM only if Meta is told where to deliver it. Paste each
                address into that product's webhook configuration in your Meta app, subscribe the field named beside
                it, and give Meta the verify token below.
            </p>

            @if ($this->verifyToken() !== null)
                <div class="mt-4 rounded-lg border border-border bg-background p-3">
                    <x-copy-field
                        label="Verify token"
                        :value="$this->verifyToken()"
                        hint="The same token for all three — Meta asks for it once per product, and echoes it back to check the address before it will send anything."
                    />
                </div>
            @else
                <div class="mt-4">
                    <x-alert variant="info">
                        No webhook verify token is set, so Meta's check call would be refused and the subscription
                        would not save. Add one under
                        <a href="{{ route('settings.group', 'meta') }}" wire:navigate class="font-medium underline">Settings &rarr; Meta</a>.
                    </x-alert>
                </div>
            @endif

            <ul class="mt-4 space-y-2">
                @foreach ($this->webhookUrls() as $webhook)
                    <li class="rounded-lg border border-border px-3 py-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-medium text-foreground">{{ $webhook['label'] }}</span>
                            <span class="text-xs text-muted-foreground">
                                subscribe <code>{{ $webhook['field'] }}</code>
                            </span>
                        </div>

                        <div class="mt-1.5">
                            <x-copy-field :value="$webhook['url']" />
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4 space-y-4">
                @if ($this->looksMisconfigured())
                    {{-- Meta is safe — the addresses above are forced to https —
                         but every other link this application generates is not. --}}
                    <x-alert variant="info">
                        These addresses are shown over <strong class="font-medium">https</strong> because Meta accepts
                        nothing else, but this installation's configured address begins <code>http://</code>. Password
                        reset links and other generated URLs will be insecure too. Set <code>APP_URL</code> to the
                        https address, and if the site sits behind a proxy that terminates TLS, set
                        <code>TRUSTED_PROXIES</code> as well.
                    </x-alert>
                @endif

                @if (! $this->isReachable())
                    <x-alert variant="info">
                        These are built from this installation's configured address, which is not a public HTTPS one —
                        so Meta cannot call it and no reply will arrive here, whatever is pasted into Meta. Sending,
                        templates and the advertising figures all work from here regardless; only inbound messages need
                        the CRM published at an address Meta can reach.
                    </x-alert>
                @elseif (! $this->isAppConfigured() || ! app(App\Domain\Meta\MetaConfiguration::class)->canVerifyWebhooks())
                    <x-alert variant="info">
                        Set a webhook verify token under Settings &rarr; Meta before subscribing, or Meta's check call
                        will be refused and the subscription will not save.
                    </x-alert>
                @endif
            </div>
        </section>

        @if ($testResults !== null)
            <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">Test results</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Every capability is checked independently, so one failure does not hide the others.
                </p>

                <ul class="mt-4 divide-y divide-border">
                    @foreach ($testResults as $result)
                        <li class="flex items-start gap-3 py-3">
                            <span @class([
                                'mt-0.5 text-xs font-semibold uppercase tracking-wide',
                                'text-emerald-600 dark:text-emerald-400' => $result['passed'],
                                'text-destructive' => ! $result['passed'],
                            ])>
                                {{ $result['passed'] ? 'Pass' : 'Fail' }}
                            </span>

                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">{{ $result['label'] }}</p>
                                <p class="text-sm text-muted-foreground">{{ $result['detail'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($account !== null && $account->whatsAppAccounts->isNotEmpty())
            <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">WhatsApp numbers</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    One number at a time. A CRM sending from two produces conversations customers cannot reply to.
                </p>

                <ul class="mt-4 divide-y divide-border">
                    @foreach ($account->whatsAppAccounts as $waba)
                        @foreach ($waba->phoneNumbers as $number)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-foreground">
                                        {{ $number->display_number }}
                                        @if ($number->verified_name)
                                            <span class="text-muted-foreground">&middot; {{ $number->verified_name }}</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ $waba->name }}
                                        @if ($number->healthNote())
                                            &middot; {{ $number->healthNote() }}
                                        @endif
                                    </p>
                                </div>

                                @if ($number->is_default)
                                    <x-status-chip label="Sending from this" color="emerald" />
                                @elsecan('update', $account)
                                    <x-button type="button" variant="secondary" wire:click="useNumber({{ $number->id }})">
                                        Use this number
                                    </x-button>
                                @endcan
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($account !== null && $account->pages->isNotEmpty())
            <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">Facebook Pages</h2>

                <ul class="mt-4 divide-y divide-border">
                    @foreach ($account->pages as $page)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">{{ $page->name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    Page {{ $page->page_id }}
                                    @if ($page->last_synced_at)
                                        &middot; read {{ $page->last_synced_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>

                            <x-status-chip
                                :label="$page->is_subscribed ? 'Subscribed' : 'Not subscribed'"
                                :color="$page->is_subscribed ? 'emerald' : 'slate'"
                            />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($account !== null && $account->adAccounts->isNotEmpty())
            <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">Ad accounts</h2>

                <ul class="mt-4 divide-y divide-border">
                    @foreach ($account->adAccounts as $adAccount)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">{{ $adAccount->name }}</p>
                                <p class="text-xs text-muted-foreground">{{ $adAccount->graphId() }}</p>
                            </div>

                            {{-- Currency per account, not per installation: two
                                 accounts in different currencies must never be
                                 added together. --}}
                            <span class="text-xs font-medium text-muted-foreground">{{ $adAccount->currency ?? '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </x-settings-shell>
</div>
