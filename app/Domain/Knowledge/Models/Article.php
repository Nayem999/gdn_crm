<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Knowledge\Enums\ArticleStatus;
use App\Models\User;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something we already know, written down once.
 *
 * There is no access-level scoping here, deliberately. An article is company
 * knowledge, not somebody's record — an agent who could only see the articles
 * they wrote would be looking things up in an empty library. What *is* gated is
 * whether an unpublished draft is visible, which is a question about the
 * article's state rather than about who owns it.
 *
 * @property int $id
 * @property int|null $kb_category_id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $keywords
 * @property string $status
 * @property Carbon|null $published_at
 * @property int|null $author_id
 * @property int $view_count
 * @property int $helpful_count
 * @property int $unhelpful_count
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    protected $table = 'kb_articles';

    /**
     * `status` and `published_at` are absent: PublishArticleAction owns both,
     * the same arrangement that keeps ChangeTicketStatusAction the only writer
     * of a ticket's status. A form that could set them would be a second path
     * to "published", and the two would disagree about when.
     *
     * @var list<string>
     */
    protected $fillable = [
        'kb_category_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'keywords',
        'author_id',
    ];

    /**
     * Defaults for the columns that share a name with a method.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'view_count' => 0,
        'helpful_count' => 0,
        'unhelpful_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'view_count' => 'integer',
            'helpful_count' => 'integer',
            'unhelpful_count' => 'integer',
        ];
    }

    /**
     * An explicit allowlist. The body is on it: what an article told a customer
     * is exactly the thing somebody later needs to prove.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['title', 'slug', 'kb_category_id', 'status', 'published_at', 'body', 'keywords'];
    }

    // -- Relations -----------------------------------------------------------

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'kb_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    // -- Presentation --------------------------------------------------------

    public function displayName(): string
    {
        return $this->title;
    }

    /**
     * getAttributeValue, not $this->status: the method and the column share a
     * name — see .ai/rules/models-name-collisions.md.
     */
    public function status(): ArticleStatus
    {
        return ArticleStatus::tryFrom((string) $this->getAttributeValue('status')) ?? ArticleStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status() === ArticleStatus::Published;
    }

    /**
     * The line shown in a result list: the author's own, or the opening of the
     * body when they did not write one.
     */
    public function summary(int $characters = 160): string
    {
        $excerpt = $this->excerpt;

        if ($excerpt !== null && trim($excerpt) !== '') {
            return $excerpt;
        }

        return Str::of($this->body)->squish()->limit($characters)->toString();
    }

    /**
     * How useful readers found it, or null when nobody has said.
     *
     * A percentage alone would report a single "yes" as a perfect article, so
     * the count is what a screen shows beside it.
     */
    public function helpfulness(): ?float
    {
        $votes = $this->helpful_count + $this->unhelpful_count;

        return $votes === 0 ? null : round($this->helpful_count / $votes * 100, 1);
    }

    public function votes(): int
    {
        return $this->helpful_count + $this->unhelpful_count;
    }

    /**
     * A slug from a title, made unique by counting up rather than by appending
     * a random suffix — a readable URL is most of the point of having one.
     */
    public static function slugFor(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'article';
        }

        $slug = $base;
        $suffix = 1;

        while (self::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ArticleStatus::Published->value);
    }

    /**
     * Everything this person may read.
     *
     * Published for everyone; drafts and archived articles only for somebody
     * who may write them. An agent looking something up should not find an
     * unfinished answer and repeat it to a customer.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopeReadableBy(Builder $query, ?User $user): Builder
    {
        if ($user !== null && $user->can('knowledge.update')) {
            return $query;
        }

        return $query->published();
    }
}
