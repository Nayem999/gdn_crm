<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Settings\SettingsManager;
use Illuminate\Support\Arr;

/**
 * What the stored settings say about one short-message channel.
 *
 * Credentials come from the settings table, never from the environment file.
 * That is the point of the exercise: an administrator changes the SMS account
 * in a form, and the next message goes through the new one without a deploy.
 */
class MessagingConfiguration
{
    public function __construct(private readonly SettingsManager $settings) {}

    public function activeProvider(string $channel): MessagingProvider
    {
        $stored = $this->settings->get($channel.'.provider');

        return MessagingProviders::find($channel, is_string($stored) ? $stored : null)
            ?? MessagingProviders::log($channel);
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialsFor(string $channel, MessagingProvider $provider): array
    {
        $credentials = [];

        foreach ($provider->fields() as $field) {
            $credentials[$field->key] = $this->settings->get($channel.'.'.$field->key);
        }

        return $credentials;
    }

    public function isConfigured(string $channel): bool
    {
        $provider = $this->activeProvider($channel);

        return $provider->missingRequirements($this->credentialsFor($channel, $provider)) === [];
    }

    public function unavailableReason(string $channel): ?string
    {
        $provider = $this->activeProvider($channel);
        $missing = $provider->missingRequirements($this->credentialsFor($channel, $provider));

        if ($missing === []) {
            return null;
        }

        return $provider->label().' needs '.Arr::join($missing, ', ', ' and ').'.';
    }

    public function send(string $channel, string $to, string $body): string
    {
        $provider = $this->activeProvider($channel);

        return $provider->send($this->credentialsFor($channel, $provider), $to, $body);
    }
}
