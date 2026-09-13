<?php

namespace App\Livewire\Knowledge;

use App\Domain\Knowledge\Actions\DeleteArticleAction;
use App\Domain\Knowledge\Actions\PublishArticleAction;
use App\Domain\Knowledge\Actions\RecordArticleFeedbackAction;
use App\Domain\Knowledge\Enums\ArticleStatus;
use App\Domain\Knowledge\Models\Article;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One article, and the two things a reader does with it: use it, and say
 * whether it helped.
 *
 * Publishing happens here rather than on the form, so the thing being published
 * is on screen when somebody presses the button.
 */
class ArticleShow extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $articleId;

    /**
     * Whether this reader has already answered "did this help?".
     *
     * Held on the component rather than stored per user: the question is a
     * temperature reading, not a vote worth a table and a unique index, and one
     * enthusiast clicking twice is a smaller problem than a join on every view.
     */
    public bool $answered = false;

    public function mount(Article $article): void
    {
        $this->authorize('view', $article);

        $this->articleId = $article->id;

        // Counted once per page open, not once per Livewire round trip — every
        // later request re-renders without passing through mount().
        $article->newQuery()->whereKey($article->getKey())->increment('view_count');
    }

    public function article(): Article
    {
        return Article::query()
            ->with(['category.parent', 'author:id,name'])
            ->findOrFail($this->articleId);
    }

    public function publish(): void
    {
        $article = $this->article();

        $this->authorize('publish', $article);

        if (app(PublishArticleAction::class)($article, ArticleStatus::Published)) {
            $this->dispatch('notify', type: 'success', message: 'The article is published.');
        }
    }

    public function unpublish(): void
    {
        $article = $this->article();

        $this->authorize('publish', $article);

        // Back to a draft rather than archived: unpublishing is almost always
        // "this is wrong, I am fixing it", and archiving is the deliberate
        // "this is finished with".
        if (app(PublishArticleAction::class)($article, ArticleStatus::Draft)) {
            $this->dispatch('notify', type: 'success', message: 'The article is no longer published.');
        }
    }

    public function archive(): void
    {
        $article = $this->article();

        $this->authorize('publish', $article);

        if (app(PublishArticleAction::class)($article, ArticleStatus::Archived)) {
            $this->dispatch('notify', type: 'success', message: 'The article has been archived.');
        }
    }

    public function markHelpful(bool $helpful): void
    {
        if ($this->answered) {
            return;
        }

        app(RecordArticleFeedbackAction::class)($this->article(), $helpful);

        $this->answered = true;

        $this->dispatch('notify', type: 'success', message: $helpful
            ? 'Glad it helped.'
            : 'Thank you — that tells us it needs work.');
    }

    public function delete(): void
    {
        $article = $this->article();

        $this->authorize('delete', $article);

        app(DeleteArticleAction::class)($article);

        session()->flash('status', 'The article has been removed.');

        $this->redirectRoute('knowledge.index', navigate: true);
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('update', $this->article()) === true;
    }

    public function canPublish(): bool
    {
        return auth()->user()?->can('publish', $this->article()) === true;
    }

    public function render(): View
    {
        $article = $this->article();

        return view('livewire.knowledge.article-show', ['article' => $article])
            ->title($article->title);
    }
}
