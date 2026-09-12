<?php

namespace App\Domain\Mail;

use App\Domain\Settings\Contracts\SettingsGroupTester;
use App\Domain\Settings\SettingsRegistry;
use App\Mail\TestEmail;
use App\Models\User;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * "Does this actually send?" for the email provider settings.
 *
 * Both answers are given against the **merged** credentials — stored, overridden
 * by anything typed and not yet saved — so an administrator can find out a key
 * is wrong before committing it.
 *
 * Every message that comes back out of here is redacted first. A provider's
 * error is worth showing verbatim and occasionally contains the credential it
 * just rejected; an error message is not a place to publish one.
 */
class MailSettingsTester implements SettingsGroupTester
{
    public function test(array $values): string
    {
        $provider = MailProviders::find(is_string($values['provider'] ?? null) ? $values['provider'] : null)
            ?? MailProviders::log();

        $missing = $provider->missingRequirements($values);

        if ($missing !== []) {
            throw new RuntimeException($provider->label().' needs '.Arr::join($missing, ', ', ' and ').'.');
        }

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
        return 'Send test email';
    }

    public function destinationLabel(): string
    {
        return 'Send to';
    }

    public function destinationRules(): array
    {
        return ['required', 'email:rfc'];
    }

    public function sendSample(array $values, string $destination): string
    {
        $provider = MailProviders::find(is_string($values['provider'] ?? null) ? $values['provider'] : null)
            ?? MailProviders::log();

        $missing = $provider->missingRequirements($values);

        if ($missing !== []) {
            throw new RuntimeException($provider->label().' needs '.Arr::join($missing, ', ', ' and ').'.');
        }

        // Its own mailer, built on this provider's transport, rather than the
        // application's: the point is to exercise the credentials in front of
        // us, which are not necessarily the ones that are saved.
        $mailer = new Mailer('mail-test', app('view'), $provider->transport($values), app('events'));

        $from = is_string($values['from_address'] ?? null) && $values['from_address'] !== ''
            ? $values['from_address']
            : (string) config('mail.from.address');

        $name = is_string($values['from_name'] ?? null) && $values['from_name'] !== ''
            ? $values['from_name']
            : (string) config('mail.from.name');

        $mailer->alwaysFrom($from, $name);

        try {
            $actor = auth()->user();

            $mailer->to($destination)->send(new TestEmail(
                $provider->label(),
                $actor instanceof User ? $actor->name : 'the system',
            ));
        } catch (Throwable $failure) {
            throw new RuntimeException($this->redact($failure->getMessage(), $values));
        }

        return 'A test message has gone to '.$destination.' through '.$provider->label().'.';
    }

    /**
     * Take any stored credential back out of a message before it is shown.
     *
     * @param  array<string, mixed>  $values
     */
    private function redact(string $message, array $values): string
    {
        foreach ($values as $key => $value) {
            if (! is_string($value) || $value === '' || ! SettingsRegistry::isSecret('mail.'.$key)) {
                continue;
            }

            $message = str_replace($value, '[redacted]', $message);
        }

        return $message;
    }
}
