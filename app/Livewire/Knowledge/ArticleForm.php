<?php

namespace App\Livewire\Knowledge;

use App\Domain\Knowledge\Actions\CreateArticleAction;
use App\Domain\Knowledge\Actions\UpdateArticleAction;
use App\Domain\Knowledge\DTOs\ArticleData;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\Category;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Write or edit one article.
 *
 * There is no status control: PublishArticleAction owns it, and publishing
 * happens from the article's own page where the thing being published is on
 * screen. A publish button on a form is a publish button somebody presses
 * before reading what they wrote.
 */
class ArticleForm extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $articleId = null;

    public string $title = '';

    public string $body = '';

    public ?string $excerpt = null;

    public ?string $keywords = null;

    public ?string $kb_category_id = null;

    public function mount(?Article $article = null): void
    {
        if ($article?->exists) {
            $this->authorize('update', $article);

            $this->articleId = $article->id;
            $this->title = $article->title;
            $this->body = $article->body;
            $this->excerpt = $article->excerpt;
            $this->keywords = $article->keywords;
            $this->kb_category_id = $article->kb_category_id === null ? null : (string) $article->kb_category_id;

            return;
        }

        $this->authorize('create', Article::class);
    }

    public function article(): ?Article
    {
        return $this->articleId === null
            ? null
            : Article::query()->whereKey($this->articleId)->first();
    }

    public function isEditing(): bool
    {
        return $this->articleId !== null;
    }

    /**
     * Every section, each labelled with its parent's name so a subsection
     * called "Setup" is not one of four identical rows.
     *
     * PHP casts a numeric string array key back to int, so these keys are ints
     * however they are written — typed as such rather than pretending.
     *
     * @return array<int, string>
     */
    public function categoryOptions(): array
    {
        $options = [];

        foreach (Category::query()->with('parent')->ordered()->get() as $category) {
            $options[(string) $category->id] = $category->path();
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:200000'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'kb_category_id' => ['nullable', 'integer', 'exists:kb_categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'kb_category_id' => 'section',
            'excerpt' => 'summary',
            'keywords' => 'search words',
        ];
    }

    public function save(): void
    {
        $article = $this->article();

        $article === null
            ? $this->authorize('create', Article::class)
            : $this->authorize('update', $article);

        $this->validate();

        $data = ArticleData::fromArray([
            'title' => $this->title,
            'body' => $this->body,
            'excerpt' => $this->excerpt,
            'keywords' => $this->keywords,
            'kb_category_id' => $this->kb_category_id,
        ]);

        $saved = $article === null
            ? app(CreateArticleAction::class)($data, $this->currentUser())
            : app(UpdateArticleAction::class)($article, $data);

        session()->flash('status', 'The article has been saved.');

        $this->redirectRoute('knowledge.show', ['article' => $saved->slug], navigate: true);
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    public function render(): View
    {
        return view('livewire.knowledge.article-form')
            ->title($this->isEditing() ? 'Edit article' : 'New article');
    }
}
