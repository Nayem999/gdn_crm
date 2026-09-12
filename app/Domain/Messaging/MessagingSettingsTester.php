<?php

namespace App\Domain\Messaging;

use App\Domain\Settings\Contracts\SettingsGroupTester;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * "Does this actually send?" for a short-message channel.
 *
 * The same arrangement as the email tester, and for the same reason: an
 * administrator should be able to find out a token is wrong before committing
 * it, so both buttons run against the merged values rather than the saved ones.
 *
 * Errors come back in the provider's own words with every stored secret
 * stripped out of them first.
 */
abstract class MessagingSettingsTester implements SettingsGroupTester
{
    abstract protected function channel(): string;

    public function test(array $values): string
    {
        $provider = $this->provider($values);
        $this->refuseIfIncomplete($provider->missingRequirements($values), $provider->label());

        try {
            $provider->verify($values);
        } catch (Throwable $failure) {
            throw new RuntimeException($this->redact($failure->getMessage(), $values));
        }

        return $provider->key() === 'log'
            ? 'Nothing to connect to — messages are written to the application log.'
            : $provider->label().' accepted the credentials.';
    }

    public function sampleLabel(): ?string
    {
        return 'Send test message';
    }

    public function destinationLabel(): string
    {
        return 'Send to';
    }

    public function destinationRules(): array
    {
        // Deliberately loose. Numbers arrive in more shapes than a regular
        // expression should have an opinion about, and the provider is the one
        // that knows which of them it will accept.
        return ['required', 'string', 'min:5', 'max:32'];
    }

    public function sendSample(array $values, string $destination): string
    {
        $provider = $this->provider($values);
        $this->refuseIfIncomplete($provider->missingRequirements($values), $provider->label());

        try {
            $provider->send($values, $destination, config('app.name').': this is a test message.');
        } catch (Throwable $failure) {
            throw new RuntimeException($this->redact($failure->getMessage(), $values));
        }

        return 'A test message has gone to '.$destination.' through '.$provider->label().'.';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function provider(array $values): Contracts\MessagingProvider
    {
        $key = $values['provider'] ?? null;

        return MessagingProviders::find($this->channel(), is_string($key) ? $key : null)
            ?? MessagingProviders::log($this->channel());
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function refuseIfIncomplete(array $missing, string $label): void
    {
        if ($missing !== []) {
            throw new RuntimeException($label.' needs '.Arr::join($missing, ', ', ' and ').'.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function redact(string $message, array $values): string
    {
        foreach ($values as $key => $value) {
            if (! is_string($value) || $value === '' || ! SettingsRegistry::isSecret($this->channel().'.'.$key)) {
                continue;
            }

            $message = str_replace($value, '[redacted]', $message);
        }

        return $message;
    }
}
