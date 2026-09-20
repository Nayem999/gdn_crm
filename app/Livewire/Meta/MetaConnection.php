<?php

namespace App\Livewire\Meta;

use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Meta\Actions\ConnectMetaWithTokenAction;
use App\Domain\Meta\Actions\DisconnectMetaAccountAction;
use App\Domain\Meta\Actions\SyncMetaAssetsAction;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\MetaConnectionTester;
use App\Domain\Meta\MetaUrls;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * The connection screen: what is connected, what it can do, and what to fix.
 *
 * §32 asks for an eleven-step wizard. This is that, arranged as **stages that
 * report their own state** rather than a sequence somebody clicks through once.
 * The difference matters: a wizard is finished and gone, and the questions it
 * asked — is the token still good, is this page still subscribed, which
 * permission is missing — are exactly the questions somebody comes back with in
 * three months. A screen that shows the same stages, ticked or not, answers both
 * the first day and every day after it.
 *
 * The order is still enforced: nothing below the connection is offered until
 * there is one, because every one of those choices is read from Meta.
 */
#[Title('Meta')]
class MetaConnection extends Component
{
    use AuthorizesRequests;

    public ?string $error = null;

    public ?string $notice = null;

    /**
     * The capability check's last answer, or null when it has not been run.
     *
     * @var array<int, array{key: string, label: string, passed: bool, detail: string}>|null
     */
    public ?array $testResults = null;

    /**
     * The paste-a-token form, open when nothing is connected yet.
     *
     * OAuth stays the first option offered, but it is not available to every
     * installation — Meta only redirects to a public HTTPS address — and a
     * token form hidden behind a button is a way in that the person holding a
     * system user token cannot tell exists.
     */
    public bool $showToken = false;

    public string $token = '';

    public string $pageId = '';

    public string $adAccountId = '';

    public string $wabaId = '';

    /**
     * A token per asset, where Meta issued separate ones.
     *
     * Left blank they fall back to the connection's token, which is what a
     * single system user holding every asset looks like. Filled in, each is
     * stored against its own asset — which is what a business that was given
     * three tokens actually has, and refusing them would mean refusing
     * credentials that work.
     */
    public string $pageToken = '';

    public string $wabaToken = '';

    public string $adsToken = '';

    /**
     * What calling our own webhook address proved, per channel.
     *
     * @var array<string, array{ok: bool, detail: string}>
     */
    public array $webhookTests = [];

    /**
     * What each pasted identifier turned out to be, one line each.
     *
     * @var array<int, array{label: string, ok: bool, detail: string}>|null
     */
    public ?array $tokenResults = null;

    public function mount(): void
    {
        $this->authorize('viewAny', MetaAccount::class);

        $this->error = session('error');
        $this->notice = session('status');

        // Open when there is nothing connected yet. The button was hiding the
        // only way in for every installation Meta cannot redirect to, and an
        // administrator holding a system user token could not tell this screen
        // would take one.
        $this->showToken = $this->account() === null;
    }

    public function account(): ?MetaAccount
    {
        return MetaAccount::query()
            ->with(['pages', 'adAccounts', 'whatsAppAccounts.phoneNumbers', 'connectedBy'])
            ->latest('id')
            ->first();
    }

    public function isAppConfigured(): bool
    {
        return app(MetaConfiguration::class)->isConfigured();
    }

