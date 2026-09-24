<?php

namespace App\Domain\Api\Modules;

use App\Domain\Api\Contracts\ApiModule;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class LeadApi implements ApiModule
{
    public function __construct(
        private readonly CreateLeadAction $creator,
        private readonly UpdateLeadAction $updater,
        private readonly DeleteLeadAction $deleter,
    ) {}

    public function key(): string
    {
        return 'leads';
    }

    public function modelClass(): string
    {
        return Lead::class;
    }

    public function query(User $user): Builder
    {
        return Lead::query()->visibleTo($user)->with('assignees.user:id,name');
    }

    public function toArray(Model $record): array
    {
        /** @var Lead $record */
        return [
            'id' => $record->id,
            'first_name' => $record->first_name,
            'last_name' => $record->last_name,
            'company_name' => $record->company_name,
            'email' => $record->email,
            'phone' => $record->phone,
            'status' => $record->status()->value,
            'source' => $record->source()?->value,
            'score' => $record->score,
            'assignees' => $record->assignees->map(fn (LeadAssignee $assignee): array => [
                'user_id' => $assignee->user_id,
                'name' => $assignee->user->name,
                'priority' => $assignee->priority,
            ])->all(),
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    public function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'source' => ['nullable', Rule::in(array_keys(LeadSource::options()))],
            'description' => ['nullable', 'string'],
            // Deliberately no owner_id/assignees field: several people can be
            // assigned to a lead at once, with an optional priority between
            // them, and that does not fit this endpoint's flat validation-rule
            // shape. Managing who is assigned still goes through the app.
        ];
    }

    public function create(array $validated, User $actor): Model
    {
        return $this->creator->__invoke(LeadData::fromArray($validated), $actor);
    }

    public function update(Model $record, array $validated): Model
    {
        /** @var Lead $record */
        return $this->updater->__invoke($record, LeadData::fromArray([
            ...$record->only(['first_name', 'last_name', 'company_name', 'email', 'phone', 'source', 'description']),
            ...$validated,
        ]));
    }

    public function delete(Model $record): void
    {
        /** @var Lead $record */
        $this->deleter->__invoke($record);
    }

    /**
     * @return array<string, string>
     */
    public function schema(): array
    {
        return [
            'id' => 'integer',
            'first_name' => 'string',
            'last_name' => 'string',
            'company_name' => 'string',
            'email' => 'string',
            'phone' => 'string',
            'status' => 'string',
            'source' => 'string',
            'score' => 'integer',
            'assignees' => 'array',
            'created_at' => 'date-time',
            'updated_at' => 'date-time',
        ];
    }
}
