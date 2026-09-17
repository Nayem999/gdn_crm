<?php

namespace App\Domain\Meta;

use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use Throwable;

/**
 * §33's test tool: does each capability actually work, one line each.
 *
 * **Every check runs, whatever the ones before it did.** A tool that stopped at
 * the first failure would report "authentication failed" on an installation
 * whose real problem is one missing permission, and the administrator would
 * reconnect three times before finding out. Each capability is asked
 * independently and answers for itself.
 *
 * Each result says what to do, not what went wrong in Meta's words. "Missing
 * the leads_retrieval permission — reconnect and grant it" is actionable;
 * "(#200) Requires extended permission" is a search engine query.
 */
class MetaConnectionTester
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly MetaAuthService $auth,
    ) {}

    /**
     * Every capability, in the order they matter: nothing else can work if the
     * first one does not.
     *
     * @return array<int, array{key: string, label: string, passed: bool, detail: string}>
     */
    public function run(MetaAccount $account): array
    {
        $token = $account->token();

        if ($token === null) {
            return [$this->result('auth', 'Meta authentication', false, 'No access token is stored. Connect the account.')];
        }

        $results = [$this->authentication($account, $token->value)];

        // Asked once and shared: every capability below is gated by a scope, and
        // one debug_token call answers for all of them.
        $granted = $account->granted_scopes ?? [];

        $results[] = $this->scope($granted, 'leads_retrieval', 'leads', 'Lead Ads access');
        $results[] = $this->scope($granted, 'pages_messaging', 'messenger', 'Messenger access');
        $results[] = $this->scope($granted, 'ads_read', 'ads', 'Advertising figures');
        $results[] = $this->scope($granted, 'whatsapp_business_messaging', 'whatsapp', 'WhatsApp messaging');

        $results[] = $this->pages($account, $token->value);
        $results[] = $this->adAccounts($account, $token->value);
        $results[] = $this->whatsAppNumber($account);
        $results[] = $this->webhooks();

        return $results;
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function authentication(MetaAccount $account, string $token): array
    {
        try {
            $inspection = $this->auth->inspect($token);
        } catch (MetaApiException $exception) {
            return $this->result('auth', 'Meta authentication', false, $exception->userMessage());
        } catch (Throwable) {
            return $this->result('auth', 'Meta authentication', false, 'Meta could not be reached.');
        }

        if ($inspection['valid'] !== true) {
            return $this->result('auth', 'Meta authentication', false, 'Meta says the stored authorisation is no longer valid. Reconnect.');
        }

        $expiry = $inspection['expires_at'];

        return $this->result('auth', 'Meta authentication', true, match (true) {
            $expiry === null => 'Connected to '.$account->name.'. The authorisation does not expire.',
            // A warning on a passing check, because it is going to fail on a
            // date and the only useful time to say so is before then.
            $expiry->lte(now()->addDays(7)) => 'Connected, but the authorisation expires '.$expiry->diffForHumans().'. Reconnect soon.',
            default => 'Connected to '.$account->name.'. Authorisation valid until '.$expiry->toFormattedDateString().'.',
        });
    }

    /**
     * @param  array<int, string>  $granted
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function scope(array $granted, string $scope, string $key, string $label): array
    {
        if (in_array($scope, $granted, true)) {
            return $this->result($key, $label, true, 'Granted.');
        }

        // A permission this installation deliberately does not ask for is not
        // missing. An app still awaiting review for `leads_retrieval` has to
        // leave it out or Meta refuses the whole sign-in, and a red line nobody
        // can ever clear teaches people to ignore the whole panel.
        if (! in_array($scope, app(MetaConfiguration::class)->scopes(), true)) {
            return $this->result($key, $label, false,
                'Not requested by this installation. Add '.$scope.' under Settings → Meta once Meta has approved the app for it.');
        }

        return $this->result($key, $label, false, 'Meta has not granted '.$scope.'. Reconnect and allow it, or check whether the app has been reviewed for it.');
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function pages(MetaAccount $account, string $token): array
    {
        // The pages this CRM actually holds, asked for one by one, rather than
        // "what can this token list". The two differ for a system user token,
        // which is assigned a page and administers nothing: `me/accounts` comes
        // back empty and the page it was given works perfectly. Reading the
        // stored row is also the call every later feature makes, so a pass here
        // means Messenger and Lead Ads will work rather than merely that Meta
        // was polite.
        $pages = $account->pages;

        if ($pages->isEmpty()) {
            try {
                $response = $this->client->get('me/accounts', ['fields' => 'id,name', 'limit' => 5], $token);
            } catch (MetaApiException $exception) {
                return $this->result('pages', 'Facebook Pages', false, $exception->userMessage());
            }

            $count = is_array($response['data'] ?? null) ? count($response['data']) : 0;

            return $count > 0
                ? $this->result('pages', 'Facebook Pages', true, $count.' page'.($count === 1 ? '' : 's').' reachable, none stored yet — re-read from Meta.')
                : $this->result('pages', 'Facebook Pages', false, 'No pages are available to this account. Check that it administers one, or add the page id when connecting with a token.');
        }

        $unreachable = [];

        foreach ($pages as $page) {
            try {
                $this->client->get($page->page_id, ['fields' => 'id'], $page->access_token ?? $token);
            } catch (MetaApiException) {
                $unreachable[] = $page->name;
            }
        }

        return $unreachable === []
            ? $this->result('pages', 'Facebook Pages', true, $pages->count().' page'.($pages->count() === 1 ? '' : 's').' reachable.')
            : $this->result('pages', 'Facebook Pages', false, 'Meta would not answer for '.implode(', ', $unreachable).'. The page token may have been revoked.');
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function adAccounts(MetaAccount $account, string $token): array
    {
        $adAccounts = $account->adAccounts;

        if ($adAccounts->isEmpty()) {
            try {
                $response = $this->client->get('me/adaccounts', ['fields' => 'account_id', 'limit' => 5], $token);
            } catch (MetaApiException $exception) {
                return $this->result('ad_accounts', 'Ad accounts', false, $exception->userMessage());
            }

            $count = is_array($response['data'] ?? null) ? count($response['data']) : 0;

            return $count > 0
                ? $this->result('ad_accounts', 'Ad accounts', true, $count.' ad account'.($count === 1 ? '' : 's').' reachable, none stored yet — re-read from Meta.')
                : $this->result('ad_accounts', 'Ad accounts', false, 'No ad accounts are available. Campaign figures will be empty.');
        }

        $unreachable = [];

        foreach ($adAccounts as $adAccount) {
            try {
                $this->client->get($adAccount->graphId(), ['fields' => 'account_id'], $token);
            } catch (MetaApiException) {
                $unreachable[] = $adAccount->name;
            }
        }

        return $unreachable === []
            ? $this->result('ad_accounts', 'Ad accounts', true, $adAccounts->count().' ad account'.($adAccounts->count() === 1 ? '' : 's').' reachable.')
            : $this->result('ad_accounts', 'Ad accounts', false, 'Meta would not answer for '.implode(', ', $unreachable).'. Check the system user has a role on it.');
    }

    /**
     * Whether the number this CRM sends from can actually be reached.
     *
     * Its own check rather than part of the scope list, because the scope being
     * granted and the number being usable are different facts: a token may carry
     * `whatsapp_business_messaging` and still have no number assigned to it,
     * which fails at the first message rather than here.
     *
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function whatsAppNumber(MetaAccount $account): array
    {
        $numbers = $account->whatsAppAccounts->flatMap(fn (WhatsAppBusinessAccount $waba) => $waba->phoneNumbers);
        $sending = $numbers->firstWhere('is_default', true) ?? $numbers->first();

        if ($sending === null) {
            return $this->result('whatsapp_number', 'WhatsApp number', false,
                'No WhatsApp number is connected. Add the business account id when connecting, or re-read from Meta.');
        }

        $token = $sending->businessAccount?->access_token;

        if (! is_string($token) || $token === '') {
            return $this->result('whatsapp_number', 'WhatsApp number', false,
                $sending->display_number.' has no stored token. Reconnect the WhatsApp business account.');
        }

        try {
            $this->client->get($sending->phone_number_id, ['fields' => 'display_phone_number'], $token);
        } catch (MetaApiException $exception) {
            return $this->result('whatsapp_number', 'WhatsApp number', false,
                'Meta would not answer for '.$sending->display_number.': '.$exception->userMessage());
        }

        return $this->result('whatsapp_number', 'WhatsApp number', true,
            'Sending from '.$sending->display_number.'.');
    }

    /**
     * Whether a webhook could be verified if Meta called right now.
     *
     * Local, because it is a local fact: Meta cannot tell us whether our verify
     * token is set, and an integration whose webhook was never configured looks
     * identical from Meta's side to one that is working.
     *
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function webhooks(): array
    {
        return app(MetaConfiguration::class)->canVerifyWebhooks()
            ? $this->result('webhooks', 'Webhook verification', true, 'A verify token is configured.')
            : $this->result('webhooks', 'Webhook verification', false, 'No webhook verify token is set under Settings → Meta. Leads and messages will not arrive on their own.');
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function result(string $key, string $label, bool $passed, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'passed' => $passed, 'detail' => $detail];
    }
}
