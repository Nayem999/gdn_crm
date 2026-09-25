<?php

namespace App\Domain\Campaigns\Concerns;

use App\Domain\Campaigns\Models\Campaign;
use Illuminate\Database\Eloquent\Model;

/**
 * The campaign picker on a lead, contact or deal form.
 *
 * `exists:campaigns,id` proves a campaign is real, not that it belongs to this
 * workspace or that this person may see it — so the save re-checks through the
 * scoped model. The record's current campaign is always allowed, so an edit to
 * some other field never fails over an attribution somebody else made.
 */
trait WithCampaignAttribution
{
    public ?string $campaign_id = null;

    /**
     * The record being edited, or null while creating.
     */
    abstract protected function attributedRecord(): ?Model;

    /**
     * @return array<int, string>
     */
    public function campaignOptions(): array
    {
        $options = Campaign::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all();
        $current = $this->currentCampaignId();

        if ($current !== null && ! array_key_exists($current, $options)) {
            $name = Campaign::query()->whereKey($current)->value('name');

            if ($name !== null) {
                $options[$current] = $name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function campaignRules(): array
    {
        return ['campaign_id' => ['nullable', 'integer', 'exists:campaigns,id']];
    }

    /**
     * Adds the error and returns false when the chosen campaign is not one this
     * person may attach records to.
     */
    protected function guardCampaign(): bool
    {
        if ($this->chosenCampaignId() === null || $this->chosenCampaignId() === $this->currentCampaignId()) {
            return true;
        }

        if (Campaign::query()->visibleTo(auth()->user())->whereKey($this->chosenCampaignId())->exists()) {
            return true;
        }

        $this->addError('campaign_id', 'That campaign is not available to you.');

        return false;
    }

    protected function chosenCampaignId(): ?int
    {
        return $this->campaign_id === null || $this->campaign_id === '' ? null : (int) $this->campaign_id;
    }

    protected function fillCampaignFrom(Model $record): void
    {
        $id = $record->getAttribute('campaign_id');
        $this->campaign_id = $id === null ? null : (string) $id;
    }

    private function currentCampaignId(): ?int
    {
        $id = $this->attributedRecord()?->getAttribute('campaign_id');

        return $id === null ? null : (int) $id;
    }
}
