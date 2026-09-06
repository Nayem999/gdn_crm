<?php

namespace App\Domain\Timeline\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Models\User;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Something somebody wrote about a record.
 *
 * A note carries no authorization of its own: NotePolicy answers by asking
 * whether the person may see the record it hangs off, so a note can never be a
 * way around a module's access level.
 *
 * @property int $id
 * @property string $notable_type
 * @property int $notable_id
 * @property int|null $author_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read Model|null $notable
 */
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['notable_type', 'notable_id', 'author_id', 'body'];

    /**
     * The body is the note, so a change to it is the whole story. The subject
     * is logged too: it is how "a note was removed from this lead" stays
     * answerable once the row is gone.
     *
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['notable_type', 'notable_id', 'author_id', 'body'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The first line, for an audit entry or a listing that has one line to
     * spend. Never the whole body: a note can be long.
     */
    public function excerpt(int $characters = 80): string
    {
        return str($this->body)->squish()->limit($characters)->toString();
    }

    public function wasEdited(): bool
    {
        return $this->created_at !== null
            && $this->updated_at !== null
            && $this->updated_at->gt($this->created_at);
    }
}
