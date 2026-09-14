<?php

namespace App\Domain\Campaigns\Actions;

use App\Domain\Campaigns\DTOs\CampaignData;
use App\Domain\Campaigns\Models\Campaign;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates or updates one campaign.
 *
 * Two rules it enforces that a form alone cannot. A campaign that ends before it
 * starts is refused outright: every figure this module produces — cost per lead
 * over a period, spend against a date range, the forecast — divides by a window,
 * and a negative one is not a mistake anybody notices in the number it produces.
 * And the code, if there is one, is unique: it is what a Meta campaign, a brief
 * and an invoice all refer to, and two campaigns answering to the same code is
 * how attribution stops adding up.
 */
class SaveCampaignAction
{
    /**
     * @throws RuntimeException when the dates are back to front or the code is taken
     */
    public function __invoke(CampaignData $data, ?Campaign $campaign = null): Campaign
    {
        $this->guardDates($data);
        $this->guardCode($data, $campaign);

        return DB::transaction(function () use ($data, $campaign): Campaign {
            $campaign = $campaign === null
                ? $this->create($data)
                : $this->update($campaign, $data);

            return $campaign->fresh() ?? $campaign;
        });
    }

    private function create(CampaignData $data): Campaign
    {
        $campaign = new Campaign;

        $campaign->forceFill([
            ...$data->toAttributes(),
            'owner_id' => $data->ownerId ?? auth()->id(),
        ])->save();

        return $campaign;
    }

    private function update(Campaign $campaign, CampaignData $data): Campaign
    {
        $attributes = $data->toAttributes();

        // The owner only moves when one was chosen, so an edit that leaves the
        // field alone cannot silently unassign the campaign.
        if ($data->ownerId !== null) {
            $attributes['owner_id'] = $data->ownerId;
        }

        $campaign->forceFill($attributes)->save();

        return $campaign;
    }

    private function guardDates(CampaignData $data): void
    {
        if ($data->startDate === null || $data->endDate === null) {
            return;
        }

        if (strtotime($data->endDate) < strtotime($data->startDate)) {
            throw new RuntimeException('The end date cannot be before the start date.');
        }
    }

    private function guardCode(CampaignData $data, ?Campaign $campaign): void
    {
        if ($data->code === null) {
            return;
        }

        $taken = Campaign::query()
            ->where('code', $data->code)
            // withTrashed, because the unique index covers removed rows too: a
            // code freed by a soft delete is not actually free, and finding that
            // out from a database error is worse than being told here.
            ->withTrashed()
            ->when($campaign !== null, fn ($query) => $query->whereKeyNot($campaign?->getKey()))
            ->exists();

        if ($taken) {
            throw new RuntimeException('Another campaign already uses the code '.$data->code.'.');
        }
    }
}
