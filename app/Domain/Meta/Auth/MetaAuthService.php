<?php

namespace App\Domain\Meta\Auth;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\MetaApiVersion;
use App\Domain\Meta\MetaConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The OAuth half of connecting Meta.
 *
 * Three things happen here and nowhere else: the authorisation URL is built, the
 * code Meta hands back is exchanged for a token, and that token is exchanged for
 * a long-lived one. Keeping them together is what makes the rules below
 * enforceable in one place.
 *
 * **The state parameter is not decoration.** It is the only thing standing
 * between this installation and somebody else's Meta account being connected to
 * it by a link in an email — an attacker who can make an administrator's browser
 * visit our callback with *their* code gets our CRM reading *their* leads, or
 * worse, writes their page's token into our database. The state is random, kept
 * in the session, and compared on return; a mismatch is refused outright.
 *
 * **The short-lived token is never stored.** Meta's first answer lasts about an
 * hour; storing it would give an installation that works for an afternoon and
 * fails overnight, which is the hardest kind of failure to diagnose. It is
 * exchanged immediately and only the long-lived token is written.
 */
class MetaAuthService
{
    /**
     * Where the state lives between the redirect out and the callback back.
     */
    public const STATE_KEY = 'meta.oauth.state';

    /**
     * What the integration asks for, and why each is needed.
     *
     * Asked for together rather than incrementally: Meta shows one consent
     * screen, and a second round trip to add a permission is a second chance for
     * an administrator to decline. Every one of these is reviewed by Meta before
     * a live app may request it.
     *
     * @var array<int, string>
     */
    public const SCOPES = [
        // Which businesses, pages and ad accounts this person administers.
        'business_management',
        // Read the lead forms and their submissions.
        'leads_retrieval',
        'pages_show_list',
        'pages_manage_metadata',
        'pages_read_engagement',
        // Messenger conversations.
        'pages_messaging',
        // Campaign, ad set, ad and insight figures.
        'ads_read',
        // WhatsApp Cloud API.
        'whatsapp_business_management',
        'whatsapp_business_messaging',
    ];

    public function __construct(
        private readonly MetaConfiguration $config,
        private readonly MetaGraphClient $client,
    ) {}

    /**
     * A fresh state token. The caller puts it in the session and hands it back
     * to `authorizeUrl()`.
     */
    public function newState(): string
    {
        return Str::random(40);
    }

    /**
     * Where to send the administrator to authorise us.
     *
     * @throws MetaApiException when the app is not configured
     */
    public function authorizeUrl(string $state, string $redirectUri): string
    {
        $appId = $this->config->appId();

        if ($appId === null) {
            throw new MetaApiException('The Meta app is not configured. Add the app ID and secret under Settings → Meta.');
        }

        return 'https://www.facebook.com/'.MetaApiVersion::resolve($this->config->version()).'/dialog/oauth?'
            .http_build_query([
                'client_id' => $appId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'scope' => implode(',', self::SCOPES),
                // Code, not token: the implicit flow would put a credential in a
                // URL fragment, which is a credential in the browser's history.
                'response_type' => 'code',
            ]);
    }

    /**
     * Turn Meta's code into a token that lasts.
     *
     * Two calls rather than one, because Meta's first answer is short-lived and
     * there is no way to ask for a long one directly.
     *
     * @throws MetaApiException
     */
    public function exchangeCode(string $code, string $redirectUri): MetaToken
    {
        $appId = $this->config->appId();
        $appSecret = $this->config->appSecret();

        if ($appId === null || $appSecret === null) {
            throw new MetaApiException('The Meta app is not configured.');
        }

        $short = $this->client->get('oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        $shortToken = $this->tokenFrom($short);

        $long = $this->client->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortToken,
        ]);

        return new MetaToken(
            value: $this->tokenFrom($long),
            expiresAt: $this->expiryFrom($long),
        );
    }

    /**
     * What Meta says about a token: whether it is valid, when it dies, and which
     * permissions were actually granted.
     *
     * The granted list matters more than it looks. A person can decline
     * individual permissions on the consent screen, and nothing fails until the
     * first call that needed one — weeks later, as a capability that mysteriously
     * stopped working.
     *
     * @return array{valid: bool, expires_at: Carbon|null, scopes: array<int, string>}
     *
     * @throws MetaApiException
     */
    public function inspect(string $token): array
    {
        $data = $this->client->debugToken($token);

        $expires = isset($data['expires_at']) ? (int) $data['expires_at'] : 0;

        /** @var array<int, string> $scopes */
        $scopes = is_array($data['scopes'] ?? null) ? array_values(array_filter($data['scopes'], 'is_string')) : [];

        return [
            'valid' => ($data['is_valid'] ?? false) === true,
            // Zero means "never expires", which is what a system user token
            // does. Null rather than 1970.
            'expires_at' => $expires > 0 ? Carbon::createFromTimestamp($expires) : null,
            'scopes' => $scopes,
        ];
    }

    /**
     * The scopes this integration needs that a token does not carry.
     *
     * @param  array<int, string>  $granted
     * @return array<int, string>
     */
    public function missingScopes(array $granted): array
    {
        return array_values(array_diff(self::SCOPES, $granted));
    }

    /**
     * @param  array<string, mixed>  $response
     *
     * @throws MetaApiException
     */
    private function tokenFrom(array $response): string
    {
        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            // Meta answered 200 with something that is not a token. Treated as a
            // refusal rather than stored: an empty token fails every later call
            // with an error that points nowhere near here.
            throw new MetaApiException('Meta did not return an access token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function expiryFrom(array $response): ?Carbon
    {
        $seconds = $response['expires_in'] ?? null;

        return is_numeric($seconds) && (int) $seconds > 0
            ? Carbon::now()->addSeconds((int) $seconds)
            : null;
    }
}
