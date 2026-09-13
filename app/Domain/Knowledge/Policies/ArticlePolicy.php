<?php

namespace App\Domain\Knowledge\Policies;

use App\Domain\Knowledge\Models\Article;
use App\Models\User;

/**
 * An article is company knowledge, not somebody's record, so there is no
 * access-level question here — an agent who could only see the articles they
 * wrote would be looking things up in an empty library.
 *
 * What is gated is the draft: somebody who may only read the knowledge base
 * sees published articles only, so nobody repeats an unfinished answer to a
 * customer.
 */
class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('knowledge.view');
    }

    public function view(User $user, Article $article): bool
    {
        if (! $user->can('knowledge.view')) {
            return false;
        }

        return $article->isPublished() || $user->can('knowledge.update');
    }

    public function create(User $user): bool
    {
        return $user->can('knowledge.create');
    }

    public function update(User $user, Article $article): bool
    {
        return $user->can('knowledge.update');
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->can('knowledge.delete');
    }

    /**
     * Publishing is its own permission: writing a draft and putting it in front
     * of customers are different acts, and plenty of desks let anyone do the
     * first and nobody but a lead do the second.
     */
    public function publish(User $user, Article $article): bool
    {
        return $user->can('knowledge.publish');
    }
}
