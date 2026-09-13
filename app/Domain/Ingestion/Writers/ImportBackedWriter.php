<?php

namespace App\Domain\Ingestion\Writers;

use App\Domain\Shared\Imports\ImportField;
use App\Domain\Shared\Imports\ImportSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an ingested record into a module, reusing what importing already
 * declares.
 *
 * The gateway and a CSV import are the same problem wearing different clothes:
 * an outside set of values arriving at a module that has already said which
 * fields may be written, what they must look like, and which action creates
 * one. `ImportSource` is that declaration, so ingestion reads it rather than
 * keeping a second list — the second list is always the one that drifts.
 *
 * What importing has no answer for is **update**, because a file only ever
 * creates. That is supplied here, per module, by the same DTO and action the
 * edit screen uses — so an ingested change obeys every rule a typed-in one
 * does. The `ImportSource` contract itself is left alone: changing a Phase 2
 * interface to suit Phase 8 would be Phase 8 reaching backwards.
 */
class ImportBackedWriter
{
    /**
     * @param  class-string<Model>  $modelClass  what this module writes
     * @param  class-string  $dataClass  the module's DTO, with fromArray()
     * @param  class-string  $updateAction  the module's own update action
     */
    public function __construct(
        private readonly ImportSource $source,
        private readonly string $modelClass,
        private readonly string $dataClass,
        private readonly string $updateAction,
    ) {}

    public function key(): string
    {
        return $this->source->key();
    }

    public function label(): string
    {
        return $this->source->label();
    }

    /**
     * The fields a mapping may target, keyed by field key.
     *
     * @return array<string, ImportField>
     */
    public function fields(): array
    {
        return $this->source->fields();
    }

    /**
     * @param  array<int, string>  $mappedFields
     * @return array<string, array<int, string>>
     */
    public function rulesFor(array $mappedFields): array
    {
        return $this->source->rulesFor($mappedFields);
    }

    /**
     * @param  array<string, string|null>  $row
     */
    public function create(array $row, User $actor): Model
    {
        // Through the module's own action, so an ingested record gets its
        // owner, its duplicate fingerprint and its status the same way a
        // typed-in one does.
        return $this->source->create($row, $actor);
    }

    /**
     * @param  array<string, string|null>  $row
     */
    public function update(Model $record, array $row, User $actor): Model
    {
        $data = ($this->dataClass)::fromArray($this->merge($record, $row));

        return app($this->updateAction)($record, $data);
    }

    /**
     * The record's current values with the delivery's laid over them.
     *
     * Load-bearing. A DTO's `fromArray()` fills every absent key with null, so
     * handing it the mapped row alone would **blank every field this delivery
     * did not carry** — a source mapping only an email would wipe the name,
     * the company and the address off a record on its first update.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function merge(Model $record, array $row): array
    {
        $declared = array_keys($this->fields());
        $current = [];

        foreach ($declared as $field) {
            $current[$field] = $record->getAttribute($field);
        }

        return [...$current, ...$row];
    }

    /**
     * Custom field values, when the record's module has any.
     *
     * Guarded rather than assumed: `saveCustomFields` comes from a trait, and a
     * module that does not use it would otherwise fail at the last step of an
     * otherwise complete delivery.
     *
     * @param  array<string, string|null>  $values
     */
    public function writeCustomFields(Model $record, array $values): void
    {
        if ($values === [] || ! method_exists($record, 'saveCustomFields')) {
            return;
        }

        $record->saveCustomFields($values);
    }

    /**
     * Somewhere to look for a record this delivery might already have made.
     *
     * Deliberately **not** scoped to what anybody can see. The gateway is
     * installation configuration rather than a person's view, and deduping
     * against only the visible subset would create a second copy of a record
     * that exists but happens to belong to somebody else — which is the exact
     * failure dedupe is there to prevent.
     *
     * @return Builder<Model>
     */
    public function matchQuery(): Builder
    {
        return ($this->modelClass)::query();
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return $this->modelClass;
    }
}
