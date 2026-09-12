<?php

namespace App\Domain\Mail;

use App\Domain\Mail\Contracts\MailProvider;
use App\Domain\Mail\Providers\BrevoProvider;
use App\Domain\Mail\Providers\LogProvider;
use App\Domain\Mail\Providers\MailgunProvider;
use App\Domain\Mail\Providers\PostmarkProvider;
use App\Domain\Mail\Providers\SendGridProvider;
use App\Domain\Mail\Providers\SesProvider;
use App\Domain\Mail\Providers\SmtpProvider;
use App\Domain\Settings\SettingField;

/**
 * Every provider the application can send through, and the settings they add.
 *
 * This is the single source: the `mail` settings group is assembled from it, so
 * a provider cannot exist without its credentials being storable, and a
 * credential cannot be stored for a provider that does not exist.
 *
 * Providers are held as instances rather than class names because the settings
 * registry asks for their fields on every settings read, and constructing seven
 * stateless objects each time is waste for no gain. They carry no state, so one
 * instance for the process is correct.
 */
final class MailProviders
{
    public const NO_FALLBACK = 'none';

    /**
     * @var array<string, MailProvider>|null
     */
    private static ?array $providers = null;

    /**
     * @return array<string, MailProvider>
     */
    public static function all(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $providers = [];

        foreach ([
            new LogProvider,
            new SmtpProvider,
            new MailgunProvider,
            new BrevoProvider,
            new SendGridProvider,
            new SesProvider,
            new PostmarkProvider,
        ] as $provider) {
            $providers[$provider->key()] = $provider;
        }

        return self::$providers = $providers;
    }

    public static function find(?string $key): ?MailProvider
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /**
     * The provider every other answer falls back to: it needs nothing, so it
     * can always be built.
     */
    public static function log(): MailProvider
    {
        return self::all()['log'];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (MailProvider $provider): string => $provider->label(), self::all());
    }

    /**
     * @return array<string, string>
     */
    public static function fallbackOptions(): array
    {
        return [self::NO_FALLBACK => 'No fallback — a failed send is a failed send', ...self::options()];
    }

    /**
     * The whole `mail` settings group: what to send through, who it comes from,
     * and every provider's own credentials.
     *
     * All of them are declared at once, whichever provider is active, so
     * switching provider does not erase the credentials of the one you switched
     * away from. That is what makes switching back cost nothing — and what lets
     * a second provider stand behind the first as the fallback.
     *
     * @return array<int, SettingField>
     */
    public static function settingFields(): array
    {
        $fields = [
            // Live, both of them: the credential fields on the form are the
            // chosen providers' own, so changing either has to redraw the form.
            SettingField::select('provider', 'Send email through', self::options(), 'log', live: true),
            SettingField::select('fallback', 'If that fails, try', self::fallbackOptions(), self::NO_FALLBACK,
                'Only used when the first provider refuses the message outright.', live: true),
            SettingField::text('from_address', 'From address',
                'The address messages appear to come from. Leave blank to use the one in the environment file.'),
            SettingField::text('from_name', 'From name'),
        ];

        foreach (self::all() as $provider) {
            foreach ($provider->fields() as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
