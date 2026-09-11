<?php

namespace App\Domain\Leads\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Leads\Capture\CaptureField;
use App\Domain\Leads\Enums\LeadSource;
use App\Models\User;
use Database\Factories\LeadCaptureFormFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A public form that captures leads.
 *
 * @property int $id
 * @property string $token
 * @property string $name
 * @property string|null $description
 * @property array<int, mixed> $fields
 * @property int $owner_id
 * @property string|null $source
 * @property string $submit_label
 * @property string|null $success_message
 * @property string|null $redirect_url
 * @property bool $is_active
 * @property int $submission_count
 * @property Carbon|null $last_submitted_at
 */
class LeadCaptureForm extends Model
{
    /** @use HasFactory<LeadCaptureFormFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The honeypot's name on the rendered page.
     *
     * Something a bot would plausibly want to fill in. Deliberately not
     * "honeypot": the whole trick is that it reads like a real field.
     */
    public const HONEYPOT = 'company_website_url';

    /**
     * The signed timestamp field, and how quickly a submission is treated as
     * automated.
     *
     * Three seconds is below what a person needs to read a form and type a
     * name into it, and far above what a script takes. It is a *second* signal,
     * not the only one — a slow bot still meets the honeypot and the rate
     * limit.
     */
    public const TIMESTAMP = 'rendered_at';

    public const MINIMUM_SECONDS = 3;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'token', 'name', 'description', 'fields', 'owner_id', 'source',
        'submit_label', 'success_message', 'redirect_url', 'is_active',
    ];

    /**
     * `fields` shares its name with no method here, but `source()` does share
     * one with its column — so it gets a default, per
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => null,
        'submit_label' => 'Send',
        'is_active' => true,
        'submission_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_active' => 'boolean',
            'submission_count' => 'integer',
            'last_submitted_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        // Not `token`: it is the form's public address, and an audit entry is
        // read by more people than should be able to reconstruct it.
        return ['name', 'owner_id', 'source', 'is_active'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Lead capture form';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * What a lead captured by this form is recorded as coming from.
     *
     * Falls back to the web form rather than to nothing: a capture form is a
     * website, and a lead with no source is invisible to the one report anybody
     * runs about this feature. The column stays nullable because "not chosen"
     * and "chosen as web form" are different things to the builder — they are
     * only the same thing to a lead.
     */
    public function source(): LeadSource
    {
        return LeadSource::tryFrom((string) $this->getAttributeValue('source'))
            ?? LeadSource::WebForm;
    }

    /**
     * The fields this form shows, in order.
     *
     * @return array<int, CaptureField>
     */
    public function captureFields(): array
    {
        $fields = [];

        foreach ($this->getAttributeValue('fields') ?? [] as $stored) {
            $field = CaptureField::fromStored($stored);

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    public function publicUrl(): string
    {
        return route('lead-capture.show', $this->token);
    }

    /**
     * The snippet somebody pastes into their own site.
     *
     * An iframe rather than a script: a script tag would run our code on
     * somebody else's page, and an embedded form that can read its host page is
     * a far bigger promise than this needs to make.
     */
    public function embedSnippet(): string
    {
        return '<iframe src="'.e($this->publicUrl()).'" width="100%" height="620" '
            .'style="border:0" title="'.e($this->name).'" loading="lazy"></iframe>';
    }

    /**
     * A token nothing can guess, and nothing derives from the name.
     */
    public static function newToken(): string
    {
        return Str::lower(Str::random(32));
    }

    /**
     * @param  Builder<LeadCaptureForm>  $query
     * @return Builder<LeadCaptureForm>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }
}
