<?php

namespace App\Domain\Meta\Enums;

/**
 * The three things Meta sends us, and what each one is.
 *
 * Separate channels rather than one webhook because Meta subscribes them
 * separately, signs them the same way but scopes them differently, and — the
 * part that matters here — *fails* separately. A page whose lead-ads
 * subscription lapsed should not make the WhatsApp number look broken, and one
 * delivery log filtered by channel is how somebody finds that out.
 */
enum MetaChannel: string
{
    case LeadGen = 'leadgen';
    case Messenger = 'messenger';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::LeadGen => 'Facebook Lead Ads',
            self::Messenger => 'Facebook Messenger',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /**
     * The `object` Meta puts at the top of the payload. A delivery whose object
     * does not match the channel it arrived on is one we refuse: Meta does not
     * cross them, so anything that does is not Meta.
     */
    public function object(): string
    {
        return match ($this) {
            self::LeadGen, self::Messenger => 'page',
            self::WhatsApp => 'whatsapp_business_account',
        };
    }

    /**
     * The field inside the subscription this channel listens to.
     */
    public function field(): string
    {
        return match ($this) {
            self::LeadGen => 'leadgen',
            self::Messenger => 'messages',
            self::WhatsApp => 'messages',
        };
    }

    /**
     * What the source this channel writes through is called in the delivery log.
     */
    public function sourceName(): string
    {
        return 'Meta — '.$this->label();
    }

    /**
     * Which module a delivery on this channel can create records in.
     *
     * All three are leads, and deliberately: a Messenger conversation from
     * somebody unknown becomes a lead, not a contact, because a contact is
     * somebody the company has decided to keep.
     */
    public function targetModule(): string
    {
        return 'leads';
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
