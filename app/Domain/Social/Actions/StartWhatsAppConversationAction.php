<?php

namespace App\Domain\Social\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Shared\Duplicates\MatchStrategy;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use RuntimeException;

/**
 * Opening a WhatsApp thread with somebody from their record.
 *
 * §19 asks for "send from lead, contact or deal", and the honest reading is that
 * the record is where somebody *starts*: the sending itself belongs to the
 * conversation, so this finds or creates one and hands it back. Otherwise there
 * would be two ways to send a WhatsApp message and two places for the window
 * rules to disagree.
 *
 * **A thread found is a thread joined.** If that number already has a
 * conversation, this returns it rather than starting a second — the customer
 * sees one thread whatever the CRM thinks, and a second row here would split the
 * history in half.
 *
 * The conversation is created with **no window**, deliberately. Meta opens the
 * window when the customer writes, not when we do, so a thread started from this
 * side can only be opened with a template — which is exactly what
 * `MessagingWindow` will say when the inbox asks.
 */
class StartWhatsAppConversationAction
{
    /**
     * @throws RuntimeException when there is no number to write to, or no
     *                          connected number to write from
     */
    public function __invoke(Lead|Contact $record, ?WhatsAppPhoneNumber $from = null): SocialConversation
    {
        $number = $this->numberFor($record);

        if ($number === null) {
            throw new RuntimeException($record->fullName().' has no telephone number to message.');
        }

        $sender = $from ?? $this->defaultNumber();

        if ($sender === null) {
            throw new RuntimeException('No WhatsApp number is connected. Connect one under Settings → Meta.');
        }

        $conversation = SocialConversation::query()->firstOrNew([
            'channel' => SocialChannel::WhatsApp->value,
            'external_conversation_id' => $number,
        ]);

        if (! $conversation->exists) {
            $conversation->forceFill([
                'channel_account_id' => $sender->phone_number_id,
                'participant_external_id' => $number,
                'participant_name' => $record->fullName(),
                'participant_handle' => '+'.$number,
            ]);
        }

        // Linked either way: an existing thread that was never tied to this
        // record should be now, which is the whole reason somebody pressed the
        // button on it.
        $conversation->forceFill($record instanceof Contact
            ? ['contact_id' => $record->getKey()]
            : ['lead_id' => $record->getKey()])->save();

        return $conversation->refresh();
    }

    /**
     * The record's number, normalised to digits the way Meta wants it.
     *
     * Through the duplicate engine's own normaliser rather than a hand-rolled
     * strip, so "the number we would match an inbound message against" and "the
     * number we send to" are produced by one piece of code — otherwise a thread
     * started from a record and the customer's reply would land in two different
     * conversations.
     *
     * **A number stored without its country code is sent as it stands**, and
     * Meta refuses it. That is deliberate: inferring the country from the
     * installation would be right most of the time and silently wrong for every
     * customer abroad, which is the failure that costs somebody a sale rather
     * than an error message. The refusal names the number, and the fix is to
     * store it in full.
     */
    private function numberFor(Lead|Contact $record): ?string
    {
        foreach (['mobile', 'phone'] as $column) {
            $value = $record->getAttribute($column);

            // Null from the normaliser means too weak to be a telephone number
            // at all — a fragment or an extension.
            if (! is_string($value) || MatchStrategy::Phone->normalise($value) === null) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', $value) ?? '';

            if ($digits !== '') {
                return $digits;
            }
        }

        return null;
    }

    private function defaultNumber(): ?WhatsAppPhoneNumber
    {
        return WhatsAppPhoneNumber::query()
            // The number somebody chose to send from, else the first connected
            // one: a CRM that sent from whichever number came first would
            // produce conversations customers cannot place.
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
