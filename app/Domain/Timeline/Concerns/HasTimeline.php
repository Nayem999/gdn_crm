<?php

namespace App\Domain\Timeline\Concerns;

use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What a record contributes to its own timeline.
 *
 * The third strand, history, needs nothing here: it comes from the activities()
 * relation RecordsActivity already provides, which every module using this
 * trait also uses.
 */
trait HasTimeline
{
    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
