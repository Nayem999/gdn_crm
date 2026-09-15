<?php

namespace App\Domain\Social\Models;

use App\Domain\Social\Enums\TemplateStatus;
use Database\Factories\WhatsAppTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message Meta has approved this business to send.
 *
 * Read-only from this application's point of view. Templates are authored and
 * submitted in Meta's Business Manager, and nothing here edits one — a screen
 * that offered to would be offering something it cannot honour, since the text
 * that matters is the text Meta approved.
 *
 * @property int $id
 * @property string|null $waba_id
 * @property string $name
 * @property string $language
 * @property string|null $category
 * @property string $status
 * @property string|null $body
 * @property string|null $header
 * @property string|null $footer
 * @property array<int, string>|null $variables
 * @property string|null $rejection_reason
 * @property Carbon|null $synced_at
 */
class WhatsAppTemplate extends Model
{
    /** @use HasFactory<WhatsAppTemplateFactory> */
    use HasFactory;

    /**
     * Named explicitly, as every WhatsApp model in this application has to be:
     * Laravel derives `whats_app_templates` from the class, splitting on the
     * capital in "App". The tables are `whatsapp_*`, which is how Meta spells
     * it and how the rest of the schema reads.
     */
    protected $table = 'whatsapp_templates';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'waba_id',
        'name',
        'language',
        'category',
        'status',
        'body',
        'header',
        'footer',
        'variables',
        'rejection_reason',
        'synced_at',
    ];

    /**
     * `status()` shares its name with the column — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TemplateStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function status(): TemplateStatus
    {
        return TemplateStatus::tryFrom((string) $this->getAttributeValue('status')) ?? TemplateStatus::Pending;
    }

    public function isSendable(): bool
    {
        return $this->status()->isSendable();
    }

    /**
     * How many values this template needs supplying at send time.
     */
    public function variableCount(): int
    {
        return count($this->variables ?? []);
    }

    /**
     * The body with values put in, for showing somebody what will be sent.
     *
     * **Preview only.** What actually goes to Meta is the template name and the
     * values as separate parameters — Meta does the substitution at its end,
     * against the text it approved. Sending this rendered string instead would
     * be sending a free-form message, which is precisely what the window
     * forbids.
     *
     * @param  array<int, string>  $values
     */
    public function preview(array $values = []): string
    {
        $body = (string) $this->body;

        foreach (array_values($values) as $index => $value) {
            $body = str_replace('{{'.($index + 1).'}}', $value, $body);
        }

        return $body;
    }

    /**
     * What is missing or extra, before anything is sent.
     *
     * Checked here rather than left to Meta because Meta's refusal for a wrong
     * parameter count is generic, and an agent watching a message fail with
     * "132000" has nothing to go on.
     *
     * @param  array<int, string>  $values
     */
    public function validationError(array $values): ?string
    {
        $expected = $this->variableCount();
        $given = count(array_filter($values, fn (string $value): bool => trim($value) !== ''));

        if ($given === $expected) {
            return null;
        }

        return sprintf(
            '"%s" needs %d %s; %d %s given.',
            $this->name,
            $expected,
            $expected === 1 ? 'value' : 'values',
            $given,
            $given === 1 ? 'was' : 'were',
        );
    }

    public function displayName(): string
    {
        return $this->name.' ('.$this->language.')';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), TemplateStatus::Approved->value);
    }
}
