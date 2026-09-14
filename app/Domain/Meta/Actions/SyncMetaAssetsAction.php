<?php

namespace App\Domain\Meta\Actions;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;

/**
 * Reads what the connected business owns, so the wizard has something to offer.
 *
 * Everything is **upserted, never replaced**. A page that has gone from Meta's
 * answer — because an administrator's access changed, or because Meta was having
 * a bad minute — keeps its row here rather than being deleted along with its
 * token and its subscription. Deleting it would take the lead attribution
 * pointing at it, and a transient permission blip would silently cost a month of
 * marketing history.
 *
 * What it does record is which assets were seen this time, so the wizard can
 * show a page that is no longer available as such rather than as a live choice.
 */
class SyncMetaAssetsAction
{
    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @return array{pages: int, ad_accounts: int, whatsapp: int}
     *
     * @throws MetaApiException
     */
    public function __invoke(MetaAccount $account): array
    {
        $token = $account->token();

        if ($token === null) {
            throw new MetaApiException('That connection has no access token. Reconnect it.');
        }

        $counts = [
            'pages' => $this->pages($account, $token->value),
            'ad_accounts' => $this->adAccounts($account, $token->value),
            'whatsapp' => $this->whatsApp($account, $token->value),
        ];

        $account->forceFill(['last_synced_at' => now()])->save();

        return $counts;
    }

    /**
     * @throws MetaApiException
     */
    private function pages(MetaAccount $account, string $token): int
    {
        $seen = 0;

        // `me/accounts` rather than the business's owned pages: the token is a
        // person's, and this is the list that person can actually act on. A page
        // the business owns but this administrator cannot manage would be
        // offered and then fail on the first call.
        foreach ($this->client->paginate('me/accounts', ['fields' => 'id,name,category,access_token', 'limit' => 100], $token) as $row) {
            $id = $this->id($row);

            if ($id === null) {
                continue;
            }

            $page = MetaPage::query()->firstOrNew(['page_id' => $id]);

            $page->forceFill(array_filter([
                'meta_account_id' => $account->id,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : $page->name ?? 'Facebook page',
                'category' => is_string($row['category'] ?? null) ? $row['category'] : null,
                // Only overwrite the stored token when Meta actually sent one:
                // some field selections omit it, and writing null would silently
                // unconnect a working page.
                'access_token' => is_string($row['access_token'] ?? null) ? $row['access_token'] : null,
                'last_synced_at' => now(),
            ], fn (mixed $value): bool => $value !== null))->save();

            $seen++;
        }

        return $seen;
    }

    /**
     * @throws MetaApiException
     */
    private function adAccounts(MetaAccount $account, string $token): int
    {
        $seen = 0;

        foreach ($this->client->paginate('me/adaccounts', [
            'fields' => 'account_id,name,currency,timezone_name,account_status',
            'limit' => 100,
        ], $token) as $row) {
            $id = is_string($row['account_id'] ?? null) ? $row['account_id'] : $this->id($row);

            if ($id === null) {
                continue;
            }

            $adAccount = MetaAdAccount::query()->firstOrNew(['ad_account_id' => $id]);

            $adAccount->forceFill([
                'meta_account_id' => $account->id,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : 'Ad account',
                // Per account, not per installation: an agency running one
                // client in USD and another in BDT must never have the two
                // added together.
                'currency' => is_string($row['currency'] ?? null) ? $row['currency'] : null,
                'timezone' => is_string($row['timezone_name'] ?? null) ? $row['timezone_name'] : null,
                'status' => isset($row['account_status']) ? (string) $row['account_status'] : null,
                'last_synced_at' => now(),
            ])->save();

            $seen++;
        }

        return $seen;
    }

    /**
     * @throws MetaApiException
     */
    private function whatsApp(MetaAccount $account, string $token): int
    {
        $seen = 0;

        foreach ($this->client->paginate(
            $account->business_id.'/owned_whatsapp_business_accounts',
            ['fields' => 'id,name,timezone_id,message_template_namespace', 'limit' => 50],
            $token
        ) as $row) {
            $id = $this->id($row);

            if ($id === null) {
                continue;
            }

            $waba = WhatsAppBusinessAccount::query()->firstOrNew(['waba_id' => $id]);

            $waba->forceFill([
                'meta_account_id' => $account->id,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : 'WhatsApp business account',
                'message_template_namespace' => is_string($row['message_template_namespace'] ?? null)
                    ? $row['message_template_namespace']
                    : null,
                'access_token' => $token,
                'last_synced_at' => now(),
            ])->save();

            $this->phoneNumbers($waba->refresh(), $token);

            $seen++;
        }

        return $seen;
    }

    /**
     * @throws MetaApiException
     */
    private function phoneNumbers(WhatsAppBusinessAccount $waba, string $token): void
    {
        foreach ($this->client->paginate($waba->waba_id.'/phone_numbers', [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier',
            'limit' => 50,
        ], $token) as $row) {
            $id = $this->id($row);

            if ($id === null) {
                continue;
            }

            WhatsAppPhoneNumber::query()->updateOrCreate(['phone_number_id' => $id], [
                'whatsapp_business_account_id' => $waba->id,
                'display_number' => is_string($row['display_phone_number'] ?? null) ? $row['display_phone_number'] : $id,
                'verified_name' => is_string($row['verified_name'] ?? null) ? $row['verified_name'] : null,
                'quality_rating' => is_string($row['quality_rating'] ?? null) ? $row['quality_rating'] : null,
                'messaging_limit' => is_string($row['messaging_limit_tier'] ?? null) ? $row['messaging_limit_tier'] : null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function id(array $row): ?string
    {
        $id = $row['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
