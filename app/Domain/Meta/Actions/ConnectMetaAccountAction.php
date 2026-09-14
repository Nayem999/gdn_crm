<?php

namespace App\Domain\Meta\Actions;

use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Auth\MetaToken;
use App\Domain\Meta\Enums\MetaConnectionStatus;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns an authorised token into a connection.
 *
 * Everything it writes is inside one transaction, because a half-connected
 * account is worse than none: a row with a business id and no token looks
 * connected on the settings screen and fails on every call, and the way out is
 * not obvious to whoever finds it.
 *
 * The token is inspected before anything is stored. Meta will happily hand back
 * a token for an app that was granted three of the nine permissions we asked
 * for, and finding that out here — where it can be shown next to a "reconnect
 * and grant these" instruction — is the difference between a five-minute fix
 * and a fortnight of leads that never arrived.
 *
 * Reconnecting an existing business updates it in place rather than making a
 * second row. Its pages, forms and every lead already attributed to them stay
 * attached, which is the whole point of reconnecting rather than starting again.
 */
class ConnectMetaAccountAction
{
    public function __construct(
        private readonly MetaAuthService $auth,
        private readonly MetaGraphClient $client,
    ) {}

    /**
     * @throws MetaApiException when Meta refuses the token or it carries nothing usable
     */
    public function __invoke(MetaToken $token, User $actor): MetaAccount
    {
        $inspection = $this->auth->inspect($token->value);

        if ($inspection['valid'] !== true) {
            throw new MetaApiException('Meta says that authorisation is not valid. Try connecting again.');
        }

        $business = $this->business($token);

        return DB::transaction(function () use ($business, $token, $inspection, $actor): MetaAccount {
            $account = MetaAccount::query()->firstOrNew(['business_id' => $business['id']]);

            $account->forceFill([
                'name' => $business['name'],
                'user_token' => $token->value,
                // Meta's own expiry, not ours: the exchange reports one and
                // debug_token reports one, and they can disagree. The token's
                // own is what the calls will actually obey.
                'token_expires_at' => $inspection['expires_at'] ?? $token->expiresAt,
                'granted_scopes' => $inspection['scopes'],
                'status' => MetaConnectionStatus::Connected->value,
                'last_error' => null,
                'connected_by_id' => $actor->id,
                'connected_at' => now(),
            ])->save();

            return $account->refresh();
        });
    }

    /**
     * Which business this token belongs to.
     *
     * Asked of Meta rather than taken from a form: a business id typed in by
     * hand is a business id that can be wrong, and the failure it produces —
     * calls that succeed and return nothing — looks like an empty account
     * rather than a mistake.
     *
     * @return array{id: string, name: string}
     *
     * @throws MetaApiException
     */
    private function business(MetaToken $token): array
    {
        $response = $this->client->get('me/businesses', ['fields' => 'id,name', 'limit' => 2], $token->value);

        /** @var array<int, mixed> $rows */
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];

        $first = $rows[0] ?? null;

        if (! is_array($first) || ! isset($first['id'])) {
            throw new MetaApiException(
                'That Meta account does not administer a business. Connect with an account that has one, '
                .'or create a business in Meta Business Suite first.'
            );
        }

        return [
            'id' => (string) $first['id'],
            'name' => is_string($first['name'] ?? null) ? $first['name'] : 'Meta business',
        ];
    }
}
