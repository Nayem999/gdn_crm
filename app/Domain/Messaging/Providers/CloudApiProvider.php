<?php

namespace App\Domain\Messaging\Providers;

use RuntimeException;

/**
 * WhatsApp through Meta's own Cloud API.
 *
 * **The 24-hour window is not something this can work around.** Meta only
 * allows a free-form message within 24 hours of the customer's last message;
 * outside it, only a pre-approved template may be sent. A plain send here is
 * therefore refused by Meta rather than silently dropped, and the refusal is
 * passed through in their words — which is the only useful thing to do with it,
 * since the fix is to get a template approved, not to retry.
 */
class CloudApiProvider extends Provider
{
    public function key(): string
    {
        return 'cloud_api';
    }

    public function label(): string
    {
        return 'WhatsApp Cloud API';
    }

    public function description(): string
    {
        return 'Meta\'s own API. Free-form messages are only allowed within 24 hours of the customer writing to you; outside that window Meta requires an approved template.';
    }

    public function fields(): array
    {
        return [
            $this->text('phone_number_id', 'WhatsApp phone number ID', 'From the WhatsApp section of your Meta app.'),
            $this->secret('token', 'Meta access token'),
            $this->text('version', 'Graph API version', 'Defaults to v21.0.'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, [
            'phone_number_id' => 'a phone number ID',
            'token' => 'an access token',
        ]);
    }

    public function verify(array $credentials): void
    {
        $this->verifyEndpoint(
            $this->request()->withToken((string) $this->credential($credentials, 'token', '')),
            $this->endpoint($credentials),
        );
    }

    public function send(array $credentials, string $to, string $body): string
    {
        $response = $this->request()
            ->withToken((string) $this->credential($credentials, 'token', ''))
            ->post($this->endpoint($credentials).'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

        if ($response->failed()) {
            throw $this->failed($response);
        }

        $id = $response->json('messages.0.id');

        if (! is_string($id)) {
            throw new RuntimeException('WhatsApp accepted the message but did not name it.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function endpoint(array $credentials): string
    {
        $version = (string) $this->credential($credentials, 'version', 'v21.0');

        return 'https://graph.facebook.com/'.$version.'/'.(string) $this->credential($credentials, 'phone_number_id', '');
    }
}
