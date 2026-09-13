<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\Article;

/**
 * "Did this help?", counted.
 *
 * Two counters rather than a score: "40 of 50" and "4 of 5" are different
 * amounts of evidence, and one number loses that. Incremented in the database
 * rather than read-modify-written, so two readers answering at once do not lose
 * a vote.
 */
class RecordArticleFeedbackAction
{
    public function __invoke(Article $article, bool $helpful): void
    {
        $article->newQuery()->whereKey($article->getKey())->increment(
            $helpful ? 'helpful_count' : 'unhelpful_count'
        );
    }
}
