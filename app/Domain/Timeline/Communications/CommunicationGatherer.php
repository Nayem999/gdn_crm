<?php

namespace App\Domain\Timeline\Communications;

use App\Domain\Chat\Models\ChatConversation;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Models\InboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Everything said to or by one record, across every channel.
 *
 * **Two ways of belonging, and both are needed.** A message can be linked to
 * the record explicitly — a quote email carries the quote's id — or it can
 * simply have gone to an address the record holds. Only the first is reliable;
 * only the second catches the mail somebody sent before the link existed. A
 * timeline that used one of them would be missing half a conversation.
 *
 * Matching by address is the weaker half and is worth being honest about: it is
 * an exact comparison, so a number stored as "01711 000000" on the contact and
 * sent as "+8801711000000" will not match. Normalising phone numbers properly
 * needs a library and a country, and guessing at it would put somebody else's
 * messages on a customer's timeline — which is a worse failure than a missing
 * row.
 */
class CommunicationGatherer
{
    /**
     * @return array<int, Communication>
     */
    public function for(Model $subject, int $take): array
    {
        $emails = $this->addressesOf($subject, ['email', 'bill_to_email']);
        $phones = $this->addressesOf($subject, ['phone', 'mobile']);

        return [
            ...$this->outboundEmail($subject, $emails, $take),
            ...$this->inboundEmail($subject, $emails, $take),
            ...$this->shortMessages($phones, $take),
            ...$this->chats($subject, $emails, $take),
            ...$this->socialConversations($subject, $take),
        ];
    }

