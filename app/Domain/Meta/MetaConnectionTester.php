<?php

namespace App\Domain\Meta;

use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAccount;
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
        return in_array($scope, $granted, true)
            ? $this->result($key, $label, true, 'Granted.')
            : $this->result($key, $label, false, 'Meta has not granted '.$scope.'. Reconnect and allow it, or check whether the app has been reviewed for it.');
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function pages(MetaAccount $account, string $token): array
    {
        try {
            $response = $this->client->get('me/accounts', ['fields' => 'id,name', 'limit' => 5], $token);
        } catch (MetaApiException $exception) {
            return $this->result('pages', 'Facebook Pages', false, $exception->userMessage());
        }

        $count = is_array($response['data'] ?? null) ? count($response['data']) : 0;

        return $count > 0
            ? $this->result('pages', 'Facebook Pages', true, $count.' page'.($count === 1 ? '' : 's').' reachable.')
            : $this->result('pages', 'Facebook Pages', false, 'No pages are available to this account. Check that it administers one.');
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string}
     */
    private function adAccounts(MetaAccount $account, string $token): array
    {
        try {
            $response = $this->client->get('me/adaccounts', ['fields' => 'account_id', 'limit' => 5], $token);
        } catch (MetaApiException $exception) {
            return $this->result('ad_accounts', 'Ad accounts', false, $exception->userMessage());
        }

        $count = is_array($response['data'] ?? null) ? count($response['data']) : 0;

        return $count > 0
            ? $this->result('ad_accounts', 'Ad accounts', true, $count.' ad account'.($count === 1 ? '' : 's').' reachable.')
            : $this->result('ad_accounts', 'Ad accounts', false, 'No ad accounts are available. Campaign figures will be empty.');
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
