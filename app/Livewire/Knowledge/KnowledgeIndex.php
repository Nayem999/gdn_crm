<?php

namespace App\Livewire\Knowledge;

use App\Domain\Knowledge\ArticleSearch;
use App\Domain\Knowledge\Enums\ArticleStatus;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The library: search across everything, or browse by section.
 *
 * Deliberately not the data-view kit. The kit answers "which records match" and
 * pages the answer; looking something up is a different act — a ranked list of
 * a dozen results with the reason each one matched, and a section tree to fall
 * back on when the words did not work. A filter builder over an article body
 * would be a worse search, not a better one.
 */
#[Title('Knowledge base')]
class KnowledgeIndex extends Component
{
    use AuthorizesRequests;

    /**
     * How many results one search returns. More than a dozen and nobody reads
     * to the bottom; fewer and the right answer falls off.
     */
    public const RESULTS = 20;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'section', except: null)]
    public ?int $categoryId = null;

    /**
     * Drafts and archived articles, for somebody who may edit them.
     */
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Article::class);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function sections(): Collection
    {
        return Category::query()
            ->topLevel()
            ->ordered()
            ->with(['children' => fn ($query) => $query->withCount('articles')])
            ->withCount('articles')
            ->get();
    }

    public function category(): ?Category
    {
        return $this->categoryId === null
            ? null
            : Category::query()->whereKey($this->categoryId)->first();
    }

    /**
     * Whether the list on screen came from a search rather than from browsing.
     */
    public function isSearching(): bool
    {
        return trim($this->search) !== '';
    }

    /**
     * @return Collection<int, Article>
     */
    #[Computed]
    public function results(): Collection
    {
        if ($this->isSearching()) {
            $query = app(ArticleSearch::class)->query($this->search, auth()->user());

            if ($query === null) {
                return new Collection;
            }

            // A search crosses the whole library by default, because the whole
            // point of typing is not knowing where it lives. Narrowing it to a
            // section is still offered, for somebody who does know.
            if ($this->categoryId !== null) {
                $query->where('kb_articles.kb_category_id', $this->categoryId);
            }

            $this->applyStatusFilter($query);

            return $query->limit(self::RESULTS)->get();
        }

        $query = Article::query()
            ->readableBy(auth()->user())
            ->with('category:id,name,parent_id')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($this->categoryId !== null) {
            $query->where('kb_category_id', $this->categoryId);
        }

        $this->applyStatusFilter($query);

        return $query->limit(self::RESULTS)->get();
    }

    /**
     * @param  Builder<Article>  $query
     */
    private function applyStatusFilter(Builder $query): void
    {
        $status = ArticleStatus::tryFrom($this->statusFilter);

        if ($status !== null) {
            $query->where('kb_articles.status', $status->value);
        }
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return ArticleStatus::options();
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('knowledge.update') === true;
    }

    public function openSection(?int $id): void
    {
        $this->categoryId = $id;
        unset($this->results);
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        unset($this->results);
    }

    public function updatedSearch(): void
    {
        unset($this->results);
    }

    public function updatedStatusFilter(): void
    {
        unset($this->results);
    }

    public function render(): View
    {
        return view('livewire.knowledge.knowledge-index');
    }
}
