<?php

namespace App\Domain\Mail\Inbound;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Models\EmailMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * Who an arriving message is about.
 *
 * Two questions in a deliberate order.
 *
 * **What is it a reply to?** If the sender's client kept our Message-ID in
 * In-Reply-To or References, that is not a guess — it is the same conversation,
 * and it survives the customer replying from a different address, which is
 * exactly when matching on the address fails.
 *
 * **Failing that, who is the sender?** An address we already hold for a contact
 * or a lead. Contacts win over leads: a lead that has been converted still has
 * its row, and attaching a customer's reply to the lead they stopped being is
 * the wrong half of the story.
 *
 * Nothing matched is a perfectly good answer. A message from somebody we do not
 * know is stored unattached rather than guessed at — a reply filed against the
 * wrong customer is worse than one filed against nobody.
 */
class ThreadMatcher
{
    /**
     * @return array{related: Model|null, replyTo: EmailMessage|null}
     */
    public function match(InboundMessageData $message): array
    {
        $replyTo = $this->outboundMessage($message);

        if ($replyTo !== null && $replyTo->related_type !== null && $replyTo->related_id !== null) {
            $related = $this->resolve($replyTo->related_type, $replyTo->related_id);

            if ($related !== null) {
                return ['related' => $related, 'replyTo' => $replyTo];
            }
        }

        return ['related' => $this->bySender($message->fromEmail), 'replyTo' => $replyTo];
    }

    private function outboundMessage(InboundMessageData $message): ?EmailMessage
    {
        $thread = $message->thread();

        if ($thread === []) {
            return null;
        }

        // Ordered by the thread rather than by the database, so the direct
        // parent wins over an ancestor.
        foreach ($thread as $id) {
            $found = EmailMessage::query()->where('message_id', $id)->first();

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function bySender(string $email): ?Model
    {
        if ($email === '') {
            return null;
        }

        $contact = Contact::query()->where('email', $email)->first();

        if ($contact !== null) {
            return $contact;
        }

        return Lead::query()->where('email', $email)->first();
    }

    private function resolve(string $type, int $id): ?Model
    {
        $class = Model::getActualClassNameForMorph($type);

        if (! class_exists($class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;

        return $model->newQuery()->find($id);
    }
}
