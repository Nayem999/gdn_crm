<?php

namespace App\Domain\Shared\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What one module contributes to importing.
 *
 * A module says which fields it accepts and how to create one record; the
 * shared machinery does the reading, mapping, validating and reporting. Records
 * are created through the module's own action, so an imported record obeys
 * every rule a typed-in one does — owners, primary contacts, lead status,
 * duplicate fingerprints.
 */
interface ImportSource
{
    /**
     * The key this module is addressed by in a URL, matched against
     * ImportRegistry before anything else happens.
     */
    public function key(): string;

    public function label(): string;

    /**
     * The fields a file may be mapped onto, in the order they are offered.
     *
     * @return array<string, ImportField> Keyed by field key.
     */
    public function fields(): array;

    /**
     * Create one record from a mapped, validated row.
     *
     * @param  array<string, string|null>  $row  Field key => value.
     */
    public function create(array $row, User $actor): Model;

    /**
     * The permission that gates importing into this module.
     */
    public function permission(): string;

    public function indexRoute(): string;

    /**
     * @return array<int, ImportField>
     */
    public function requiredFields(): array;

    /**
     * Validation rules for the fields a mapping actually covers.
     *
     * @param  array<int, string>  $mappedFields
     * @return array<string, array<int, string>>
     */
    public function rulesFor(array $mappedFields): array;
}
