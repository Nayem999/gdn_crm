<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Imports\ImportRegistry;
use App\Domain\Shared\Imports\ImportRowError;
use App\Domain\Shared\Imports\ImportSource;
use App\Domain\Shared\Imports\ImportStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt at importing a file.
 *
 * @property int $id
 * @property string $module
 * @property int $user_id
 * @property string $original_filename
 * @property string $path
 * @property array<int|string, mixed> $mapping
 * @property string $status
 * @property int $total_rows
 * @property int $imported_rows
 * @property int $failed_rows
 * @property array<int, array<string, mixed>>|null $errors
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class ImportRun extends Model
{
    /**
     * How many row errors are kept for reporting. `failed_rows` still carries
     * the true count, and the screen says so when the list is cut short.
     */
    public const MAX_REPORTED_ERRORS = 500;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'module',
        'user_id',
        'original_filename',
        'path',
        'mapping',
        'status',
        'total_rows',
        'imported_rows',
        'failed_rows',
        'errors',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    /**
     * `status` shares its name with the method below, so it needs a default —
     * see .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): ImportStatus
    {
        return ImportStatus::tryFrom((string) $this->getAttributeValue('status')) ?? ImportStatus::Pending;
    }

    public function source(): ?ImportSource
    {
        return ImportRegistry::find($this->module);
    }

    /**
     * The mapping as column index => field key, with anything the module no
     * longer offers dropped.
     *
     * @return array<int, string>
     */
    public function mappedFields(): array
    {
        $fields = $this->source()?->fields() ?? [];
        $mapping = [];

        foreach ((array) $this->mapping as $column => $field) {
            if (is_string($field) && array_key_exists($field, $fields)) {
                $mapping[(int) $column] = $field;
            }
        }

        return $mapping;
    }

    /**
     * @return array<int, ImportRowError>
     */
    public function rowErrors(): array
    {
        return array_map(
            fn (array $error) => ImportRowError::fromArray($error),
            (array) ($this->errors ?? [])
        );
    }

    /**
     * Whether the error list was cut short by the reporting cap.
     */
    public function errorsTruncated(): bool
    {
        return $this->failed_rows > count((array) ($this->errors ?? []));
    }
}
