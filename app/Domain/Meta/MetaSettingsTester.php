<?php

namespace App\Domain\Meta;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Settings\Contracts\SettingsGroupTester;
use RuntimeException;

/**
 * "Do these app credentials actually work?", answered before anybody tries to
 * connect an account with them.
 *
 * It asks Meta about the app itself using the app token, which is the one call
 * that needs no user to have authorised anything. A wrong secret fails here in
 * a second, rather than three screens into the connection wizard where the
 * error reads as an OAuth problem.
 *
 * It is handed what is on the form, including what has been typed and not yet
 * saved — so nobody has to save a secret they are unsure of to find out.
 */
class MetaSettingsTester implements SettingsGroupTester
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly MetaConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function test(array $values): string
    {
        // What is on the form first, then what is stored. Never the environment
        // — these credentials do not live there, and a fallback to it would be
        // a way for a stale deployment variable to pass a test the saved
        // configuration would fail.
        $id = $this->stringValue($values, 'app_id') ?? $this->configuration->appId();
        $secret = $this->stringValue($values, 'app_secret') ?? $this->configuration->appSecret();

        if ($id === null || $secret === null) {
            throw new RuntimeException('Add the app ID and the app secret first.');
        }

        $version = $this->stringValue($values, 'graph_version');

        if ($version !== null && ! MetaApiVersion::isValid($version)) {
            throw new RuntimeException('The Graph version should look like v21.0.');
        }

        try {
            // The app asking about itself. Anything else would need a user
            // token, which is exactly what does not exist yet.
            $app = $this->client->get($id, ['fields' => 'id,name'], $id.'|'.$secret);
        } catch (MetaApiException $exception) {
            // Meta's own words, which for a wrong secret is a clear "Invalid
            // OAuth access token". Never the secret, and never the trace id.
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        $name = is_string($app['name'] ?? null) ? $app['name'] : 'your Meta app';

        return 'Connected to '.$name.' on Graph '.MetaApiVersion::resolve($version).'.';
    }

    /**
     * Nothing to send: an app has no inbox. Connecting a page or a WhatsApp
     * number is what gets tested for real, and that is the wizard's job.
     */
    public function sampleLabel(): ?string
    {
        return null;
    }

    public function destinationLabel(): string
    {
        return 'Destination';
    }

    /**
     * @return array<int, string>
     */
    public function destinationRules(): array
    {
        return ['nullable'];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function sendSample(array $values, string $destination): string
    {
        throw new RuntimeException('There is nothing to send from here.');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
