<?php

namespace App\Domain\Meta\Auth;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Asks Meta what it thinks of every token this installation holds.
 *
 * A stored token is a claim about the past. It was accepted on the day somebody
 * pasted it, and until something fails, nothing asks again — so a token revoked
 * in Business Settings on Tuesday is discovered on Friday by an agent whose
 * message would not send, reading Meta's own words: "The user has not
 * authorized application 1772978827247269." That sentence names an app and
 * reads like a configuration mistake. It is not. It means a credential died.
 *
 * `debug_token` answers all of it — which app issued the token, whether it is a
 * user, page or system user token, whether Meta still accepts it, and why not —
 * and it answers 200 with the refusal inside rather than failing, which is why
 * the reason is worth reading instead of inferring.
 *
 * Every token is asked about independently. One dead credential says nothing
 * about the others: here, a page token that works perfectly sits beside a
 * system user token that has been revoked, and a check that stopped at the
 * first failure would have reported the wrong one.
 */
class MetaTokenAudit
{
    public function __construct(
        private readonly MetaAuthService $auth,
        private readonly MetaConfiguration $config,
    ) {}

    /**
     * Check every token on this account and remember the answers.
     *
     * @return array<int, array{label: string, type: string|null, app_id: string|null, valid: bool, detail: string}>
     */
    public function run(MetaAccount $account): array
    {
        $results = [];

        foreach ($this->holders($account) as [$label, $model, $token]) {
            $results[] = $this->check($label, $model, $token);
        }

        return $results;
    }

    /**
     * Everything that holds a token of its own, with what to call it.
     *
     * The ad account is absent on purpose: it has no token, it borrows the
     * account's, so auditing it would report the same credential twice under
     * two names and imply a second thing to fix.
     *
     * @return array<int, array{0: string, 1: Model, 2: string|null}>
     */
    private function holders(MetaAccount $account): array
    {
        $holders = [['Meta account', $account, $account->token()?->value]];

        foreach ($account->pages as $page) {
            /** @var MetaPage $page */
            $holders[] = ['Page: '.$page->name, $page, $page->access_token];
        }

        foreach ($account->whatsAppAccounts as $waba) {
            /** @var WhatsAppBusinessAccount $waba */
            $holders[] = ['WhatsApp: '.$waba->name, $waba, $waba->access_token];
        }

        return $holders;
    }

    /**
     * @return array{label: string, type: string|null, app_id: string|null, valid: bool, detail: string}
     */
    private function check(string $label, Model $model, ?string $token): array
    {
        if (! is_string($token) || $token === '') {
            // Not asked about, because there is nothing to ask. Recorded all
            // the same: "no token" and "not checked" are different states and
            // the screen should not show them alike.
            $this->remember($model, null, null, 'No token is stored.');

            return $this->result($label, null, null, false, 'No token is stored.');
        }

        try {
            $inspection = $this->auth->inspect($token);
        } catch (MetaApiException $exception) {
            // The app token could not be obtained, or Meta refused the
            // inspection itself. Nothing is learnt about this token, so
            // nothing about it is overwritten.
            return $this->result($label, null, null, false, $exception->userMessage());
        } catch (Throwable) {
            return $this->result($label, null, null, false, 'Meta could not be reached.');
        }

        $detail = $this->detail($inspection);

        $this->remember(
            $model,
            $inspection['type'],
            $inspection['app_id'],
            $inspection['valid'] === true ? null : $detail,
        );

        return $this->result($label, $inspection['type'], $inspection['app_id'], $inspection['valid'] === true, $detail);
    }

    /**
     * What to tell somebody, in the order the facts matter.
     *
     * @param  array{valid: bool, expires_at: Carbon|null, scopes: array<int, string>, app_id: string|null, type: string|null, error: string|null}  $inspection
     */
    private function detail(array $inspection): string
    {
        $configured = $this->config->appId();

        // Checked before validity, because a token from another app is refused
        // for a reason no amount of reconnecting will fix: the proof sent with
        // every call is keyed with the configured app's secret, so Meta is
        // being shown a signature from the wrong app.
        if ($inspection['app_id'] !== null && $configured !== null && $inspection['app_id'] !== $configured) {
            return 'Issued by app '.$inspection['app_id'].', but this installation is configured with app '
                .$configured.'. Every call signs with the configured app\'s secret, so Meta will refuse this token. '
                .'Use a token from app '.$configured.', or change the app id under Settings → Meta.';
        }

        if ($inspection['valid'] !== true) {
            return 'Meta no longer accepts this token'
                .($inspection['error'] === null ? '. ' : ': '.rtrim($inspection['error'], '.').'. ')
                .$this->remedy($inspection['type']);
        }

        $expiry = $inspection['expires_at'];

        return match (true) {
            $expiry === null => 'Accepted. Does not expire.',
            $expiry->isPast() => 'Expired '.$expiry->diffForHumans().'. '.$this->remedy($inspection['type']),
            // A warning on a passing check, because the date is known and the
            // only useful time to mention it is before it arrives.
            $expiry->lte(now()->addDays(7)) => 'Accepted, but expires '.$expiry->diffForHumans().'. '.$this->remedy($inspection['type']),
            default => 'Accepted. Valid until '.$expiry->toFormattedDateString().'.',
        };
    }

    /**
     * What to do about a dead token, which depends on what kind it is.
     *
     * "Reconnect" is wrong for two of the three: a system user token is
     * generated in Business Settings and pasted, and a page token comes back
     * with the page rather than by itself.
     */
    private function remedy(?string $type): string
    {
        return match ($type) {
            'SYSTEM_USER' => 'Generate a new token in Business settings → Users → System users, with the permissions this '
                .'integration needs, and paste it in here. Check the system user still has access to the asset.',
            'PAGE' => 'Reconnect the page, or paste a new page access token.',
            'USER' => 'Reconnect with Facebook under Settings → Meta.',
            default => 'Paste a new token under Settings → Meta.',
        };
    }

    /**
     * Write what Meta said onto the row that holds the token.
     */
    private function remember(Model $model, ?string $type, ?string $appId, ?string $error): void
    {
        $model->forceFill([
            'token_type' => $type,
            'token_app_id' => $appId,
            // Truncated rather than refused: this is Meta's prose in a column
            // with a length, and losing the end of a sentence is better than
            // losing the whole check.
            'token_error' => $error === null ? null : mb_substr($error, 0, 255),
            'token_checked_at' => now(),
        ])->save();
    }

    /**
     * @return array{label: string, type: string|null, app_id: string|null, valid: bool, detail: string}
     */
    private function result(string $label, ?string $type, ?string $appId, bool $valid, string $detail): array
    {
        return ['label' => $label, 'type' => $type, 'app_id' => $appId, 'valid' => $valid, 'detail' => $detail];
    }
}
