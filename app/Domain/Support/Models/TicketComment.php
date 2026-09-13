<?php

namespace App\Domain\Support\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Models\User;
use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One thing said on a ticket, by us or by the customer.
 *
 * A comment carries no authorization of its own: TicketCommentPolicy answers by
 * asking whether the person may see the ticket it hangs off, so a comment can
 * never be a way around the support module's access level. Notes on other
 * modules work the same way.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $author_id
 * @property string|null $author_name
 * @property string $body
 * @property bool $is_internal
 * @property bool $from_customer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read Ticket $ticket
 */
class TicketComment extends Model
{
    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'author_id',
        'author_name',
        'body',
        'is_internal',
        'from_customer',
    ];

    /**
     * Defaults for the two flags, so an instance built without them answers
     * the "is this internal" question rather than returning null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_internal' => false,
        'from_customer' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'from_customer' => 'boolean',
        ];
    }

    /**
     * An explicit allowlist, never logAll().
     *
     * `is_internal` is on it because a comment changing from internal to public
     * is a comment the customer can now read, and "who made that visible" has
     * to stay answerable.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['ticket_id', 'author_id', 'body', 'is_internal', 'from_customer'];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Who said it, for a page that has one line to spend on the question.
     */
    public function authorLabel(): string
    {
        $author = $this->author;

        if ($author !== null) {
            return $author->name;
        }

        return $this->author_name
            ?? ($this->from_customer ? 'The customer' : 'Somebody who has since left');
    }

    /**
     * The first line, for an audit entry or a listing. Never the whole body.
     */
    public function excerpt(int $characters = 120): string
    {
        return str($this->body)->squish()->limit($characters)->toString();
    }

    /**
     * @param  Builder<TicketComment>  $query
     * @return Builder<TicketComment>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_internal'), false);
    }
}
