<?php

namespace App\Livewire\Knowledge;

use App\Domain\Knowledge\Actions\DeleteCategoryAction;
use App\Domain\Knowledge\Actions\SaveCategoryAction;
use App\Domain\Knowledge\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Arranging the library.
 *
 * Its own screen rather than a panel on the index: filing is an editorial job
 * done occasionally, and putting it beside the search box would offer every
 * reader a "remove section" button they have no use for.
 */
#[Title('Knowledge base sections')]
class KnowledgeSections extends Component
{
    use AuthorizesRequests;

    public ?int $editingId = null;

    public bool $editing = false;

    public string $name = '';

    public string $description = '';

    public ?string $parentId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Category::class);
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

    /**
     * The sections a new one can sit inside: top-level only, because the tree
     * goes one level deep.
     *
     * Int-keyed for the reason ArticleForm::categoryOptions() is: PHP casts a
     * numeric string key straight back to an int.
     *
     * @return array<int, string>
     */
    public function parentOptions(): array
    {
        $options = [];

        foreach (Category::query()->topLevel()->ordered()->get() as $category) {
            if ($category->id === $this->editingId) {
                // A section cannot be its own parent, and offering it would
                // make that the easiest mistake on the screen.
                continue;
            }

            $options[(string) $category->id] = $category->name;
        }

        return $options;
    }

    public function create(): void
    {
        $this->authorize('create', Category::class);

        $this->resetForm();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $category = Category::query()->findOrFail($id);

        $this->authorize('update', $category);

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->description = (string) $category->description;
        $this->parentId = $category->parent_id === null ? null : (string) $category->parent_id;
        $this->editing = true;
    }

    public function save(): void
    {
        $category = $this->editingId === null
            ? new Category
            : Category::query()->findOrFail($this->editingId);

        $category->exists
            ? $this->authorize('update', $category)
            : $this->authorize('create', Category::class);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'parentId' => ['nullable', 'integer', 'exists:kb_categories,id'],
        ], attributes: ['parentId' => 'parent section']);

        try {
            app(SaveCategoryAction::class)(
                $category,
                $this->name,
                $this->parentId === null || $this->parentId === '' ? null : (int) $this->parentId,
                $this->description === '' ? null : $this->description,
            );
        } catch (RuntimeException $exception) {
            $this->addError('parentId', $exception->getMessage());

            return;
        }

        unset($this->sections);

        $this->editing = false;
        $this->resetForm();

        $this->dispatch('notify', type: 'success', message: 'The section has been saved.');
    }

    public function delete(int $id): void
    {
        $category = Category::query()->findOrFail($id);

        $this->authorize('delete', $category);

        app(DeleteCategoryAction::class)($category);

        unset($this->sections);

        $this->dispatch('notify', type: 'success', message: 'The section has gone. Its articles are still here, unfiled.');
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->parentId = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.knowledge.knowledge-sections');
    }
}
