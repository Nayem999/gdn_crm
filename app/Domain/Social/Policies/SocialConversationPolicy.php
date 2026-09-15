<?php

namespace App\Domain\Social\Policies;

use App\Domain\Social\Models\SocialConversation;
use App\Models\User;

/**
 * Who may read the inbox, answer it, and hand a thread to somebody.
 *
 * Three permissions rather than one, and the split is the one the brief asks for
 * under different names (`social.inbox.*` rather than `facebook.inbox.*`, since
 * the inbox is one screen for every channel).
 *
 * Reading is separated from replying because they are genuinely different acts:
 * a manager reviewing what was said to customers should not need the ability to
 * say something to one, and a message sent from the company's page cannot be
 * unsent.
 *
 * No record-level scoping. A shared inbox is shared — see
 * `SocialConversation` for why hiding unclaimed threads would empty the queue.
 */
class SocialConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('social.inbox.view');
    }

    public function view(User $user, SocialConversation $conversation): bool
    {
        return $user->can('social.inbox.view');
    }

    /**
     * Sending a message to a customer.
     */
    public function reply(User $user, SocialConversation $conversation): bool
    {
        return $user->can('social.inbox.reply');
    }

    /**
     * Opening a new conversation from a record.
     *
     * Takes no conversation, deliberately: there is not one yet, and a policy
     * method requiring a record could not be called with a class name at all.
     * Gated on replying rather than viewing, because starting a thread sends a
     * message from the company's number.
     */
    public function start(User $user): bool
    {
        return $user->can('social.inbox.reply');
    }

    /**
     * Claiming a thread, handing it on, closing it, marking it read.
     *
     * One permission for all four: they are the same act of saying who is
     * dealing with something, and splitting them would produce a screen where
     * somebody can take a conversation and not put it back.
     */
    public function assign(User $user, SocialConversation $conversation): bool
    {
        return $user->can('social.inbox.assign');
    }
}
