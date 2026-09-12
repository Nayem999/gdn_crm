<?php

namespace App\Domain\Api\Modules;

use App\Domain\Api\Contracts\ApiModule;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
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
        return Lead::query()->visibleTo($user);
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
            'owner_id' => $record->owner_id,
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
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string'],
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
            ...$record->only(['first_name', 'last_name', 'company_name', 'email', 'phone', 'source', 'owner_id', 'description']),
            ...$validated,
        ]));
    }

    public function delete(Model $record): void
    {
        /** @var Lead $record */
        $this->deleter->__invoke($record);
    }
}
