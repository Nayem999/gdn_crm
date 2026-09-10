<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\DTOs\DealData;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Models\User;
use RuntimeException;

class CreateDealAction
{
    /**
     * @throws RuntimeException when a chosen relation is not a real record
     */
    public function __invoke(DealData $data, User $actor): Deal
    {
        $attributes = $data->toAttributes();

        // A deal always has an owner; unassigned records are how visibility
        // scoping springs a leak.
        $attributes['owner_id'] = $data->ownerId ?? $actor->id;

        $pipeline = $this->pipeline($data);
        $attributes['pipeline_id'] = $pipeline?->getKey();

        // The first stage of the pipeline, not "new": a pipeline that starts at
        // "Scoping" should put its deals there.
        $attributes['stage'] = $this->openingStage($pipeline);

        $this->guardRelations($data);

        // forceFill, not create(): `stage` is deliberately out of $fillable so
        // no form can write it, and create() would silently drop the opening
        // stage computed above. The attribute set here is DealData's own fixed
        // list plus values this action decided, so nothing from a request
        // reaches a column it should not.
        $deal = new Deal;
        $deal->forceFill($attributes)->save();

        return $deal->refresh();
    }

    private function pipeline(DealData $data): ?Pipeline
    {
        if ($data->pipelineId === null) {
            return Pipeline::default();
        }

        $pipeline = Pipeline::query()->whereKey($data->pipelineId)->first();

        if ($pipeline === null) {
            throw new RuntimeException('That pipeline does not exist.');
        }

        return $pipeline;
    }

    /**
     * Where a new deal starts — the pipeline decides, so conversion and this
     * action cannot disagree about it.
     */
    private function openingStage(?Pipeline $pipeline): string
    {
        if ($pipeline === null) {
            return DealStage::New->value;
        }

        $stage = $pipeline->openingStage();

        if ($stage === null) {
            throw new RuntimeException('The '.$pipeline->name.' pipeline has no stages, so a deal has nowhere to start.');
        }

        return $stage->key;
    }

    private function guardRelations(DealData $data): void
    {
        if (! Account::query()->whereKey($data->accountId)->exists()) {
            throw new RuntimeException('That account does not exist.');
        }

        if ($data->contactId === null) {
            return;
        }

        $contact = Contact::query()->whereKey($data->contactId)->first();

        if ($contact === null) {
            throw new RuntimeException('That contact does not exist.');
        }

        // A deal is for an organisation and the contact is who to talk to
        // there, so a contact from a different account is a mistake worth
        // catching rather than storing.
        if ($contact->account_id !== null && $contact->account_id !== $data->accountId) {
            throw new RuntimeException($contact->fullName().' does not work at that account.');
        }
    }
}