    /**
     * What still has to happen, in order, and whether it has.
     *
     * §32's eleven steps, collapsed where two of them are one decision: choosing
     * a WhatsApp business account and choosing its number is one act to the
     * person doing it, and splitting it into two screens would be ceremony
     * rather than clarity.
     *
     * @return array<int, array{label: string, done: bool, detail: string}>
     */
    public function stages(): array
    {
        $account = $this->account();
        $configuration = app(MetaConfiguration::class);

        $pages = $account === null ? new Collection : $account->pages;
        $adAccounts = $account === null ? new Collection : $account->adAccounts;
        $whatsApp = $account === null ? new Collection : $account->whatsAppAccounts;

        $numbers = $whatsApp->flatMap(fn ($waba) => $waba->phoneNumbers);

        // Nothing below the connection can work without it. After a disconnect
        // the rows stay — every lead attributed to a form on one of those pages
        // still points at them, and deleting them to look tidy would turn a
        // year of marketing history into orphaned ids — but the tokens are
        // gone, so a checklist still ticking "sending from +880…" would be
        // describing something that cannot send.
        $connected = $account?->isUsable() === true;

        return [
            [
                'label' => 'Meta app credentials',
                'done' => $configuration->isConfigured(),
                'detail' => $configuration->isConfigured()
                    ? 'The app ID and secret are stored.'
                    : 'Add them under Settings → Meta first: '.implode(' and ', $configuration->missing()).'.',
            ],
            [
                'label' => 'Business connected',
                'done' => $account?->isUsable() === true,
                'detail' => match (true) {
                    $account === null => 'Nothing is connected yet.',
                    $account->isUsable() => 'Connected to '.$account->name.'.',
                    default => $account->status()->guidance() ?? 'This connection needs attention.',
                },
            ],
            [
                'label' => 'Facebook Pages',
                'done' => $connected && $pages->contains(fn (MetaPage $page): bool => $page->isUsable()),
                'detail' => match (true) {
                    $pages->isEmpty() => 'None yet. Add the Page ID below and the Page is read from Meta.',
                    ! $connected => $pages->count().' known, none usable until Meta is connected again.',
                    default => $pages->count().' available, '.$pages->filter(fn (MetaPage $page): bool => $page->is_subscribed)->count().' subscribed.',
                },
            ],
            [
                'label' => 'Ad accounts',
                'done' => $connected && $adAccounts->isNotEmpty(),
                'detail' => match (true) {
                    $adAccounts->isEmpty() => 'None yet, so campaign figures will be empty. Add the ad account ID below.',
                    ! $connected => $adAccounts->count().' known, but the figures stopped updating when Meta was disconnected.',
                    default => $adAccounts->count().' available.',
                },
            ],
            [
                'label' => 'WhatsApp number',
                'done' => $connected && $numbers->contains(fn (WhatsAppPhoneNumber $number): bool => $number->is_default),
                // **Numbers are never typed here.** They are read from Meta
                // against the business account, because a phone number ID
                // entered by hand is one that can be wrong — and the failure
                // that produces is messages that go nowhere rather than an
                // error anybody sees. A checklist line that asked for a number
                // with no field to put it in was, fairly, read as an omission.
                'detail' => match (true) {
                    $whatsApp->isEmpty() => 'None yet. Add the WhatsApp business account ID below; its numbers are read from Meta rather than typed.',
                    $numbers->isEmpty() => 'The connected business account has no numbers. Add one to it in Business Manager, then re-read from Meta.',
                    ! $connected => (string) ($numbers->firstWhere('is_default', true) ?? $numbers->first())->display_number
                        .' is remembered, but nothing can be sent until Meta is connected again.',
                    $numbers->contains(fn (WhatsAppPhoneNumber $number): bool => $number->is_default) => 'Sending from '
                        .(string) $numbers->firstWhere('is_default', true)?->display_number,
                    default => 'Choose which of the '.$numbers->count().' numbers this CRM sends from, below.',
                },
            ],
            [
                'label' => 'Webhooks',
                'done' => $configuration->canVerifyWebhooks(),
                'detail' => $configuration->canVerifyWebhooks()
                    ? 'A verify token is configured.'
                    : 'Set a webhook verify token under Settings → Meta, or nothing will arrive on its own.',
            ],
        ];
    }

    /**
     * The three addresses Meta has to be given, and the token it will echo.
     *
     * Shown rather than described, because this is the step of the setup that
     * cannot be done from here: somebody has to paste each URL into Meta's own
     * webhook configuration, and an address they have to assemble from a
     * documentation page is one they will assemble wrongly.
     *
     * @return array<int, array{channel: string, label: string, url: string, field: string, log: string}>
     */
    public function webhookUrls(): array
    {
        // Built on the **configured** address rather than the one this request
        // arrived on, and always over https — see MetaUrls for why both matter.
        return array_map(fn (MetaChannel $channel): array => [
            'channel' => $channel->value,
            'label' => $channel->label(),
            'url' => MetaUrls::webhook($channel),
            // What to subscribe on Meta's side. Naming it here saves a trip to
            // the documentation for the one detail that decides whether
            // anything is delivered at all.
            'field' => $channel->field(),
            // Where to see whether anything has actually arrived. The test
            // button answers "can this address be called"; this answers "has
            // Meta called it", and the second question is the one somebody
            // asks next.
            'log' => $this->deliveryLog($channel),
        ], MetaChannel::cases());
    }

    /**
     * The delivery log, narrowed to this channel when it has ever delivered.
     *
     * Deliberately not `MetaSources::for()`, which creates the source it cannot
     * find: looking at a settings screen would then provision three data
     * sources for an installation that has never received anything, and the
     * sources screen would show three rows that mean nothing yet.
     */
    private function deliveryLog(MetaChannel $channel): string
    {
        $id = DataSource::query()
            ->where('provider', MetaSources::PROVIDER)
            ->where('name', $channel->sourceName())
            ->value('id');

        return $id === null
            ? route('settings.integration-log')
            : route('settings.integration-log', ['source' => $id]);
    }

