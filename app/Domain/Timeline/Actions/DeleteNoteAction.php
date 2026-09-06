<?php

namespace App\Domain\Timeline\Actions;

use App\Domain\Timeline\Models\Note;

/**
 * Take a note off a record.
 *
 * The row goes, but RecordsActivity has already written the deletion — with the
 * body and the subject it hung off — into the audit log before it does, so what
 * was removed and by whom stays answerable.
 */
class DeleteNoteAction
{
    public function __invoke(Note $note): void
    {
        $note->delete();
    }
}
