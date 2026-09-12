<?php

namespace App\Domain\Mail\Models;

use App\Domain\Mail\Templates\MergeFields;
use App\Domain\Notifications\TemplateRenderer;
use App\Models\User;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable subject and body, written against one module's merge fields.
 *
 * @property int $id
 * @property string $name
 * @property string $module
 * @property string $subject
 * @property string $body
 * @property bool $is_active
 * @property bool $track_opens
 * @property bool $track_clicks
 * @property int|null $created_by
 */
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'module',
        'subject',
        'body',
        'is_active',
        'track_opens',
        'track_clicks',
        'created_by',
    ];

    /**
     * `module()` and `subject()` would both collide with a column, so the
     * defaults are declared — see .ai/rules/models-name-collisions.md. Nothing
     * here reads them as methods today; the defaults are what stops that
     * becoming a bug the day something does.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'module' => 'contact',
        'is_active' => true,
        'track_opens' => false,
        'track_clicks' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function moduleLabel(): string
    {
        return MergeFields::modules()[$this->getAttributeValue('module')] ?? $this->getAttributeValue('module');
    }

    /**
     * Tokens this template uses that its module does not offer.
     *
     * @return array<int, string>
     */
    public function unknownFields(): array
    {
        $available = MergeFields::for($this->getAttributeValue('module'));
        $renderer = app(TemplateRenderer::class);

        return array_values(array_diff(
            [...$renderer->fieldsUsed($this->getAttributeValue('subject')), ...$renderer->fieldsUsed($this->getAttributeValue('body'))],
            array_keys($available)
        ));
    }
}
