<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\Category;
use RuntimeException;

class SaveCategoryAction
{
    /**
     * @throws RuntimeException when the nesting would go deeper than one level
     */
    public function __invoke(Category $category, string $name, ?int $parentId = null, ?string $description = null): Category
    {
        $this->guardNesting($category, $parentId);

        $category->forceFill([
            'name' => $name,
            'slug' => $category->exists && $category->name === $name
                ? $category->slug
                : Category::slugFor($name, $category->exists ? $category->id : null),
            'parent_id' => $parentId,
            'description' => $description,
        ])->save();

        return $category->refresh();
    }

    /**
     * One level, and never its own parent.
     *
     * A section inside a subsection is how a library becomes unsearchable, and
     * a cycle would make the tree unrenderable — an infinite loop in a view is
     * a blank page with nothing in the log.
     */
    private function guardNesting(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($category->exists && $parentId === $category->id) {
            throw new RuntimeException('A section cannot sit inside itself.');
        }

        $parent = Category::query()->whereKey($parentId)->first();

        if ($parent === null) {
            throw new RuntimeException('That section does not exist.');
        }

        if (! $parent->isTopLevel()) {
            throw new RuntimeException('Sections go one level deep. Choose a top-level section.');
        }

        if ($category->exists && $category->children()->exists()) {
            throw new RuntimeException('This section has subsections of its own, so it cannot become one.');
        }
    }
}
