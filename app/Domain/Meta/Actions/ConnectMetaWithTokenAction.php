<?php

namespace App\Domain\Meta\Actions;

use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Enums\MetaConnectionStatus;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Connecting Meta by pasting a token, for the installations OAuth cannot reach.
 *
 * The OAuth flow in 12.4 is the right way in and stays the default. It is also
 * unavailable to a great many real installations: Meta will only redirect to a
 * public HTTPS address, so a CRM on a company network, behind a VPN, or on a
 * laptop during setup cannot complete it. Those businesses are given a **system
 * user token** in Business Manager instead — permanent, scoped to named assets,
 * and the credential Meta's own documentation hands out for server-to-server
 * work.
 *
 * Two things make this different from the OAuth path, and both are the point:
 *
 * **Assets are read by id, not discovered.** A system user token frequently
 * cannot answer `me/accounts` or `me/businesses` at all — it is not a person, it
 * administers nothing, it has been *assigned* a page and a WhatsApp account. So
 * each id is fetched directly, which is the call that actually works, and it is
 * also the call the CRM will make every day afterwards. If it succeeds here, the
 * integration works.
 *
 * **One bad id does not lose the rest.** Each asset is reported on
 * individually: a wrong ad account id must not throw away a WhatsApp number that
 * verified perfectly. Somebody pasting five identifiers from a Business Manager
 * page will get one of them wrong, and being told *which* is the difference
 * between a two-minute fix and starting over.
 */
class ConnectMetaWithTokenAction
{
    public function __construct(
        private readonly MetaAuthService $auth,
        private readonly MetaGraphClient $client,
    ) {}

    /**
     * @param  array{page_id?: string|null, ad_account_id?: string|null, waba_id?: string|null}  $assets
     * @return array{account: MetaAccount, results: array<int, array{label: string, ok: bool, detail: string}>}
     *
     * @throws MetaApiException when the token itself is refused
     */
    public function __invoke(string $token, array $assets, User $actor): array
    {
        $inspection = $this->auth->inspect($token);

        if ($inspection['valid'] !== true) {
            throw new MetaApiException(
                'Meta says that token is not valid. Check it was copied whole — they are long, and a '
                .'truncated one fails exactly like a wrong one.'
            );
        }

        $identity = $this->identity($token);

        $account = DB::transaction(function () use ($identity, $token, $inspection, $actor): MetaAccount {
            $account = MetaAccount::query()->firstOrNew(['business_id' => $identity['id']]);

            $account->forceFill([
                'name' => $identity['name'],
                'user_token' => $token,
                'token_expires_at' => $inspection['expires_at'],
                'granted_scopes' => $inspection['scopes'],
                'status' => MetaConnectionStatus::Connected->value,
                'last_error' => null,
                'connected_by_id' => $actor->id,
                'connected_at' => now(),
            ])->save();

            return $account->refresh();
        });

        $results = [$this->tokenResult($inspection)];

        foreach (['page_id' => 'page', 'ad_account_id' => 'adAccount', 'waba_id' => 'whatsApp'] as $key => $method) {
            $id = $this->clean($assets[$key] ?? null);

            if ($id !== null) {
                /** @var array{label: string, ok: bool, detail: string} $result */
                $result = $this->{$method}($account, $id, $token);
                $results[] = $result;
            }
        }

        $account->forceFill(['last_synced_at' => now()])->save();

        return ['account' => $account->refresh(), 'results' => $results];
    }

    /**
     * Who the token belongs to.
     *
     * A business if the token can name one, and the token's own identity if it
     * cannot — a system user answers `me` with itself, which is stable, unique
     * and sufficient, because `business_id` is this row's key rather than
     * something we go on to call Meta with.
     *
     * @return array{id: string, name: string}
     *
     * @throws MetaApiException
     */
    private function identity(string $token): array
    {
        try {
            $response = $this->client->get('me/businesses', ['fields' => 'id,name', 'limit' => 1], $token);
            $first = is_array($response['data'][0] ?? null) ? $response['data'][0] : null;

            if (is_array($first) && isset($first['id'])) {
                return [
                    'id' => (string) $first['id'],
                    'name' => is_string($first['name'] ?? null) ? $first['name'] : 'Meta business',
                ];
            }
        } catch (MetaApiException) {
            // A system user token is routinely refused this call. Not a failure
            // worth stopping for — the assets below are what matter.
        }

        $me = $this->client->get('me', ['fields' => 'id,name'], $token);

        if (! isset($me['id'])) {
            throw new MetaApiException('Meta accepted the token but would not say who it belongs to.');
        }

        return [
            'id' => (string) $me['id'],
            'name' => is_string($me['name'] ?? null) ? $me['name'] : 'Meta connection',
        ];
    }

