<?php

namespace App\Domain\Meta\Actions;

use App\Domain\Meta\Enums\MetaConnectionStatus;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Disconnects Meta, and means it.
 *
 * Three things happen, in this order and for these reasons:
 *
 * 1. **The permission is revoked at Meta.** Clearing our copy of a token does
 *    not stop the token working — it stops *us* using it. Anybody holding a copy
 *    from a leaked backup would still have access to the customer's page. Asking
 *    Meta to revoke is the only thing that actually ends it.
 * 2. **Every stored token is cleared**, on the account, its pages and its
 *    WhatsApp accounts. A revoked token is useless, and useless credentials in a
 *    database are a liability with no upside.
 * 3. **The app's own credentials go too.** The app ID, the secret and the
 *    webhook verify token are what this installation uses to *be* the Meta
 *    application, and somebody disconnecting is finished with it — leaving them
 *    behind means the setup screen goes on reporting itself half configured,
 *    and a verify token nobody knows is still live is a subscription somebody
 *    else's deployment could satisfy.
 * 4. **The rows stay.** The pages, the ad accounts and the numbers keep their
 *    ids and their names, because every lead in the CRM attributed to a form on
 *    one of those pages still points here. Deleting them to look tidy would turn
 *    a year of marketing history into orphaned ids.
 *
 * A revoke that fails does not stop the disconnection. Meta being unreachable is
 * not a reason to leave an administrator connected to something they have said
 * they want nothing more to do with; the failure is recorded and the local side
 * is cleared regardless.
 */
class DisconnectMetaAccountAction
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly SettingsManager $settings,
    ) {}

    public function __invoke(MetaAccount $account): void
    {
        $revokeError = $this->revoke($account);

        DB::transaction(function () use ($account, $revokeError): void {
            $account->pages()->update(['access_token' => null, 'is_subscribed' => false, 'subscribed_at' => null]);
            $account->whatsAppAccounts()->update(['access_token' => null, 'is_subscribed' => false]);

            // The application's own credentials, not this connection's. See the
            // class comment: a disconnect that leaves them behind reports a
            // setup half done and a webhook token still live.
            foreach (['app_id', 'app_secret', 'verify_token'] as $key) {
                $this->settings->forget(MetaConfiguration::GROUP.'.'.$key);
            }

            $account->forceFill([
                'user_token' => null,
                'token_expires_at' => null,
                'granted_scopes' => null,
                'status' => MetaConnectionStatus::Disconnected->value,
                // Recorded rather than shown: whoever reconnects wants to know
                // the old permission may still be live at Meta's end.
                'last_error' => $revokeError,
            ])->save();
        });
    }

    /**
     * Ask Meta to drop the permission, and report what went wrong if anything.
     */
    private function revoke(MetaAccount $account): ?string
    {
        $token = $account->token();

        if ($token === null) {
            return null;
        }

        try {
            // Graph takes a DELETE as a POST carrying `method=delete`, which is
            // its own documented override and what every SDK sends.
            $this->client->post('me/permissions', ['method' => 'delete'], $token->value);

            return null;
        } catch (MetaApiException $exception) {
            return 'Meta did not confirm the permission was revoked: '.$exception->getMessage();
        } catch (Throwable) {
            return 'Meta could not be reached to revoke the permission. Remove this app from your Meta account settings as well.';
        }
    }
}
