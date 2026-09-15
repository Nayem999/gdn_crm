<?php

namespace App\Domain\Meta\Models;

use Database\Factories\MetaFormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lead form as Meta has it, cached here.
 *
 * Kept for three things a form id alone cannot do: name the form in a lead's
 * attribution panel, give the backfill something to walk, and show what the
 * form actually asks — which is the first thing anybody looks at when leads
 * start arriving with half their fields empty, because a form edited at Meta's
 * end changes its question names without telling anybody.
 *
 * No `RecordsActivity`: this is a copy of somebody else's configuration, and an
 * audit entry every time a sync re-reads it would be noise in a trail that
 * exists to record what people here did.
 *
 * @property int $id
 * @property string $form_id
 * @property string|null $page_id
 * @property string $name
 * @property string|null $status
 * @property array<int, mixed>|null $questions Meta's own definitions, as they arrived.
 * @property Carbon|null $last_lead_at
 * @property Carbon|null $last_synced_at
 */
class MetaForm extends Model
{
    /** @use HasFactory<MetaFormFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'form_id',
        'page_id',
        'name',
        'status',
        'questions',
        'last_lead_at',
        'last_synced_at',
    ];

    /**
     * `status()` shares its name with the column, so the key has to be present
     * on every instance however the row was made — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'last_lead_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The page this form lives on, when its row has been synced.
     *
     * Joined on Meta's own id rather than a foreign key, because a lead can
     * arrive for a page nobody has synced yet and the form still has to be
     * recorded.
     *
     * @return BelongsTo<MetaPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(MetaPage::class, 'page_id', 'page_id');
    }

    /**
     * @return HasMany<MetaLead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(MetaLead::class);
    }

    public function status(): ?string
    {
        $value = $this->getAttributeValue('status');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The names Meta will use for this form's answers, which is what a mapping
     * has to be written against.
     *
     * @return array<int, string>
     */
    public function questionNames(): array
    {
        $names = [];

        foreach ($this->questions ?? [] as $question) {
            $name = is_array($question) ? ($question['key'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
