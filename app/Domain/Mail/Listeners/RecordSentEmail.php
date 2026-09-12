<?php

namespace App\Domain\Mail\Listeners;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\MailConfiguration;
use App\Domain\Mail\Models\EmailMessage;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Symfony\Component\Mime\Address;

/**
 * Write a row for every message that left the application.
 *
 * It listens to MessageSent rather than sitting in the transport, because the
 * transport is also how the Test Connection button and a raw send reach a
 * provider, and neither of those is a message anybody wants in the delivery
 * log.
 *
 * One row per recipient. A message to three people that bounces for one of them
 * is three facts, and a single row could only record the last one.
 */
class RecordSentEmail
{
    public function __construct(private readonly MailConfiguration $configuration) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $messageId = $event->sent->getMessageId();

        $provider = $this->configuration->activeProvider()->key();
        $sentAt = Carbon::now();
        $subject = $message->getSubject();

        /** @var array<string, mixed> $data */
        $data = $event->data;

        foreach ($message->getTo() as $recipient) {
            $this->record($provider, $messageId, $recipient, $subject, $sentAt, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(string $provider, ?string $messageId, Address $recipient, ?string $subject, Carbon $sentAt, array $data): void
    {
        // Sent through a provider that reports nothing back, or with no id to
        // report against: the row is still worth having — it is the record that
        // we tried — but nothing will ever update it.
        EmailMessage::query()->updateOrCreate(
            ['provider' => $provider, 'message_id' => $messageId, 'to_email' => $recipient->getAddress()],
            [
                'to_name' => $recipient->getName() === '' ? null : $recipient->getName(),
                'subject' => $subject,
                'status' => EmailStatus::Sent,
                'sent_at' => $sentAt,
                'notification_log_id' => is_int($data['notification_log_id'] ?? null) ? $data['notification_log_id'] : null,
                'related_type' => is_string($data['related_type'] ?? null) ? $data['related_type'] : null,
                'related_id' => is_int($data['related_id'] ?? null) ? $data['related_id'] : null,
            ]
        );
    }
}
