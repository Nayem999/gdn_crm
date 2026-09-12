<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\Models\PriceBook;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes one book the default, and the others not.
 *
 * Both halves in one transaction, because the moment between clearing the old
 * default and setting the new one is a moment when nothing is default and every
 * price resolves to the catalogue. On a busy installation that is a handful of
 * quotes priced wrong, and nothing in the data afterwards says why.
 *
 * A book that cannot apply cannot be the default: an inactive or out-of-date
 * default is a default that silently does nothing, and every price would
 * resolve to the catalogue with the screen still showing a book selected.
 */
class SetDefaultPriceBookAction
{
    /**
     * @throws RuntimeException when the book could not be used as the default
     */
    public function __invoke(PriceBook $book): PriceBook
    {
        if (! $book->is_active) {
            throw new RuntimeException('A price book has to be switched on before it can be the default.');
        }

        if (! $book->appliesOn()) {
            throw new RuntimeException('That price book is outside its dates, so it cannot be the default.');
        }

        DB::transaction(function () use ($book): void {
            PriceBook::query()->where('is_default', true)->update(['is_default' => false]);

            $book->forceFill(['is_default' => true])->save();
        });

        return $book->fresh() ?? $book;
    }
}
