<?php

namespace App\Domain\Timeline\Actions;

use App\Domain\Timeline\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Take a document off a record, file and all.
 *
 * The bytes go with the row on purpose: leaving the file behind would mean a
 * "deleted" contract still sitting on disk with nothing pointing at it, which
 * is worse than either outcome. What was removed and by whom is in the audit
 * log, written before the row goes.
 */
class DeleteDocumentAction
{
    public function __invoke(Document $document): void
    {
        DB::transaction(function () use ($document) {
            $document->clearMediaCollection('file');
            $document->delete();
        });
    }
}
