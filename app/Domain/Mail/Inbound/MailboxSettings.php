<?php

namespace App\Domain\Mail\Inbound;

/**
 * The mailbox to read, as the administrator configured it.
 *
 * Certificate validation can be turned off because plenty of small businesses
 * run a mail server with a self-signed certificate, and the alternative is that
 * they cannot use the feature at all. It is a choice they have to make, not a
 * default.
 */
final readonly class MailboxSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        public string $password,
        public string $folder = 'INBOX',
        public bool $validateCertificate = true,
    ) {}

    /**
     * @param  array<string, mixed>  $values  The inbound settings group.
     */
    public static function fromSettings(array $values): self
    {
        return new self(
            host: (string) ($values['host'] ?? ''),
            port: (int) ($values['port'] ?? 993),
            encryption: (string) ($values['encryption'] ?? 'ssl'),
            username: (string) ($values['username'] ?? ''),
            password: (string) ($values['password'] ?? ''),
            folder: (string) ($values['folder'] ?? 'INBOX') ?: 'INBOX',
            validateCertificate: (bool) ($values['validate_certificate'] ?? true),
        );
    }

    /**
     * The connection string ext-imap wants.
     *
     * It is a string rather than a set of options, which is why this is built
     * in one place and not assembled at the call site.
     */
    public function connectionString(): string
    {
        $flags = '/imap';

        $flags .= match ($this->encryption) {
            'ssl' => '/ssl',
            'tls' => '/tls',
            default => '/notls',
        };

        if (! $this->validateCertificate) {
            $flags .= '/novalidate-cert';
        }

        return '{'.$this->host.':'.$this->port.$flags.'}'.$this->folder;
    }
}
