<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\Article;

class DeleteArticleAction
{
    /**
     * Soft-deleted. An article is what a customer was told, and "we have no
     * record of what that page said" is the same bad answer a removed ticket
     * gives.
     */
    public function __invoke(Article $article): void
    {
        $article->delete();
    }
}
