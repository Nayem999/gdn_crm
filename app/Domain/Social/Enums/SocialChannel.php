<?php

namespace App\Domain\Social\Enums;

use App\Domain\Meta\Enums\MetaChannel;

/**
 * Where a conversation is happening.
 *
 * The whole reason the inbox is one screen. A channel decides three things and
 * nothing else: how long the reply window is, what an outbound message is posted
 * to, and which icon it wears. Everything else — threading, assignment, becoming
 * a lead, the CRM actions beside the thread — is identical, and a second model
 * per channel would be a second set of all of it to keep in step.
 *
 * Instagram is deliberately absent rather than present and unused. It is out of
 * scope for this phase (12.9), it needs its own permission and an Instagram
 * account linked to the page, and a case here that nothing can produce would be
 * a promise the inbox does not keep.
 */
enum SocialChannel: string
{
    case Messenger = 'messenger';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Messenger => 'Messenger',
            self::WhatsApp => 'WhatsApp',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Messenger => 'lucide-messages-square',
            self::WhatsApp => 'lucide-message-circle',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Messenger => 'indigo',
            self::WhatsApp => 'emerald',
        };
    }

    /**
     * How long after the customer's last message a free-form reply is allowed.
     *
     * **Meta's rule, not ours**, and it differs per channel: Messenger gives
     * seven days from the last message, WhatsApp twenty-four hours. Stated here
     * once so the inbox, the send action and the timeline cannot disagree about
     * whether somebody may answer.
     */
    public function replyWindowHours(): int
    {
        return match ($this) {
            self::Messenger => 24 * 7,
            self::WhatsApp => 24,
        };
    }

    /**
     * What Meta calls this channel's window, in its own words.
     *
     * Stated per channel rather than derived from the hours, because the two
     * disagree: twenty-four hours is "the 24-hour window" everywhere in Meta's
     * documentation and in every conversation an agent will have about it, and
     * a phrase computed from the number renders it as "1-day" — which is
     * correct arithmetic, means the same thing, and matches nothing anybody can
     * search for.
     */
    public function windowLabel(): string
    {
        return match ($this) {
            self::Messenger => '7-day',
            self::WhatsApp => '24-hour',
        };
    }

    /**
     * What Meta calls the thing outside the window that may still be sent.
     *
     * Different words for the same restriction, and the inbox says the channel's
     * own word: telling a WhatsApp agent about "message tags" would send them
     * looking through the wrong documentation.
     */
    public function outOfWindowRemedy(): string
    {
        return match ($this) {
            self::Messenger => 'a message tag',
            self::WhatsApp => 'an approved template',
        };
    }

    /**
     * The Meta webhook channel a conversation on this channel arrives through.
     */
    public function metaChannel(): MetaChannel
    {
        return match ($this) {
            self::Messenger => MetaChannel::Messenger,
            self::WhatsApp => MetaChannel::WhatsApp,
        };
    }

    /**
     * The lead source a record created from this conversation carries.
     *
     * A string rather than the enum, because `LeadSource` is the leads module's
     * vocabulary and this enum is the inbox's — naming the case here would tie
     * the two together in the direction that makes the inbox depend on leads.
     */
    public function leadSource(): string
    {
        return match ($this) {
            self::Messenger => 'facebook_messenger',
            self::WhatsApp => 'whatsapp',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
