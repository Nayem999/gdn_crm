<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Enums\ArticleStatus;
use App\Domain\Knowledge\Models\Article;
use Illuminate\Support\Carbon;

/**
 * The only writer of an article's status.
 *
 * `published_at` is stamped the first time an article goes out and kept
 * afterwards: an article unpublished for a correction and published again was
 * first published when it first was, and a date that jumped forward would
 * reorder every list that sorts by it.
 */
class PublishArticleAction
{
    /**
     * @return bool whether anything moved
     */
    public function __invoke(Article $article, ArticleStatus $status, ?Carbon $now = null): bool
    {
        if ($article->status() === $status) {
            return false;
        }

        $article->forceFill([
            'status' => $status->value,
            'published_at' => $status === ArticleStatus::Published
                ? ($article->published_at ?? $now ?? now())
                : $article->published_at,
        ])->save();

        return true;
    }
}