    /**
     * The token Meta echoes back when a webhook is subscribed.
     *
     * Shown here, and deliberately: it is stored as a secret, which makes it
     * write-only on the settings screen — so an administrator who set it (or
     * had it generated for them) had **no way to read it back**, and it is
     * useless unless it can be pasted into Meta. Hiding it protected nothing,
     * because knowing it lets somebody verify a webhook they already control;
     * what protects a delivery is the app secret's signature, which is never
     * shown.
     *
     * One token for all three channels. Meta asks per product, and repeating
     * the same value under three headings would imply three tokens to keep
     * track of.
     */
    public function verifyToken(): ?string
    {
        if (auth()->user()?->can('meta.manage') !== true) {
            return null;
        }

        // Minted here rather than asked for. This screen is the only place the
        // token is ever needed — it is copied straight into Meta's webhook
        // configuration from the panel below — so there is no moment at which
        // making somebody invent one first is useful.
        return app(MetaConfiguration::class)->ensureVerifyToken();
    }

    /**
     * Whether Meta could actually reach this installation.
     */
    public function isReachable(): bool
    {
        return MetaUrls::isReachable();
    }

    /**
     * Whether this installation is generating its own links insecurely.
     *
     * Meta is handled — the addresses above force https — but everything else
     * this application builds a URL for is not, and that is worth one line on
     * the screen rather than a password reset nobody can open.
     */
    public function looksMisconfigured(): bool
    {
        return MetaUrls::looksMisconfigured();
    }

    // -- Actions ---------------------------------------------------------------

    /**
     * Re-read what the business owns.
     */
    public function refresh(): void
    {
        $account = $this->account();

        if ($account === null) {
            return;
        }

        $this->authorize('sync', $account);

        try {
            $counts = app(SyncMetaAssetsAction::class)($account);
        } catch (MetaApiException $exception) {
            $this->error = $exception->userMessage();

            return;
        }

        $this->error = null;
        $this->notice = 'Read '.$counts['pages'].' pages, '.$counts['ad_accounts'].' ad accounts and '
            .$counts['whatsapp'].' WhatsApp accounts from Meta.';
    }

    /**
     * Which number outbound WhatsApp goes from. One at a time.
     */
    public function useNumber(int $numberId): void
    {
        $account = $this->account();

        if ($account === null) {
            return;
        }

        $this->authorize('update', $account);

        $number = WhatsAppPhoneNumber::query()
            ->whereKey($numberId)
            ->whereHas('businessAccount', fn ($query) => $query->where('meta_account_id', $account->id))
            ->first();

        if ($number === null) {
            // Not an error worth showing: the only way here is a stale page.
            return;
        }

        // Cleared across the whole connection, not just this business account: a
        // CRM sending from two numbers produces conversations customers cannot
        // reply to, because the reply arrives under whichever number they wrote
        // to and the other one never hears about it.
        WhatsAppPhoneNumber::query()
            ->whereHas('businessAccount', fn ($query) => $query->where('meta_account_id', $account->id))
            ->update(['is_default' => false]);

        $number->forceFill(['is_default' => true])->save();

        $this->notice = 'WhatsApp messages will be sent from '.$number->display_number.'.';
    }

    /**
     * Connect by pasting a system user token and the ids it was given.
     *
     * The token is cleared from the component as soon as it is stored, so it
     * does not sit in Livewire's state being sent back and forth with every
     * subsequent click on this screen.
     */
    public function connectWithToken(): void
    {
        $this->authorize('create', MetaAccount::class);

        $this->validate([
            'token' => ['required', 'string', 'min:20'],
            'pageId' => ['nullable', 'string', 'max:64'],
            'adAccountId' => ['nullable', 'string', 'max:64'],
            'wabaId' => ['nullable', 'string', 'max:64'],
            'pageToken' => ['nullable', 'string', 'min:20'],
            'wabaToken' => ['nullable', 'string', 'min:20'],
            'adsToken' => ['nullable', 'string', 'min:20'],
        ], [
            'token.min' => 'That looks too short to be a Meta access token.',
            'pageToken.min' => 'That looks too short to be a page access token.',
            'wabaToken.min' => 'That looks too short to be an access token.',
            'adsToken.min' => 'That looks too short to be an access token.',
        ]);

        try {
            $outcome = app(ConnectMetaWithTokenAction::class)(
                $this->token,
                [
                    'page_id' => $this->pageId,
                    'ad_account_id' => $this->adAccountId,
                    'waba_id' => $this->wabaId,
                    'page_token' => $this->pageToken,
                    'ads_token' => $this->adsToken,
                    'waba_token' => $this->wabaToken,
                ],
                auth()->user(),
            );
        } catch (MetaApiException $exception) {
            $this->error = $exception->userMessage();

            return;
        }

        // Cleared the moment they are stored, so no credential sits in the
        // component's state being shipped back and forth with every later click
        // on this screen.
        $this->token = '';
        $this->pageToken = '';
        $this->wabaToken = '';
        $this->adsToken = '';
        $this->error = null;
        $this->tokenResults = $outcome['results'];
        $this->testResults = null;
        $this->notice = 'Connected to '.$outcome['account']->name.'.';
    }

