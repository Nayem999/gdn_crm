<?php

namespace App\Domain\Meta;

use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Settings\SettingsManager;

/**
 * What this installation knows about the Meta app it talks to.
 *
 * **One place answers: the settings table, encrypted at rest.** Not the
 * environment file, and not a config default — deliberately, and not only for
 * tidiness. A credential in `.env` is a credential in every deployment script,
 * every server backup and every `config:cache` artefact, it cannot be rotated
 * without a deploy, and `.env.example` is where one eventually gets committed
 * by somebody filling it in "just to test". Stored here it is encrypted,
 * write-only in the UI, redacted in the audit trail and gated behind
 * `settings.secrets`, exactly like every other integration credential in this
 * application.
 *
 * Everything on this class is a secret or names one. None of it is safe to put
 * in a view, a log line, an API response or an exception message: the app
 * secret signs webhooks and mints the app token, and the verify token is what
 * stops somebody else's server registering itself as our webhook.
 */
class MetaConfiguration
{
    /**
     * The settings group these live in.
     */
    public const GROUP = 'meta';

    public function __construct(private readonly SettingsManager $settings) {}

    public function appId(): ?string
    {
        return $this->stored('app_id');
    }

    public function appSecret(): ?string
    {
        return $this->stored('app_secret');
    }

    /**
     * The token Meta echoes back when subscribing a webhook. Ours to choose;
     * it only has to match what was typed into the Meta app.
     */
    public function verifyToken(): ?string
    {
        return $this->stored('verify_token');
    }

    /**
     * The dataset (pixel) conversions are reported against. Only the
     * Conversions API needs it, so an installation that never sends conversions
     * is fully configured without one.
     */
    public function datasetId(): ?string
    {
        return $this->stored('dataset_id');
    }

    /**
     * The Graph version to call, validated rather than trusted — an
     * administrator can type anything into a text field, and `latest` is a URL
     * Meta answers with an error that says nothing about the real cause.
     */
    public function version(): string
    {
        // The one value with a packaged default: it is not a credential, it is
        // what this application was written against, and an installation that
        // has never opened the settings screen still has to be able to call
        // Meta. An administrator's override wins when it is a real version.
        return MetaApiVersion::resolve($this->stored('graph_version'));
    }

    /**
     * The permissions this installation asks Meta for.
     *
     * Configurable, and that is not a nicety. Meta refuses the whole consent
     * screen with **"Invalid Scopes"** when an app asks for a permission it has
     * not been approved for — `leads_retrieval` being the usual one, since Lead
     * Ads needs App Review before an app may request it at all. An installation
     * whose app is still under review must be able to connect for Messenger,
     * WhatsApp and advertising in the meantime rather than being locked out of
     * everything by the one permission it does not have yet.
     *
     * Only names this application actually uses are honoured: an unknown scope
     * is dropped rather than passed through, because the value goes straight
     * into a URL somebody is sent to, and a typo there is a consent screen that
     * fails with Meta's own unhelpful wording.
     *
     * @return array<int, string>
     */
    public function scopes(): array
    {
        $configured = $this->stored('scopes');

        if ($configured === null) {
            return MetaAuthService::SCOPES;
        }

        $asked = array_filter(array_map('trim', explode(',', $configured)));

        $known = array_values(array_intersect(MetaAuthService::SCOPES, $asked));

        // Everything removed leaves nothing to ask for, which Meta answers with
        // a different error again. The full set is a better answer than none.
        return $known === [] ? MetaAuthService::SCOPES : $known;
    }

    /**
     * Whether the app itself is set up. Not whether anything is *connected* —
     * that is an account, and it is 12.4's question.
     */
    public function isConfigured(): bool
    {
        return $this->appId() !== null && $this->appSecret() !== null;
    }

    /**
     * What is still needed, in words an administrator can act on.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->appId() === null) {
            $missing[] = 'an app ID';
        }

        if ($this->appSecret() === null) {
            $missing[] = 'an app secret';
        }

        return $missing;
    }

    /**
     * Whether webhooks can be verified. Separate from isConfigured() because a
     * connection can read from Meta perfectly well while no webhook has been
     * set up yet — and a missing verify token should say so rather than making
     * the whole integration look broken.
     */
    public function canVerifyWebhooks(): bool
    {
        return $this->verifyToken() !== null;
    }

    /**
     * What the settings table holds for this key, or nothing.
     *
     * A blank string is nothing: a row that was cleared arrives as `''`, and
     * treating that as a configured empty secret would key `appsecret_proof`
     * with nothing at all and produce a signature Meta rejects for a reason
     * that points nowhere near the cause.
     */
    private function stored(string $key): ?string
    {
        $value = $this->settings->get(self::GROUP.'.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
