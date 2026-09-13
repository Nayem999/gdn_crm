<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\DTOs\ArticleData;
use App\Domain\Knowledge\Enums\ArticleStatus;
use App\Domain\Knowledge\Models\Article;
use App\Models\User;

class CreateArticleAction
{
    public function __invoke(ArticleData $data, User $author): Article
    {
        $article = new Article;

        // forceFill rather than create(): status is out of $fillable so no form
        // can write it, and the opening status is this action's to set.
        $article->forceFill([
            ...$data->toAttributes(),
            'slug' => Article::slugFor($data->title),
            'author_id' => $author->id,
            'status' => ArticleStatus::Draft->value,
        ])->save();

        return $article->refresh();
    }
}
