<?php

namespace App\Domain\Shared\Duplicates;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What one module contributes to duplicate detection and merging.
 *
 * Everything the engine is allowed to read or write is declared here, so the
 * merge screen can be one shared component without ever taking a table, column
 * or class name from the request.
 */
interface DuplicateSource
{
    /**
     * The key this module is addressed by in a URL, matched against
     * DuplicateRegistry before anything else happens.
     */
    public function key(): string;

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * The fields duplicates are matched on.
     *
     * @return array<int, MatchRule>
     */
    public function rules(): array;

    /**
     * The fields an operator can choose between when merging, in display order.
     *
     * A field absent from here cannot be written by a merge however the form is
     * tampered with — it is the same registry idea as PermissionCatalogue.
     *
     * @return array<string, string> Column => label.
     */
    public function mergeableFields(): array;

    /**
     * Rows elsewhere that point at a record of this type and must be moved to
     * the survivor.
     *
     * `where` narrows the move, and a polymorphic table needs it: notes are
     * addressed by a type *and* an id, so moving on `notable_id` alone would
     * drag a contact's notes onto an account that happens to share its id.
     *
     * @return array<int, array{table: string, column: string, where?: array<string, string>}>
     */
    public function inboundRelations(): array;

    /**
     * Records this user may see. Re-applied for every read: a duplicate hint
     * must never reveal a record outside the viewer's access level.
     *
     * @return Builder<covariant Model>
     */
    public function visibleQuery(User $user): Builder;

    /**
     * A human name for one record, for the merge screen and the audit entry.
     */
    public function label(Model $record): string;

    public function showRoute(Model $record): string;

    /**
     * One field's value as a person should read it: an owner's name rather than
     * an id, a status label rather than its stored value.
     *
     * The merge screen compares values side by side, and comparing raw foreign
     * keys is not something anybody can do.
     */
    public function displayValue(Model $record, string $field): string;

    /**
     * Anything the module must put right once the survivor has the chosen
     * values and the inbound rows have moved — a hierarchy that would now loop,
     * a flag that lives in an action rather than a column.
     */
    public function afterMerge(Model $survivor, Model $loser): void;
}