    /**
     * Messenger and WhatsApp threads, one entry per conversation.
     *
     * The same judgement the website chat gets and for the same reason: a
     * conversation is the unit somebody reads, and thirty entries for thirty
     * messages would bury every other strand on the page.
     *
     * Matched only on the **explicit link** — the lead or contact the
     * conversation was tied to — with no address fallback. A social thread has
     * no email or telephone number to match on: what it has is a page-scoped id
     * that means nothing outside Meta, so there is nothing here to guess with,
     * and guessing is what puts somebody else's messages on a customer's
     * timeline.
     *
     * @return array<int, Communication>
     */
    private function socialConversations(Model $subject, int $take): array
    {
        $column = match (true) {
            $subject instanceof Lead => 'lead_id',
            $subject instanceof Contact => 'contact_id',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        $conversations = SocialConversation::query()
            ->with('messages')
            ->where($column, $subject->getKey())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get();

        return $conversations->map(function (SocialConversation $conversation): Communication {
            $latest = $conversation->messages->last();

            return new Communication(
                channel: $conversation->channel() === SocialChannel::WhatsApp
                    ? CommunicationChannel::WhatsApp
                    : CommunicationChannel::Messenger,
                // The direction of the last thing said, which is what tells
                // somebody scanning the page whether the ball is in our court.
                outbound: $latest !== null && ! $latest->isInbound(),
                occurredAt: $conversation->last_message_at,
                title: $conversation->channel()->label().' with '.$conversation->displayName(),
                body: $latest?->preview(500),
                status: $conversation->status()->label(),
                counterparty: $conversation->participant_handle ?? $conversation->displayName(),
                sourceKey: 'social-'.$conversation->getKey(),
            );
        })->all();
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<int, Communication>
     */
    private function outboundEmail(Model $subject, array $emails, int $take): array
    {
        $messages = EmailMessage::query()
            ->where(function ($query) use ($subject, $emails) {
                $query->where(fn ($rows) => $rows
                    ->where('related_type', $subject->getMorphClass())
                    ->where('related_id', $subject->getKey()));

                if ($emails !== []) {
                    $query->orWhereIn('to_email', $emails);
                }
            })
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get();

        return $messages->map(fn (EmailMessage $message): Communication => new Communication(
            channel: CommunicationChannel::Email,
            outbound: true,
            occurredAt: $message->sent_at,
            title: $message->subject ?? '(no subject)',
            body: null,
            status: $message->status->label(),
            counterparty: $message->to_email,
            sourceKey: 'email-out-'.$message->id,
        ))->all();
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<int, Communication>
     */
    private function inboundEmail(Model $subject, array $emails, int $take): array
    {
        $messages = InboundMessage::query()
            ->where(function ($query) use ($subject, $emails) {
                $query->where(fn ($rows) => $rows
                    ->where('related_type', $subject->getMorphClass())
                    ->where('related_id', $subject->getKey()));

                if ($emails !== []) {
                    $query->orWhereIn('from_email', $emails);
                }
            })
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get();

        return $messages->map(fn (InboundMessage $message): Communication => new Communication(
            channel: CommunicationChannel::Email,
            outbound: false,
            occurredAt: $message->received_at,
            title: $message->subject ?? '(no subject)',
            // The reply itself, trimmed: the timeline is a summary, and the
            // whole of a quoted thread is not one.
            body: $message->body === null ? null : Str::limit(trim($message->body), 500),
            status: null,
            counterparty: $message->from_email,
            sourceKey: 'email-in-'.$message->id,
        ))->all();
    }

    /**
     * SMS and WhatsApp, from the notification log.
     *
     * That log is where a sent text message is recorded, so this reads it
     * rather than keeping a second copy of the same fact somewhere else.
     *
     * @param  array<int, string>  $phones
     * @return array<int, Communication>
     */
    private function shortMessages(array $phones, int $take): array
    {
        if ($phones === []) {
            return [];
        }

        $logs = NotificationLog::query()
            ->whereIn('channel', [NotificationChannel::Sms->value, NotificationChannel::WhatsApp->value])
            ->whereIn('recipient', $phones)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get();

        return $logs->map(fn (NotificationLog $log): Communication => new Communication(
            channel: $log->channel === NotificationChannel::WhatsApp->value
                ? CommunicationChannel::WhatsApp
                : CommunicationChannel::Sms,
            outbound: true,
            occurredAt: $log->sent_at ?? $log->created_at,
            title: $log->subject ?? $log->event,
            body: null,
            status: ucfirst($log->status),
            counterparty: $log->recipient,
            sourceKey: 'message-'.$log->id,
        ))->all();
    }

    /**
     * Website chat, as one entry per conversation rather than per line.
     *
     * A conversation is the unit somebody reads. Thirty separate entries for
     * thirty messages would bury every other strand on the page.
     *
     * @param  array<int, string>  $emails
     * @return array<int, Communication>
     */
    private function chats(Model $subject, array $emails, int $take): array
    {
        $conversations = ChatConversation::query()
            ->with('messages')
            ->where(function ($query) use ($subject, $emails) {
                if ($subject instanceof Lead) {
                    $query->orWhere('lead_id', $subject->getKey());
                }

                if ($emails !== []) {
                    $query->orWhereIn('visitor_email', $emails);
                }
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($take)
            ->get();

        return $conversations->map(fn (ChatConversation $conversation): Communication => new Communication(
            channel: CommunicationChannel::Chat,
            outbound: false,
            occurredAt: $conversation->last_message_at,
            title: 'Website chat'.($conversation->page_url === null ? '' : ' from '.$conversation->page_url),
            body: Str::limit($conversation->transcript(), 500),
            status: $conversation->lead_id === null ? 'Not identified' : null,
            counterparty: $conversation->visitor_email ?? $conversation->visitor_phone,
            sourceKey: 'chat-'.$conversation->id,
        ))->all();
    }

    /**
     * The record's own addresses, read off its attributes rather than assumed.
     *
     * The builder is handed a Model and every module has a different set of
     * columns; asking for a property a record does not have would be a fatal
     * rather than an empty list.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function addressesOf(Model $subject, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $value = $subject->getAttributes()[$column] ?? null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