    /**
     * Call this installation's own webhook address, as Meta would.
     *
     * Worth having because Meta's refusal says nothing useful — "An error
     * occurred (#1004)" is returned both when the address is unreachable and
     * when the app simply has not been published — and the first question is
     * always whether the endpoint itself answers. This settles that half in a
     * second, so what remains is known to be at Meta's end.
     *
     * **It proves the endpoint, not the route to it.** The request leaves this
     * server and comes back to this server, which a firewall that only blocks
     * outsiders would still allow. The wording says as much rather than
     * claiming more than the test can show.
     */
    public function testWebhook(string $channel): void
    {
        $this->authorize('update', MetaAccount::query()->latest('id')->first() ?? new MetaAccount);

        $case = MetaChannel::tryFrom($channel);

        if ($case === null) {
            return;
        }

        $url = MetaUrls::webhook($case);

        // Not the full outbound guard. A server very often resolves its own
        // domain to an address inside its own network, so `refuse()` answers
        // "gdncrm.example.net resolves to an address inside this network" about
        // the site's own public domain and the test never runs. `refuseSelfCall`
        // is the same check with that one allowance made.
        $refusal = WebhookTarget::refuseSelfCall($url);

        if ($refusal !== null) {
            $this->webhookTests[$channel] = ['ok' => false, 'detail' => $refusal];

            return;
        }

        // Whether the request is about to stay on this machine. Asked before it
        // is sent, because it changes what a pass is allowed to claim.
        $stayedLocal = ! WebhookTarget::allows($url);

        $challenge = (string) random_int(1000000000, 9999999999);

        try {
            $response = Http::timeout(10)->get($url, [
                'hub.mode' => 'subscribe',
                'hub.challenge' => $challenge,
                'hub.verify_token' => (string) app(MetaConfiguration::class)->ensureVerifyToken(),
            ]);
        } catch (Throwable $exception) {
            $this->webhookTests[$channel] = [
                'ok' => false,
                'detail' => 'This address could not be reached from this server: '.$exception->getMessage(),
            ];

            return;
        }

        $body = trim($response->body());

        $this->webhookTests[$channel] = match (true) {
            $response->status() === 403 => [
                'ok' => false,
                'detail' => 'The address answered, but refused the verify token. Something between here and there is '
                    .'rewriting the request, or another installation is answering on this address.',
            ],
            ! $response->successful() => [
                'ok' => false,
                'detail' => 'The address answered '.$response->status().'. Meta needs a 200 with the challenge.',
            ],
            $body !== $challenge => [
                'ok' => false,
                'detail' => 'The address answered 200 but did not echo the challenge, so Meta would refuse it. '
                    .'It replied: '.mb_substr($body, 0, 120),
            ],
            default => [
                'ok' => true,
                'detail' => 'Answered correctly — 200, with the challenge echoed. If Meta still refuses this address, '
                    .'the fault is between Meta and here: most often an app that has not been published, or a '
                    .'firewall that lets this server through and not Meta.'
                    // Said plainly rather than left for somebody to work out
                    // after Meta refuses an address this screen called good.
                    .($stayedLocal
                        ? ' Note that this server resolves the address to itself, so the request never left the '
                            .'machine: this proves the route, the token and the certificate, but not that anything '
                            .'on the internet can reach it.'
                        : ''),
            ],
        };
    }

    public function test(): void
    {
        $account = $this->account();

        if ($account === null) {
            return;
        }

        $this->authorize('view', $account);

        $this->testResults = app(MetaConnectionTester::class)->run($account);
    }

    public function disconnect(): void
    {
        $account = $this->account();

        if ($account === null) {
            return;
        }

        $this->authorize('delete', $account);

        app(DisconnectMetaAccountAction::class)($account);

        $this->testResults = null;
        $this->notice = 'Meta was disconnected. The pages and figures already in the CRM were kept.';
    }

    public function render(): View
    {
        return view('livewire.meta.meta-connection', [
            'account' => $this->account(),
            'stages' => $this->stages(),
        ]);
    }

    /**
     * @return Collection<int, MetaAdAccount>
     */
    public function adAccounts(): Collection
    {
        $account = $this->account();

        return $account === null ? new Collection : $account->adAccounts;
    }
}
