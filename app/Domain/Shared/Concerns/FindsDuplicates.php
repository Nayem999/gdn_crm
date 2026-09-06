<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Duplicates\DuplicateFinder;
use App\Domain\Shared\Duplicates\DuplicateMatch;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Duplicate hints for a Livewire screen, whether it is showing a record or
 * capturing one.
 *
 * A form has no saved record to compare, so it builds an unsaved model from
 * what has been typed and matches on that. That is the whole point of warning
 * at capture time: the cheapest duplicate to deal with is the one that is not
 * created.
 */
trait FindsDuplicates
{
    abstract public function duplicateSource(): ?DuplicateSource;

    /**
     * @return array<int, DuplicateMatch>
     */
    public function duplicatesOf(?Model $record): array
    {
        $source = $this->duplicateSource();
        $user = auth()->user();

        if ($source === null || $record === null || ! $user instanceof User) {
            return [];
        }

        return app(DuplicateFinder::class)->for($source, $record, $user);
    }

    /**
     * Whether this screen should be showing a duplicate warning at all.
     */
    public function canMergeDuplicates(?Model $record): bool
    {
        $user = auth()->user();

        return $record !== null
            && $user instanceof User
            && $user->can('merge', $record);
    }

    public function mergeRoute(Model $record): string
    {
        $source = $this->duplicateSource();

        return route('duplicates.merge', [
            'module' => $source?->key(),
            'record' => $record->getKey(),
        ]);
    }
}
