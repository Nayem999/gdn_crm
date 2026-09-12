<?php

namespace App\Domain\Api\Modules;

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\Actions\DeleteAccountAction;
use App\Domain\Accounts\Actions\UpdateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Api\Contracts\ApiModule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountApi implements ApiModule
{
    public function __construct(
        private readonly CreateAccountAction $creator,
        private readonly UpdateAccountAction $updater,
        private readonly DeleteAccountAction $deleter,
    ) {}

    public function key(): string
    {
        return 'accounts';
    }

    public function modelClass(): string
    {
        return Account::class;
    }

    public function query(User $user): Builder
    {
        return Account::query()->visibleTo($user);
    }

    public function toArray(Model $record): array
    {
        /** @var Account $record */
        return [
            'id' => $record->id,
            'name' => $record->name,
            'industry' => $record->industry,
            'website' => $record->website,
            'email' => $record->email,
            'phone' => $record->phone,
            'city' => $record->city,
            'country' => $record->country,
            'owner_id' => $record->owner_id,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    public function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function create(array $validated, User $actor): Model
    {
        return $this->creator->__invoke(AccountData::fromArray($validated), $actor);
    }

    public function update(Model $record, array $validated): Model
    {
        /** @var Account $record */
        return $this->updater->__invoke($record, AccountData::fromArray([
            ...$record->only(['name', 'industry', 'website', 'email', 'phone', 'city', 'country', 'owner_id', 'description']),
            ...$validated,
        ]));
    }

    public function delete(Model $record): void
    {
        /** @var Account $record */
        $this->deleter->__invoke($record);
    }
}
