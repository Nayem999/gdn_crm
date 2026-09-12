<?php

namespace App\Domain\Mail\Webhooks;

/**
 * Which providers report back, and where they should report to.
 *
 * **The URL carries its own secret**, derived from the application key rather
 * than stored. Half of these providers do not sign their webhooks at all and
 * their own advice is to keep the URL private, so an unguessable path is the
 * floor rather than an extra. Deriving it means there is no secret to generate,
 * store, display once, rotate or leak — and it moves when the application key
 * moves, which is the only rotation that would matter.
 *
 * Providers that *do* sign are verified as well, on top of the token, whenever
 * a signing key is configured.
 *
 * SMTP has no entry: a relay tells you nothing after the handshake, and
 * pretending otherwise would mean a delivery log that quietly says "sent"
 * forever.
 */
final class MailWebhooks
{
    /**
     * @var array<string, class-string<MailWebhook>>
     */
    private const HANDLERS = [
        'mailgun' => MailgunWebhook::class,
        'postmark' => PostmarkWebhook::class,
        'sendgrid' => SendGridWebhook::class,
        'brevo' => BrevoWebhook::class,
        'ses' => SesWebhook::class,
    ];

    public static function for(string $provider): ?MailWebhook
    {
        $handler = self::HANDLERS[$provider] ?? null;

        return $handler === null ? null : app($handler);
    }

    /**
     * @return array<int, string>
     */
    public static function providers(): array
    {
        return array_keys(self::HANDLERS);
    }

    public static function supports(string $provider): bool
    {
        return array_key_exists($provider, self::HANDLERS);
    }

    public static function token(): string
    {
        return substr(hash_hmac('sha256', 'mail-webhook', (string) config('app.key')), 0, 32);
    }

    public static function urlFor(string $provider): string
    {
        return url('/webhooks/mail/'.$provider.'/'.self::token());
    }

    public static function tokenMatches(string $candidate): bool
    {
        return hash_equals(self::token(), $candidate);
    }
}
