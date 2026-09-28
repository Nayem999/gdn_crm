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
    /**
     * The lead's own columns an update carries forward when the request does
     * not mention them.
     */
    private const DETAIL_FIELDS = [
        'first_name', 'last_name', 'job_title', 'company_name', 'email', 'phone', 'mobile', 'website',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
        'estimated_value', 'source', 'description',
    ];

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
        return Lead::query()->visibleTo($user)->with(['assignees.user:id,name', 'leadOwner:id,name']);
    }

    public function toArray(Model $record): array
    {
        /** @var Lead $record */
        return [
            'id' => $record->id,
            'first_name' => $record->first_name,
            'last_name' => $record->last_name,
            'job_title' => $record->job_title,
            'company_name' => $record->company_name,
            'email' => $record->email,
            'phone' => $record->phone,
            'mobile' => $record->mobile,
            'website' => $record->website,
            'address_line_1' => $record->address_line_1,
            'address_line_2' => $record->address_line_2,
            'city' => $record->city,
            'state' => $record->state,
            'postal_code' => $record->postal_code,
            'country' => $record->country,
            'estimated_value' => $record->estimated_value,
            'description' => $record->description,
            'status' => $record->status()->value,
            'source' => $record->source()?->value,
            'score' => $record->score,
            'assignees' => $record->assignees->map(fn (LeadAssignee $assignee): array => [
                'user_id' => $assignee->user_id,
                'name' => $assignee->user->name,
                'priority' => $assignee->priority,
            ])->all(),
            'lead_owner_id' => $record->lead_owner_id,
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
            'job_title' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            // Fits DECIMAL(15,2), so MySQL cannot silently truncate it.
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'source' => ['nullable', Rule::in(array_keys(LeadSource::options()))],
            'description' => ['nullable', 'string', 'max:2000'],
            'lead_owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
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
            // Every detail column the lead has, not only the ones this
            // endpoint used to accept: LeadData writes each of them, so any
            // left out here was wiped by a PATCH that did not mention it.
            ...$record->only(self::DETAIL_FIELDS),
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
            'job_title' => 'string',
            'company_name' => 'string',
            'email' => 'string',
            'phone' => 'string',
            'mobile' => 'string',
            'website' => 'string',
            'address_line_1' => 'string',
            'address_line_2' => 'string',
            'city' => 'string',
            'state' => 'string',
            'postal_code' => 'string',
            'country' => 'string',
            'estimated_value' => 'string',
            'description' => 'string',
            'status' => 'string',
            'source' => 'string',
            'score' => 'integer',
            'assignees' => 'array',
            'lead_owner_id' => 'integer',
            'created_at' => 'date-time',
            'updated_at' => 'date-time',
        ];
    }
}
