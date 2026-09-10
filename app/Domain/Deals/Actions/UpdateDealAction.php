<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\DTOs\DealData;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use RuntimeException;

class UpdateDealAction
{
    /**
     * @throws RuntimeException when a chosen relation is not a real record, or
     *                          the pipeline cannot hold the deal where it is
     */
    public function __invoke(Deal $deal, DealData $data): Deal
    {
        $attributes = $data->toAttributes();

        if ($data->ownerId !== null) {
            $attributes['owner_id'] = $data->ownerId;
        } else {
            unset($attributes['owner_id']);
        }

        $this->guardRelations($data);
        $attributes['pipeline_id'] = $this->guardPipeline($deal, $data);

        $deal->fill($attributes)->save();

        return $deal->refresh();
    }

    /**
     * Moving a deal to another pipeline is only safe if the new one has the
     * stage it is sitting in.
     *
     * Refused rather than translated: guessing which stage of the new pipeline
     * corresponds to "Negotiation" is a business decision, and getting it wrong
     * silently reprices the forecast. The deal has to be moved on the board
     * first — MoveDealStageAction is the only writer of stage, and it is not
     * this action.
     */
    private function guardPipeline(Deal $deal, DealData $data): ?int
    {
        if ($data->pipelineId === null || $data->pipelineId === $deal->pipeline_id) {
            return $deal->pipeline_id;
        }

        $pipeline = Pipeline::query()->whereKey($data->pipelineId)->first();

        if ($pipeline === null) {
            throw new RuntimeException('That pipeline does not exist.');
        }

        if ($pipeline->stageByKey((string) $deal->stage) === null) {
            throw new RuntimeException(
                'The '.$pipeline->name.' pipeline has no "'.$deal->stage.'" stage, '
                .'so this deal cannot move to it while it sits there.'
            );
        }

        return $pipeline->getKey();
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

        if ($contact->account_id !== null && $contact->account_id !== $data->accountId) {
            throw new RuntimeException($contact->fullName().' does not work at that account.');
        }
    }
}
