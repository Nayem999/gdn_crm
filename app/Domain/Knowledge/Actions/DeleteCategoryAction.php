<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\Category;

/**
 * Removes a section without taking its articles with it.
 *
 * The articles are left uncategorised rather than deleted or moved somewhere
 * arbitrary: the answer they hold is still the answer, and an article that
 * vanished because somebody tidied the sections is a support failure nobody
 * would trace back to this.
 */
class DeleteCategoryAction
{
    public function __invoke(Category $category): void
    {
        $category->articles()->update(['kb_category_id' => null]);
        $category->children()->update(['parent_id' => null]);

        $category->delete();
    }
}
