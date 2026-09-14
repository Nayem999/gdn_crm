<?php

namespace App\Livewire\Campaigns;

use App\Domain\Campaigns\Actions\SaveCampaignAction;
use App\Domain\Campaigns\DTOs\CampaignData;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\CampaignType;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Adds or edits one campaign.
 *
 * The spend field is editable here on purpose. Meta's figures are written into
 * it by the synchronisation in 12.7, but a campaign that is half Facebook and
 * half an exhibition stand has a cost only a person knows, and a field that
 * could only be written by an integration would make the module useless to
 * everybody not running ads.
 */
#[Title('Campaign')]
class CampaignForm extends Component
{
    use AuthorizesRequests;
    use WithCustomFieldForm;

    public ?int $campaignId = null;

    public string $name = '';

    public string $type = 'other';

    public string $status = 'planned';

    public string $code = '';

    public string $description = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $budget = '';

    public string $actualCost = '';

    public string $expectedRevenue = '';

    public string $ownerId = '';

    public ?string $error = null;

    public function mount(?Campaign $campaign = null): void
    {
        if ($campaign?->exists) {
            $this->authorize('update', $campaign);
            $this->fillFrom($campaign);
            $this->loadCustomFields($campaign);

            return;
        }

        $this->authorize('create', Campaign::class);

        $this->ownerId = (string) auth()->id();
        $this->loadCustomFields();
    }

    // -- Options ---------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        return CampaignType::options();
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return CampaignStatus::options();
    }

    /**
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    // -- Saving ----------------------------------------------------------------

    public function save(): void
    {
        $campaign = $this->campaignId === null
            ? null
            : Campaign::query()->visibleTo(auth()->user())->whereKey($this->campaignId)->first();

        if ($this->campaignId !== null && $campaign === null) {
            abort(404);
        }

        $this->authorize($campaign === null ? 'create' : 'update', $campaign ?? Campaign::class);

        $this->validateCustomFields($this->customFieldViewer());

        $this->validate($this->rules(), [], [
            'startDate' => 'start date',
            'endDate' => 'end date',
            'actualCost' => 'amount spent',
            'expectedRevenue' => 'expected revenue',
            'ownerId' => 'owner',
        ]);

        try {
            $saved = app(SaveCampaignAction::class)($this->definition(), $campaign);
        } catch (RuntimeException $refused) {
            // A code somebody else has, or dates the wrong way round. Reported
            // on the screen rather than thrown: it is a thing somebody did, not
            // a fault.
            $this->error = $refused->getMessage();

            return;
        }

        $saved->saveCustomFields($this->customFields);

        $this->redirectRoute('campaigns.show', $saved, navigate: true);
    }

    private function definition(): CampaignData
    {
        return CampaignData::fromArray([
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'code' => $this->code,
            'description' => $this->description,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'budget' => $this->budget,
            'actual_cost' => $this->actualCost,
            'expected_revenue' => $this->expectedRevenue,
            'owner_id' => $this->ownerId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'type' => ['required', Rule::in(array_keys(CampaignType::options()))],
            'status' => ['required', Rule::in(array_keys(CampaignStatus::options()))],
            // Unique when given, ignoring this row and counting removed ones —
            // the index does, so finding out from a database error would be
            // worse than being told here.
            'code' => [
                'nullable', 'string', 'max:60',
                Rule::unique('campaigns', 'code')->ignore($this->campaignId),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'startDate' => ['nullable', 'date'],
            // Checked again in the action, which is what a form cannot be
            // trusted for: every figure this module produces divides by the
            // window between these two.
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'actualCost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'expectedRevenue' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'ownerId' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    private function fillFrom(Campaign $campaign): void
    {
        $this->campaignId = $campaign->id;
        $this->name = $campaign->name;
        $this->type = $campaign->type()->value;
        $this->status = $campaign->status()->value;
        $this->code = (string) $campaign->code;
        $this->description = (string) $campaign->description;
        $this->startDate = $campaign->start_date?->format('Y-m-d') ?? '';
        $this->endDate = $campaign->end_date?->format('Y-m-d') ?? '';
        $this->budget = $campaign->budget === null ? '' : (string) $campaign->budget;
        $this->actualCost = $campaign->actual_cost === null ? '' : (string) $campaign->actual_cost;
        $this->expectedRevenue = $campaign->expected_revenue === null ? '' : (string) $campaign->expected_revenue;
        $this->ownerId = (string) $campaign->owner_id;
    }

    public function customFieldModule(): string
    {
        return 'campaigns';
    }

    public function render(): View
    {
        return view('livewire.campaigns.campaign-form');
    }
}
