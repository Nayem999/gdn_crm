<?php

namespace App\Domain\Messaging\Providers;

use RuntimeException;

/**
 * Twilio, for SMS and for WhatsApp.
 *
 * One class for both, because Twilio's own API makes no distinction beyond a
 * `whatsapp:` prefix on the numbers. Two classes would have been two copies of
 * the same request with one string different.
 */
class TwilioProvider extends Provider
{
    /**
     * @param  bool  $whatsApp  Whether numbers need the WhatsApp prefix.
     */
    public function __construct(private readonly bool $whatsApp = false) {}

    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio';
    }

    public function description(): string
    {
        return $this->whatsApp
            ? 'Uses Twilio\'s WhatsApp sender. The from number must be one Twilio has approved for WhatsApp.'
            : 'Needs the Account SID and Auth Token from the Twilio console.';
    }

    public function fields(): array
    {
        return [
            $this->text('sid', 'Twilio Account SID'),
            $this->secret('token', 'Twilio Auth Token'),
            $this->text('from', 'Twilio from number', 'In international form, for example +8801700000000.'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, [
            'sid' => 'an account SID',
            'token' => 'an auth token',
            'from' => 'a from number',
        ]);
    }

    public function verify(array $credentials): void
    {
        $sid = (string) $this->credential($credentials, 'sid', '');

        $this->verifyEndpoint(
            $this->request()->withBasicAuth($sid, (string) $this->credential($credentials, 'token', '')),
            'https://api.twilio.com/2010-04-01/Accounts/'.$sid.'.json',
        );
    }

    public function send(array $credentials, string $to, string $body): string
    {
        $sid = (string) $this->credential($credentials, 'sid', '');

        $response = $this->request()
            ->asForm()
            ->withBasicAuth($sid, (string) $this->credential($credentials, 'token', ''))
            ->post('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json', [
                'To' => $this->address($to),
                'From' => $this->address((string) $this->credential($credentials, 'from', '')),
                'Body' => $body,
            ]);

        if ($response->failed()) {
            throw $this->failed($response);
        }

        $id = $response->json('sid');

        if (! is_string($id)) {
            throw new RuntimeException('Twilio accepted the message but did not name it.');
        }

        return $id;
    }

    private function address(string $number): string
    {
        if (! $this->whatsApp) {
            return $number;
        }

        return str_starts_with($number, 'whatsapp:') ? $number : 'whatsapp:'.$number;
    }
}
