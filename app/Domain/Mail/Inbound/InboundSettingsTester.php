<?php

namespace App\Domain\Mail\Inbound;

use App\Domain\Settings\Contracts\SettingsGroupTester;
use RuntimeException;
use Throwable;

/**
 * "Can we actually read this mailbox?"
 *
 * There is nothing to send, so the panel shows one button. An IMAP password is
 * the kind of thing people get wrong once and spend a morning on, and finding
 * out at the moment you type it is the difference between a minute and that
 * morning.
 */
class InboundSettingsTester implements SettingsGroupTester
{
    public function test(array $values): string
    {
        if (! ImapMailbox::isAvailable()) {
            throw new RuntimeException('This server does not have PHP\'s IMAP extension installed, so inbound mail cannot be read.');
        }

        $settings = MailboxSettings::fromSettings($values);

        if ($settings->host === '' || $settings->username === '') {
            throw new RuntimeException('Inbound mail needs a host and a user name.');
        }

        $mailbox = new ImapMailbox($settings);

        try {
            $mailbox->connect();
        } catch (Throwable $failure) {
            throw new RuntimeException($this->redact($failure->getMessage(), $settings->password));
        } finally {
            $mailbox->close();
        }

        return 'Opened '.$settings->folder.' on '.$settings->host.'.';
    }

    public function sampleLabel(): ?string
    {
        return null;
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
        throw new RuntimeException('There is nothing to send from a mailbox we only read.');
    }

    /**
     * IMAP servers habitually quote the credentials back in an error.
     */
    private function redact(string $message, string $password): string
    {
        return $password === '' ? $message : str_replace($password, '[redacted]', $message);
    }
}
