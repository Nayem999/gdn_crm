<?php

namespace App\Livewire\Meta;

use App\Domain\Meta\Actions\ConnectMetaWithTokenAction;
use App\Domain\Meta\Actions\DisconnectMetaAccountAction;
use App\Domain\Meta\Actions\SyncMetaAssetsAction;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\MetaConnectionTester;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

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
                'done' => $pages->contains(fn (MetaPage $page): bool => $page->isUsable()),
                'detail' => $pages->isEmpty()
                    ? 'No pages have been read from Meta yet.'
                    : $pages->count().' available, '.$pages->filter(fn (MetaPage $page): bool => $page->is_subscribed)->count().' subscribed.',
            ],
            [
                'label' => 'Ad accounts',
                'done' => $adAccounts->isNotEmpty(),
                'detail' => $adAccounts->isEmpty()
                    ? 'No ad accounts yet — campaign figures will be empty.'
                    : $adAccounts->count().' available.',
            ],
            [
                'label' => 'WhatsApp number',
                'done' => $numbers->contains(fn (WhatsAppPhoneNumber $number): bool => $number->is_default),
                'detail' => match (true) {
                    $numbers->isEmpty() => 'No WhatsApp numbers are available to this business.',
                    $numbers->contains(fn (WhatsAppPhoneNumber $number): bool => $number->is_default) => 'Sending from '
                        .(string) $numbers->firstWhere('is_default', true)?->display_number,
                    default => 'Choose which number this CRM sends from.',
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
     * @return array<int, array{label: string, url: string}>
     */
    public function webhookUrls(): array
    {
        // Built on the **configured** address rather than the one this request
        // arrived on. Somebody setting Meta up is usually looking at a
        // development host, and a panel that printed "localhost:8123" would
        // hand them an address Meta can never call — which fails silently,
        // weeks later, as messages that simply never arrive.
        $base = rtrim((string) config('app.url'), '/');

        return array_map(fn (MetaChannel $channel): array => [
            'label' => $channel->label(),
            'url' => $base.route('api.webhooks.meta', $channel->value, false),
            // What to subscribe on Meta's side. Naming it here saves a trip to
            // the documentation for the one detail that decides whether
            // anything is delivered at all.
            'field' => $channel->field(),
        ], MetaChannel::cases());
    }

    /**
     * Whether Meta could actually reach this installation.
     */
    public function isReachable(): bool
    {
        return str_starts_with(rtrim((string) config('app.url'), '/'), 'https://');
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
        ], [
            'token.min' => 'That looks too short to be a Meta access token.',
        ]);

        try {
            $outcome = app(ConnectMetaWithTokenAction::class)(
                $this->token,
                ['page_id' => $this->pageId, 'ad_account_id' => $this->adAccountId, 'waba_id' => $this->wabaId],
                auth()->user(),
            );
        } catch (MetaApiException $exception) {
            $this->error = $exception->userMessage();

            return;
        }

        $this->token = '';
        $this->error = null;
        $this->tokenResults = $outcome['results'];
        $this->testResults = null;
        $this->notice = 'Connected to '.$outcome['account']->name.'.';
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
