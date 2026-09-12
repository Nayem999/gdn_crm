<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Providers\CloudApiProvider;
use App\Domain\Messaging\Providers\LogMessagingProvider;
use App\Domain\Messaging\Providers\TwilioProvider;
use App\Domain\Messaging\Providers\VonageProvider;
use App\Domain\Settings\SettingField;

/**
 * Which providers each short-message channel can use.
 *
 * Two lists rather than one, because the answer genuinely differs: Vonage sends
 * SMS and not WhatsApp, Meta's Cloud API sends WhatsApp and not SMS, and Twilio
 * sends both. Offering a provider that cannot serve the channel would be a
 * setting that saves and then fails at the first message.
 *
 * Credentials are stored per group, so the Twilio account used for SMS and the
 * one used for WhatsApp are configured separately. They are usually the same
 * account — but "usually" is not a reason to make them impossible to separate.
 */
final class MessagingProviders
{
    public const SMS = 'sms';

    public const WHATSAPP = 'whatsapp';

    /**
     * @var array<string, array<string, MessagingProvider>>|null
     */
    private static ?array $providers = null;

    /**
     * @return array<string, MessagingProvider>
     */
    public static function forChannel(string $channel): array
    {
        if (self::$providers === null) {
            self::$providers = [
                self::SMS => self::keyed([new LogMessagingProvider, new TwilioProvider, new VonageProvider]),
                self::WHATSAPP => self::keyed([new LogMessagingProvider, new TwilioProvider(whatsApp: true), new CloudApiProvider]),
            ];
        }

        return self::$providers[$channel] ?? [];
    }

    public static function find(string $channel, ?string $key): ?MessagingProvider
    {
        return $key === null ? null : (self::forChannel($channel)[$key] ?? null);
    }

    public static function log(string $channel): MessagingProvider
    {
        return self::forChannel($channel)['log'];
    }

    /**
     * @return array<string, string>
     */
    public static function options(string $channel): array
    {
        return array_map(fn (MessagingProvider $provider): string => $provider->label(), self::forChannel($channel));
    }

    /**
     * @return array<int, SettingField>
     */
    public static function settingFields(string $channel): array
    {
        $fields = [
            SettingField::select('provider', 'Send through', self::options($channel), 'log', live: true),
        ];

        foreach (self::forChannel($channel) as $provider) {
            foreach ($provider->fields() as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param  array<int, MessagingProvider>  $providers
     * @return array<string, MessagingProvider>
     */
    private static function keyed(array $providers): array
    {
        $keyed = [];

        foreach ($providers as $provider) {
            $keyed[$provider->key()] = $provider;
        }

        return $keyed;
    }
}
