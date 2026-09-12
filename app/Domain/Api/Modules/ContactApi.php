<?php

namespace App\Domain\Api\Modules;

use App\Domain\Api\Contracts\ApiModule;
use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Actions\DeleteContactAction;
use App\Domain\Contacts\Actions\UpdateContactAction;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContactApi implements ApiModule
{
    public function __construct(
        private readonly CreateContactAction $create,
        private readonly UpdateContactAction $update,
        private readonly DeleteContactAction $delete,
    ) {}

    public function key(): string
    {
        return 'contacts';
    }

    public function modelClass(): string
    {
        return Contact::class;
    }

    public function query(User $user): Builder
    {
        return Contact::query()->visibleTo($user)->with('account');
    }

    public function toArray(Model $record): array
    {
        /** @var Contact $record */
        return [
            'id' => $record->id,
            'first_name' => $record->first_name,
            'last_name' => $record->last_name,
            'job_title' => $record->job_title,
            'email' => $record->email,
            'phone' => $record->phone,
            'mobile' => $record->mobile,
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
            'first_name' => [$required, 'string', 'max:255'],
            'last_name' => [$required, 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function create(array $validated, User $actor): Model
    {
        return $this->create->__invoke(ContactData::fromArray($validated), $actor);
    }

    public function update(Model $record, array $validated): Model
    {
        /** @var Contact $record */
        return $this->update->__invoke($record, ContactData::fromArray([
            ...$record->only(['first_name', 'last_name', 'job_title', 'email', 'phone', 'mobile', 'account_id', 'owner_id', 'description']),
            ...$validated,
        ]));
    }

    public function delete(Model $record): void
    {
        /** @var Contact $record */
        $this->delete->__invoke($record);
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
            'job_title' => 'string',
            'email' => 'string',
            'phone' => 'string',
            'mobile' => 'string',
            'account' => 'object',
            'owner_id' => 'integer',
            'created_at' => 'date-time',
            'updated_at' => 'date-time',
        ];
    }
}
