<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Models\SocialConversation;
use App\Models\User;

/**
 * Who is answering this one.
 *
 * Assignment in a shared inbox is a claim, not a permission: everybody who can
 * open the inbox can see every thread, and this says which of them is dealing
 * with it so that two people do not answer the same customer with two different
 * answers.
 *
 * Claiming an unanswered thread **opens** it rather than leaving it as it was: a
 * conversation somebody has just taken is by definition work in hand, and the
 * one state that would be wrong afterwards is Closed.
 */
class AssignConversationAction
{
    public function assign(SocialConversation $conversation, User $user): SocialConversation
    {
        $conversation->forceFill([
            'assigned_to_id' => $user->getKey(),
            // Taking a closed conversation reopens it; anything else keeps the
            // state it had, because "waiting on them" survives a handover.
            'status' => $conversation->status()->isClosed()
                ? ConversationStatus::Open->value
                : $conversation->getAttributeValue('status'),
        ])->save();

        return $conversation->refresh();
    }

    /**
     * Put it back in the queue.
     *
     * The status is left alone. A thread nobody is holding is not thereby
     * unanswered — it may have been replied to an hour ago — and moving it to
     * Open would put answered work back on somebody's list.
     */
    public function release(SocialConversation $conversation): SocialConversation
    {
        $conversation->forceFill(['assigned_to_id' => null])->save();

        return $conversation->refresh();
    }

    /**
     * Mark it dealt with.
     *
     * Closing is never final: the customer writing again reopens it, which is
     * `RecordInboundMessageAction`'s job and the reason closing is safe to do
     * freely.
     */
    public function close(SocialConversation $conversation): SocialConversation
    {
        $conversation->forceFill([
            'status' => ConversationStatus::Closed->value,
            // Closing is also reading: a thread cannot be both dealt with and
            // unread.
            'unread_count' => 0,
        ])->save();

        return $conversation->refresh();
    }

    public function reopen(SocialConversation $conversation): SocialConversation
    {
        $conversation->forceFill(['status' => ConversationStatus::Open->value])->save();

        return $conversation->refresh();
    }

    /**
     * Somebody has looked at it.
     */
    public function markRead(SocialConversation $conversation): SocialConversation
    {
        if ($conversation->unread_count === 0) {
            return $conversation;
        }

        $conversation->forceFill(['unread_count' => 0])->save();

        return $conversation->refresh();
    }
}
