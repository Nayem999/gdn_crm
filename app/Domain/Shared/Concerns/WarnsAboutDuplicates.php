<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Duplicates\DuplicateFinder;
use App\Domain\Shared\Duplicates\DuplicateMatch;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A duplicate warning while a record is being typed, not after it is saved.
 *
 * The cheapest duplicate to deal with is the one never created, so the form
 * matches on an unsaved model built from what has been entered so far. Only the
 * fields the module's rules actually name are read off the component, and the
 * record being edited is excluded by id — an unsaved draft has no key of its
 * own to exclude it by.
 */
trait WarnsAboutDuplicates
{
    abstract public function duplicateSource(): ?DuplicateSource;

    /**
     * The id being edited, so an edit does not report itself as its own
     * duplicate. Null while capturing.
     */
    abstract public function duplicateIgnoreId(): ?int;

    /**
     * @return array<int, DuplicateMatch>
     */
    public function draftDuplicates(): array
    {
        $source = $this->duplicateSource();
        $user = auth()->user();

        if ($source === null || ! $user instanceof User) {
            return [];
        }

        return app(DuplicateFinder::class)->for(
            $source,
            $this->draftRecord($source),
            $user,
            $this->duplicateIgnoreId(),
        );
    }

    public function mergeRouteFor(int $id): string
    {
        return route('duplicates.merge', [
            'module' => $this->duplicateSource()?->key(),
            'record' => $id,
        ]);
    }

    /**
     * An unsaved model carrying just the values the rules match on.
     */
    private function draftRecord(DuplicateSource $source): Model
    {
        $class = $source->modelClass();
        $draft = new $class;

        foreach ($source->rules() as $rule) {
            foreach ($rule->fields as $field) {
                // The forms name their properties after their columns, so a
                // rule field either exists on the component or is not something
                // this form collects.
                if (property_exists($this, $field)) {
                    $draft->setAttribute($field, $this->{$field});
                }
            }
        }

        return $draft;
    }
}
