<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\DTOs\ArticleData;
use App\Domain\Knowledge\Models\Article;

class UpdateArticleAction
{
    public function __invoke(Article $article, ArticleData $data): Article
    {
        $attributes = $data->toAttributes();

        // The slug follows the title only while the article is a draft. Once it
        // is published the URL is in somebody's bookmarks and in the reply an
        // agent sent a customer last week, and a tidier slug is not worth
        // breaking those.
        if (! $article->isPublished()) {
            $attributes['slug'] = Article::slugFor($data->title, $article->id);
        }

        $article->update($attributes);

        return $article->refresh();
    }
}
