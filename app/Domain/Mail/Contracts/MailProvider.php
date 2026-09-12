<?php

namespace App\Domain\Mail\Contracts;

use App\Domain\Settings\SettingField;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * One way of handing an email to the outside world.
 *
 * A provider is three things: the settings it needs, whether those settings are
 * filled in, and the transport they build. It never decides *whether* to send —
 * that is the notification engine's business — and it never reads settings
 * itself, so the same provider can be built from stored credentials, from a
 * form the administrator has not saved yet (the Test Connection button in 7.2),
 * or from a fixture in a test.
 *
 * Every field key is prefixed with the provider's own key, because all of them
 * share the one `mail` settings group: `smtp_host`, `mailgun_secret`. Switching
 * provider therefore does not destroy the credentials of the one you switched
 * away from, which is what makes switching back — and the fallback provider —
 * possible at all.
 */
interface MailProvider
{
    /**
     * The stored value of `mail.provider` when this provider is the active one.
     * Permanent: changing it orphans every credential already saved under it.
     */
    public function key(): string;

    public function label(): string;

    /**
     * What an administrator needs to know before choosing it — where to find
     * the credentials, mostly.
     */
    public function description(): string;

    /**
     * The settings this provider adds to the `mail` group, keys already
     * prefixed.
     *
     * @return array<int, SettingField>
     */
    public function fields(): array;

    /**
     * The labels of the settings this provider cannot work without and does not
     * have. Empty means it is ready to send.
     *
     * Returning labels rather than keys is deliberate: this text goes straight
     * into "Email is not configured — Mailgun needs a domain", which is read by
     * somebody who is looking at the form, not at the code.
     *
     * @param  array<string, mixed>  $credentials  Keyed by the full field key.
     * @return array<int, string>
     */
    public function missingRequirements(array $credentials): array;

    /**
     * @param  array<string, mixed>  $credentials  Keyed by the full field key.
     */
    public function transport(array $credentials): TransportInterface;
}
