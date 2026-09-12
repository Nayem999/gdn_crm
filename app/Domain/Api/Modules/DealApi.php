<?php

namespace App\Domain\Api\Modules;

use App\Domain\Api\Contracts\ApiModule;
use App\Domain\Deals\Actions\CreateDealAction;
use App\Domain\Deals\Actions\DeleteDealAction;
use App\Domain\Deals\Actions\UpdateDealAction;
use App\Domain\Deals\DTOs\DealData;
use App\Domain\Deals\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DealApi implements ApiModule
{
    public function __construct(
        private readonly CreateDealAction $creator,
        private readonly UpdateDealAction $updater,
        private readonly DeleteDealAction $deleter,
    ) {}

    public function key(): string
    {
        return 'deals';
    }

    public function modelClass(): string
    {
        return Deal::class;
    }

    public function query(User $user): Builder
    {
        return Deal::query()->visibleTo($user)->with('account');
    }

    public function toArray(Model $record): array
    {
        /** @var Deal $record */
        return [
            'id' => $record->id,
            'name' => $record->name,
            'value' => $record->getAttributeValue('value'),
            'expected_close_date' => $record->expected_close_date?->toDateString(),
            'account' => $record->account === null ? null : ['id' => $record->account->id, 'name' => $record->account->name],
            'owner_id' => $record->owner_id,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    public function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'account_id' => [$required, 'integer', 'exists:accounts,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function create(array $validated, User $actor): Model
    {
        return $this->creator->__invoke(DealData::fromArray($validated), $actor);
    }

    public function update(Model $record, array $validated): Model
    {
        /** @var Deal $record */
        return $this->updater->__invoke($record, DealData::fromArray([
            ...$record->only(['name', 'account_id', 'contact_id', 'pipeline_id', 'value', 'expected_close_date', 'owner_id', 'description']),
            ...$validated,
        ]));
    }

    public function delete(Model $record): void
    {
        /** @var Deal $record */
        $this->deleter->__invoke($record);
    }
}
