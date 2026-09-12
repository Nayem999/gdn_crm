<?php

namespace App\Domain\Mail\Providers;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Amazon SES, over its SMTP endpoint.
 *
 * **SMTP rather than the SES API, deliberately.** The API is signed with
 * SigV4 and in practice means adding the AWS SDK; the SMTP endpoint is the
 * documented alternative and needs nothing beyond what is already installed.
 * The cost is that it wants *SES SMTP credentials* — the user name and password
 * the SES console generates — and not an IAM access key, which is why the
 * fields say so.
 *
 * The host is derived from the region rather than typed, because every SES
 * region follows the same pattern and a typo in a hostname fails at connection
 * time with a message about DNS.
 */
class SesProvider extends Provider
{
    public function key(): string
    {
        return 'ses';
    }

    public function label(): string
    {
        return 'Amazon SES';
    }

    public function description(): string
    {
        return 'Uses the SES SMTP endpoint. Create SMTP credentials in the SES console — an IAM access key will not work here.';
    }

    public function fields(): array
    {
        return [
            $this->text('region', 'SES region', 'For example eu-west-1.'),
            $this->text('username', 'SES SMTP user name'),
            $this->secret('password', 'SES SMTP password'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, [
            'region' => 'a region',
            'username' => 'an SMTP user name',
            'password' => 'an SMTP password',
        ]);
    }

    public function transport(array $credentials): TransportInterface
    {
        $region = (string) $this->credential($credentials, 'region', 'us-east-1');

        $transport = new EsmtpTransport('email-smtp.'.$region.'.amazonaws.com', 587);

        $transport->setUsername((string) $this->credential($credentials, 'username', ''));
        $transport->setPassword((string) $this->credential($credentials, 'password', ''));

        return $transport;
    }

    public function verify(array $credentials): void
    {
        $transport = $this->transport($credentials);

        if ($transport instanceof EsmtpTransport) {
            $this->verifySmtp($transport);
        }
    }
}
