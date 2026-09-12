<?php

namespace App\Domain\Mail\Transports;

use App\Domain\Mail\Contracts\MailProvider;
use App\Domain\Mail\MailConfiguration;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * The transport the application actually sends through.
 *
 * It owns no protocol of its own. It reads the configured provider **on every
 * send**, builds that provider's transport, and hands the message over — which
 * is what makes changing the provider in Settings take effect immediately, in
 * every process, with no config cache to clear and no mailer to forget. The
 * alternative, resolving the provider when the mailer is created, works
 * perfectly in the request that changes the setting and not at all in the queue
 * worker that sends the mail.
 *
 * It also owns the two things that only make sense once there is more than one
 * provider: the fallback, and the company's from address.
 */
class ManagedTransport implements TransportInterface
{
    /**
     * Built transports, keyed by provider and credentials, so an SMTP
     * connection survives a batch of messages but a changed password does not
     * survive at all.
     *
     * @var array<string, TransportInterface>
     */
    private array $transports = [];

    public function __construct(private readonly MailConfiguration $configuration) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->stampSender($message);

        $primary = $this->configuration->activeProvider();

        try {
            return $this->transportFor($primary)->send($message, $envelope);
        } catch (Throwable $failure) {
            $fallback = $this->configuration->fallbackProvider();

            if ($fallback === null) {
                throw $failure;
            }

            // Worth being honest about: "the primary threw" is not the same as
            // "the message was not sent". A provider that accepts a message and
            // then times out on the response throws here, and the fallback
            // sends it again. A duplicate is the better failure of the two, but
            // it is a real one.
            Log::warning('Mail provider ['.$primary->key().'] failed; trying ['.$fallback->key().'].', [
                'error' => $failure->getMessage(),
            ]);

            return $this->transportFor($fallback)->send($message, $envelope);
        }
    }

    /**
     * Put the company's from address on anything that did not choose one.
     *
     * Laravel fills the from address from config before the message reaches a
     * transport, so "nobody chose" shows up here as "the from address is still
     * the environment file's". Anything else was set deliberately — a quote
     * going out under a salesperson's name, say — and is left alone.
     */
    private function stampSender(RawMessage $message): void
    {
        $address = $this->configuration->fromAddress();

        if ($address === null || ! $message instanceof Email) {
            return;
        }

        $from = $message->getFrom();

        if ($from !== [] && $from[0]->getAddress() !== (string) config('mail.from.address')) {
            return;
        }

        $message->from(new Address($address, $this->configuration->fromName() ?? ''));
    }

    private function transportFor(MailProvider $provider): TransportInterface
    {
        $credentials = $this->configuration->credentialsFor($provider);

        $signature = $provider->key().':'.md5(serialize($credentials));

        return $this->transports[$signature] ??= $provider->transport($credentials);
    }

    public function __toString(): string
    {
        return 'crm://'.$this->configuration->activeProvider()->key();
    }
}