    /**
     * @param  array{valid: bool, expires_at: Carbon|null, scopes: array<int, string>}  $inspection
     * @return array{label: string, ok: bool, detail: string}
     */
    private function tokenResult(array $inspection): array
    {
        $missing = $this->auth->missingScopes($inspection['scopes']);
        $expiry = $inspection['expires_at'];

        $life = $expiry === null
            ? 'It does not expire.'
            : 'It expires '.$expiry->diffForHumans().'.';

        // A permanent token missing a permission is the failure this whole
        // screen exists to catch early: everything appears connected and one
        // capability is quietly dead.
        return [
            'label' => 'Access token',
            'ok' => $missing === [],
            'detail' => $missing === []
                ? 'Accepted, with every permission this CRM uses. '.$life
                : 'Accepted, but without '.implode(', ', $missing).'. '
                    .'Add those permissions to the system user in Business Manager, or the features needing them will fail. '.$life,
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function page(MetaAccount $account, string $id, string $token): array
    {
        try {
            $row = $this->client->get($id, ['fields' => 'id,name,category,access_token'], $token);
        } catch (MetaApiException $exception) {
            return $this->failure('Facebook Page', $id, $exception);
        }

        $page = MetaPage::query()->firstOrNew(['page_id' => (string) ($row['id'] ?? $id)]);

        $page->forceFill(array_filter([
            'meta_account_id' => $account->id,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : 'Facebook page',
            'category' => is_string($row['category'] ?? null) ? $row['category'] : null,
            // A page token is what Messenger sends and Lead Ads retrieves with.
            // Without one the page is readable and useless, so say so rather
            // than storing a row that looks finished.
            'access_token' => is_string($row['access_token'] ?? null) ? $row['access_token'] : null,
            'last_synced_at' => now(),
        ], fn (mixed $value): bool => $value !== null))->save();

        $hasToken = $page->refresh()->access_token !== null;

        return [
            'label' => 'Facebook Page',
            'ok' => $hasToken,
            'detail' => $hasToken
                ? $page->name.' is connected, with a page token.'
                : $page->name.' was found, but Meta returned no page token for it. Messenger and Lead Ads both need one: '
                    .'give the system user a role on the page, or paste the page access token under Settings → Meta.',
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function adAccount(MetaAccount $account, string $id, string $token): array
    {
        // Meta's ad account ids carry an `act_` prefix on the API and not in
        // Business Manager's own interface, which is where somebody copies it
        // from. Accepting both is not indulgence; it is the difference between
        // working and a 404 nobody can explain.
        $path = str_starts_with($id, 'act_') ? $id : 'act_'.$id;

        try {
            $row = $this->client->get($path, [
                'fields' => 'account_id,name,currency,timezone_name,account_status',
            ], $token);
        } catch (MetaApiException $exception) {
            return $this->failure('Ad account', $id, $exception);
        }

        $adAccount = MetaAdAccount::query()->firstOrNew([
            'ad_account_id' => is_string($row['account_id'] ?? null) ? $row['account_id'] : ltrim($path, 'act_'),
        ]);

        $adAccount->forceFill([
            'meta_account_id' => $account->id,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : 'Ad account',
            'currency' => is_string($row['currency'] ?? null) ? $row['currency'] : null,
            'timezone' => is_string($row['timezone_name'] ?? null) ? $row['timezone_name'] : null,
            'status' => isset($row['account_status']) ? (string) $row['account_status'] : null,
            'last_synced_at' => now(),
        ])->save();

        return [
            'label' => 'Ad account',
            'ok' => true,
            'detail' => $adAccount->name.' is connected'
                .($adAccount->currency !== null ? ', reporting in '.$adAccount->currency : '').'.',
        ];
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function whatsApp(MetaAccount $account, string $id, string $token): array
    {
        try {
            $row = $this->client->get($id, ['fields' => 'id,name,message_template_namespace'], $token);
        } catch (MetaApiException $exception) {
            return $this->failure('WhatsApp business account', $id, $exception);
        }

        $waba = WhatsAppBusinessAccount::query()->firstOrNew(['waba_id' => (string) ($row['id'] ?? $id)]);

        $waba->forceFill([
            'meta_account_id' => $account->id,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : 'WhatsApp business account',
            'message_template_namespace' => is_string($row['message_template_namespace'] ?? null)
                ? $row['message_template_namespace']
                : null,
            'access_token' => $token,
            'last_synced_at' => now(),
        ])->save();

        $numbers = $this->phoneNumbers($waba->refresh(), $token);

        return [
            'label' => 'WhatsApp business account',
            'ok' => $numbers > 0,
            'detail' => $numbers > 0
                ? $waba->name.' is connected, with '.$numbers.' number'.($numbers === 1 ? '' : 's').'.'
                : $waba->name.' is connected, but it has no phone numbers. Nothing can be sent or received until one is '
                    .'added to it in Business Manager.',
        ];
    }

    /**
     * @throws MetaApiException
     */
    private function phoneNumbers(WhatsAppBusinessAccount $waba, string $token): int
    {
        $seen = 0;

        foreach ($this->client->paginate($waba->waba_id.'/phone_numbers', [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier',
            'limit' => 50,
        ], $token) as $row) {
            $id = is_string($row['id'] ?? null) ? $row['id'] : null;

            if ($id === null) {
                continue;
            }

            $number = WhatsAppPhoneNumber::query()->updateOrCreate(['phone_number_id' => $id], [
                'whatsapp_business_account_id' => $waba->id,
                'display_number' => is_string($row['display_phone_number'] ?? null) ? $row['display_phone_number'] : $id,
                'verified_name' => is_string($row['verified_name'] ?? null) ? $row['verified_name'] : null,
                'quality_rating' => is_string($row['quality_rating'] ?? null) ? $row['quality_rating'] : null,
                'messaging_limit' => is_string($row['messaging_limit_tier'] ?? null) ? $row['messaging_limit_tier'] : null,
            ]);

            $seen++;

            // The first number connected becomes the one we send from, so a
            // business with exactly one — which is nearly all of them — never
            // has to find the setting. A second number changes nothing here;
            // choosing between them stays a decision somebody makes.
            if (WhatsAppPhoneNumber::query()->where('is_default', true)->doesntExist()) {
                $number->forceFill(['is_default' => true])->save();
            }
        }

        return $seen;
    }

    /**
     * @return array{label: string, ok: bool, detail: string}
     */
    private function failure(string $label, string $id, MetaApiException $exception): array
    {
        return [
            'label' => $label,
            'ok' => false,
            // The id is echoed back because the commonest cause by far is a
            // digit lost in copying, and seeing it next to the refusal is how
            // somebody spots that.
            'detail' => 'Meta refused '.$id.': '.$exception->userMessage(),
        ];
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
