<?php

namespace App\Domain\Api\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One module, as the REST API sees it.
 *
 * The controller is generic on purpose. Every module already has a policy, an
 * access-level scope, a DTO and a set of actions that enforce its rules; an API
 * that reimplemented any of that would be a second set of rules to keep in step
 * with the first, and the one that drifts is always the one nobody looks at.
 * So a module here is a thin adapter onto what already exists.
 */
interface ApiModule
{
    /**
     * The path segment: /api/v1/{key}.
     */
    public function key(): string;

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * The records this user may see, already scoped by their access level.
     *
     * @return Builder<covariant Model>
     */
    public function query(User $user): Builder;

    /**
     * What one record looks like in a response.
     *
     * Declared field by field rather than serialising the model: a model
     * carries everything on its table, and an API that returns "whatever the
     * columns are" leaks the next column somebody adds.
     *
     * @return array<string, mixed>
     */
    public function toArray(Model $record): array;

    /**
     * @return array<string, mixed> Validation rules; $creating relaxes the
     *                              required fields for a partial update.
     */
    public function rules(bool $creating): array;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, User $actor): Model;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Model $record, array $validated): Model;

    public function delete(Model $record): void;
}
