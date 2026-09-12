<?php

namespace App\Domain\Messaging\Providers;

use RuntimeException;

/**
 * Vonage (formerly Nexmo), for SMS.
 *
 * **Vonage answers 200 to a rejected message.** The HTTP status says the
 * request was understood; whether the message was accepted is
 * `messages[0].status`, where "0" means success and anything else is a failure
 * with a reason beside it. A driver that trusts the status code reports every
 * message as sent, including the ones that were not.
 */
class VonageProvider extends Provider
{
    public function key(): string
    {
        return 'vonage';
    }

    public function label(): string
    {
        return 'Vonage';
    }

    public function description(): string
    {
        return 'Needs the API key and secret from the Vonage dashboard. The from value may be a number or an approved sender name.';
    }

    public function fields(): array
    {
        return [
            $this->text('key', 'Vonage API key'),
            $this->secret('secret', 'Vonage API secret'),
            $this->text('from', 'Vonage from number or sender name'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, [
            'key' => 'an API key',
            'secret' => 'an API secret',
            'from' => 'a from number',
        ]);
    }

    public function verify(array $credentials): void
    {
        // Basic auth rather than the query-string form their documentation
        // shows first: an API secret does not belong in a URL, where it ends up
        // in access logs on both sides.
        $this->verifyEndpoint(
            $this->request()->withBasicAuth(
                (string) $this->credential($credentials, 'key', ''),
                (string) $this->credential($credentials, 'secret', ''),
            ),
            'https://rest.nexmo.com/account/get-balance',
        );
    }

    public function send(array $credentials, string $to, string $body): string
    {
        $response = $this->request()->asForm()->post('https://rest.nexmo.com/sms/json', [
            'api_key' => (string) $this->credential($credentials, 'key', ''),
            'api_secret' => (string) $this->credential($credentials, 'secret', ''),
            'to' => ltrim($to, '+'),
            'from' => (string) $this->credential($credentials, 'from', ''),
            'text' => $body,
        ]);

        if ($response->failed()) {
            throw $this->failed($response);
        }

        $message = $response->json('messages.0');

        if (! is_array($message)) {
            throw new RuntimeException('Vonage returned no result for the message.');
        }

        if ((string) ($message['status'] ?? '') !== '0') {
            throw new RuntimeException('Vonage refused the message: '.(string) ($message['error-text'] ?? 'no reason given').'.');
        }

        return (string) ($message['message-id'] ?? '');
    }
}
