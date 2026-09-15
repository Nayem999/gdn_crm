<?php

namespace App\Domain\Social\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Duplicates\MatchStrategy;
use App\Domain\Shared\Models\DuplicateKey;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use Illuminate\Database\Eloquent\Model;

/**
 * Finding the customer a conversation is already about.
 *
 * Only WhatsApp can do this, and the reason is worth stating: a WhatsApp thread
 * **is** a telephone number, so it can be matched against numbers the CRM
 * already holds. A Messenger thread is a page-scoped id that means nothing
 * outside Meta — there is no field on a contact it could ever equal — so there
 * is nothing to match on and guessing would attach one customer's messages to
 * another customer's record.
 *
 * Matched through the fingerprints the duplicate engine already keeps rather
 * than a `where phone = ?`: those compare the last nine digits, so a customer
 * writing from `+44 7700 900123` is found on a record somebody typed as
 * `07700 900123`. A column comparison misses that and makes a second lead for
 * somebody the company has known for years.
 *
 * **A contact outranks a lead.** Both may match the same number, and the contact
 * is the record people actually work from — a lead that has been converted is
 * somebody the company decided to keep, and putting their messages back on the
 * lead would bury them.
 */
class MatchConversationToRecordAction
{
    /**
     * Link the conversation to a record it matches, and say whether it did.
     */
    public function __invoke(SocialConversation $conversation): bool
    {
        $fingerprint = $this->fingerprint($conversation);

        if ($fingerprint === null) {
            return false;
        }

        $contact = $this->find(Contact::class, $fingerprint);

        if ($contact instanceof Contact) {
            $conversation->forceFill([
                'contact_id' => $contact->getKey(),
                // A contact reached through a converted lead keeps that lead on
                // the conversation too, so the thread still shows where it came
                // from.
                'lead_id' => $conversation->lead_id,
            ])->save();

            return true;
        }

        $lead = $this->find(Lead::class, $fingerprint);

        if ($lead instanceof Lead) {
            $conversation->forceFill(['lead_id' => $lead->getKey()])->save();

            return true;
        }

        return false;
    }

    /**
     * The number this conversation is with, normalised the way the duplicate
     * engine normalises the ones already stored.
     */
    private function fingerprint(SocialConversation $conversation): ?string
    {
        if ($conversation->channel() !== SocialChannel::WhatsApp) {
            return null;
        }

        // Null means "too weak to match on" — under seven digits is a fragment,
        // and matching on it would collide freely.
        return MatchStrategy::Phone->normalise($conversation->external_conversation_id);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function find(string $modelClass, string $fingerprint): ?Model
    {
        /** @var array<int, int> $ids */
        $ids = DuplicateKey::query()
            ->where('keyable_type', $modelClass)
            ->where('kind', MatchStrategy::Phone->value)
            ->where('value', $fingerprint)
            ->pluck('keyable_id')
            ->all();

        if ($ids === []) {
            return null;
        }

        return $modelClass::query()
            ->whereKey($ids)
            // A record merged away is a resolved duplicate; threading onto it
            // would put the conversation on the one somebody retired.
            ->whereNull('merged_into_id')
            // The oldest match is the original.
            ->orderBy('id')
            ->first();
    }
}
