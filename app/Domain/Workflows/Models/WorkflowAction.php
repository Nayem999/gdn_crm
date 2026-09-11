<?php

namespace App\Domain\Workflows\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Workflows\Enums\WorkflowActionType;
use Database\Factories\WorkflowActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a workflow.
 *
 * A row rather than an entry in a JSON array on the workflow, because 5.4 logs
 * a result per step and 5.8 retries an individual one — both need something
 * stable to point at, and an index into an array moves the moment somebody
 * reorders the list.
 *
 * @property int $id
 * @property int $workflow_id
 * @property string $type
 * @property array<string, mixed> $config
 * @property int $position
 * @property bool $is_active
 * @property bool $stop_on_failure
 */
class WorkflowAction extends Model
{
    /** @use HasFactory<WorkflowActionFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'type',
        'config',
        'position',
        'is_active',
        'stop_on_failure',
    ];

    /**
     * `type()` and `config()` are both named after their column. See
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => WorkflowActionType::UpdateField->value,
        'config' => '{}',
        'position' => 0,
        'is_active' => true,
        'stop_on_failure' => true,
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'integer',
            'is_active' => 'boolean',
            'stop_on_failure' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['workflow_id', 'type', 'config', 'position', 'is_active'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Workflow step';
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function type(): WorkflowActionType
    {
        return WorkflowActionType::tryFrom((string) $this->getAttributeValue('type'))
            ?? WorkflowActionType::UpdateField;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $stored = $this->getAttributeValue('config');

        return is_array($stored) ? $stored : [];
    }

    /**
     * One config value, or null when it was never set.
     */
    public function setting(string $key): mixed
    {
        return $this->config()[$key] ?? null;
    }

    /**
     * Whether this step carries everything its type needs.
     *
     * An incomplete step is skipped rather than attempted: a "send an email"
     * with no template would otherwise fail once per firing, forever, and bury
     * the real failures in the log.
     */
    public function isComplete(): bool
    {
        foreach ($this->type()->requiredKeys() as $key) {
            $value = $this->setting($key);

            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }
}
