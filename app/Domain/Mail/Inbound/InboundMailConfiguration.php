<?php

namespace App\Domain\Mail\Inbound;

use App\Domain\Settings\SettingsManager;

/**
 * The inbound mailbox settings, read the same way the outbound ones are: on
 * demand, so a change takes effect in the scheduler's next run rather than at
 * the next deploy.
 */
class InboundMailConfiguration
{
    public function __construct(private readonly SettingsManager $settings) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('inbound.enabled', false)
            && $this->mailboxSettings()->host !== '';
    }

    public function folder(): string
    {
        $folder = $this->settings->get('inbound.folder');

        return is_string($folder) && $folder !== '' ? $folder : 'INBOX';
    }

    public function mailboxSettings(): MailboxSettings
    {
        return MailboxSettings::fromSettings([
            ...$this->settings->forGroup('inbound'),
            // forGroup leaves secrets out on purpose; this is one of the few
            // callers with business seeing one.
            'password' => $this->settings->get('inbound.password'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values  Merged form values, for a test
     *                                        against credentials not yet saved.
     */
    public function mailboxFor(array $values): MailboxSettings
    {
        return MailboxSettings::fromSettings($values);
    }
}
