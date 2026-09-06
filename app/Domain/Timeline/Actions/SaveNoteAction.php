<?php

namespace App\Domain\Timeline\Actions;

use App\Domain\Timeline\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Write a note onto a record, or change one that is already there.
 *
 * The subject is passed as a model, never as a type string plus an id: that is
 * what stops a request naming a class the person may not touch.
 */
class SaveNoteAction
{
    public function create(Model $subject, User $author, string $body): Note
    {
        $note = new Note([
            'notable_type' => $subject->getMorphClass(),
            'notable_id' => $subject->getKey(),
            'author_id' => $author->id,
            'body' => trim($body),
        ]);

        $note->save();

        // So the entry can name its author without a second query.
        $note->setRelation('author', $author);

        return $note;
    }

    public function update(Note $note, string $body): Note
    {
        // The author never moves. A note is a record of who said what, and
        // editing the words does not make somebody else the person who said
        // them.
        $note->forceFill(['body' => trim($body)])->save();

        return $note;
    }
}
