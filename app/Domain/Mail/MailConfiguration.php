<?php

namespace App\Domain\Mail;

use App\Domain\Mail\Contracts\MailProvider;
use App\Domain\Settings\SettingsManager;
use Illuminate\Support\Arr;

/**
 * What the stored settings say about sending email.
 *
 * Every read goes through the settings cache, so asking on each send is cheap;
 * that is deliberate rather than incidental. A queue worker is a process that
 * lives for hours, and anything resolved once at boot — the mailer Laravel
 * caches by name, a transport built in a service provider — keeps sending
 * through the provider that was configured when the worker started. Reading
 * here, per send, is what makes "change the provider in Settings" take effect
 * everywhere rather than only in the web process that made the change.
 */
class MailConfiguration
{
    public function __construct(private readonly SettingsManager $settings) {}

    /**
     * The provider to send through. An unrecognised stored value reads as the
     * log provider rather than throwing: a mailer that refuses to be built
     * takes the application down with it.
     */
    public function activeProvider(): MailProvider
    {
        return MailProviders::find($this->stored('provider')) ?? MailProviders::log();
    }

    /**
     * The provider to try when the first one throws, if there is one.
     *
     * A fallback that is the active provider is no fallback — it would retry
     * the thing that just failed — so it reads as none.
     */
    public function fallbackProvider(): ?MailProvider
    {
        $provider = MailProviders::find($this->stored('fallback'));

        if ($provider === null || $provider->key() === $this->activeProvider()->key()) {
            return null;
        }

        return $provider;
    }

    /**
     * A provider's stored credentials, keyed by the full setting key.
     *
     * Secrets are read by name, one at a time: `forGroup()` deliberately leaves
     * them out, because it feeds screens, and this is the one caller that has
     * business seeing them.
     *
     * @return array<string, mixed>
     */
    public function credentialsFor(MailProvider $provider): array
    {
        $credentials = [];

        foreach ($provider->fields() as $field) {
            $credentials[$field->key] = $this->settings->get('mail.'.$field->key);
        }

        return $credentials;
    }

    /**
     * The same thing by provider key, for a caller that has a key rather than a
     * provider — a webhook naming the provider that sent the message, which is
     * not necessarily the one configured now.
     *
     * @return array<string, mixed>
     */
    public function credentialsForKey(string $provider): array
    {
        $found = MailProviders::find($provider);

        return $found === null ? [] : $this->credentialsFor($found);
    }

    public function isConfigured(): bool
    {
        $provider = $this->activeProvider();

        return $provider->missingRequirements($this->credentialsFor($provider)) === [];
    }

    /**
     * Why email cannot go out, in words meant for the person who has to fix it.
     */
    public function unavailableReason(): ?string
    {
        $provider = $this->activeProvider();
        $missing = $provider->missingRequirements($this->credentialsFor($provider));

        if ($missing === []) {
            return null;
        }

        return $provider->label().' needs '.Arr::join($missing, ', ', ' and ').'.';
    }

    public function fromAddress(): ?string
    {
        return $this->stored('from_address');
    }

    public function fromName(): ?string
    {
        return $this->stored('from_name');
    }

    private function stored(string $key): ?string
    {
        $value = $this->settings->get('mail.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
