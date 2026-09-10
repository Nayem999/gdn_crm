<?php

namespace App\Livewire\Deals;

use App\Domain\Deals\Actions\CloseDealAction;
use App\Domain\Deals\Actions\DeleteDealAction;
use App\Domain\Deals\Actions\MoveDealStageAction;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealStageEntry;
use App\Domain\Deals\Models\PipelineStage;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One deal's record, with the moves it can make from where it is.
 */
class DealShow extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $dealId;

    /**
     * The owner picked in the reassign control.
     */
    public ?string $reassignTo = null;

    // -- The closing form ----------------------------------------------------

    public bool $closing = false;

    /**
     * The stage the close is moving to, and why.
     */
    public ?string $closeStage = null;

    public ?string $closeReason = null;

    public string $closeNotes = '';

    public function mount(Deal $deal): void
    {
        $this->authorize('view', $deal);

        $this->dealId = $deal->id;
        $this->reassignTo = (string) $deal->owner_id;
    }

    /**
     * Trashed records are included so a deleted deal stays readable to somebody
     * following a link from a note or an audit entry.
     */
    public function deal(): Deal
    {
        return Deal::withTrashed()
            ->with(['owner', 'account', 'contact', 'lead', 'pipeline.stages'])
            ->findOr($this->dealId, fn () => abort(404));
    }

    /**
     * The stages this deal could move to — its pipeline's, minus where it is.
     *
     * @return Collection<int, PipelineStage>
     */
    public function availableStages(): Collection
    {
        $deal = $this->deal();
        $pipeline = $deal->pipeline;

        if ($pipeline === null) {
            return collect();
        }

        return $pipeline->stages->reject(fn (PipelineStage $stage) => $stage->key === $deal->stage)->values();
    }

    /**
     * Every stage the deal has sat in, oldest first, with how long each took.
     *
     * @return Collection<int, DealStageEntry>
     */
    public function stageHistory(): Collection
    {
        return $this->deal()->stageEntries()->with('movedBy')->get();
    }

    /**
     * Total time in each stage, summed across repeat visits — a deal that went
     * back to Proposal has been there twice.
     *
     * @return array<string, array{key: string, name: string, seconds: int, visits: int}>
     */
    public function timePerStage(): array
    {
        return $this->deal()->timePerStage();
    }

    /**
     * @return Collection<int, PipelineStage>
     */
    public function closingStages(): Collection
    {
        return $this->availableStages()->filter(fn (PipelineStage $stage) => $stage->isClosed())->values();
    }

    // -- Moving --------------------------------------------------------------

    public function moveTo(string $stageKey): void
    {
        $deal = $this->deal();

        $this->authorize('update', $deal);

        $stage = $deal->pipeline?->stageByKey($stageKey);

        // A closing stage wants a reason, so it opens the form rather than
        // moving straight there — the reason is the one thing the win/loss
        // report cannot be given later without somebody remembering.
        if ($stage !== null && $stage->isClosed()) {
            $this->authorize('close', $deal);

            $this->closing = true;
            $this->closeStage = $stageKey;
            $this->closeReason = null;
            $this->closeNotes = '';

            return;
        }

        try {
            app(MoveDealStageAction::class)($deal, $stageKey);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $movedStage = $deal->refresh()->configuredStage();

        $this->dispatch(
            'deal-updated',
            message: 'Moved to '.($movedStage === null ? $stageKey : $movedStage->name).'.',
        );
    }

    public function cancelClosing(): void
    {
        $this->closing = false;
        $this->closeStage = null;
        $this->closeReason = null;
        $this->closeNotes = '';
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $deal = $this->deal();

        $this->authorize('close', $deal);

        $this->validate([
            'closeStage' => ['required', 'string'],
            'closeReason' => ['required', 'string'],
            'closeNotes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'closeStage' => 'stage',
            'closeReason' => 'reason',
            'closeNotes' => 'notes',
        ]);

        $reason = DealCloseReason::tryFrom((string) $this->closeReason);

        if ($reason === null) {
            $this->addError('closeReason', 'Choose a reason from the list.');

            return;
        }

        try {
            app(CloseDealAction::class)($deal, (string) $this->closeStage, $reason, $this->closeNotes);
        } catch (RuntimeException $exception) {
            $this->addError('closeReason', $exception->getMessage());

            return;
        }

        $this->cancelClosing();

        $this->dispatch('deal-updated', message: $deal->refresh()->outcome()->label().', and the reason is recorded.');
    }

    public function reopen(string $stageKey): void
    {
        $deal = $this->deal();

        $this->authorize('close', $deal);

        try {
            app(CloseDealAction::class)->reopen($deal, $stageKey);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->dispatch('deal-updated', message: $deal->refresh()->name.' is open again.');
    }

    /**
     * The reasons that go with the outcome of the stage being moved to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function closeReasonOptions(): array
    {
        $stage = $this->closeStage === null
            ? null
            : $this->deal()->pipeline?->stageByKey($this->closeStage);

        return DealCloseReason::selectOptions($stage?->outcome() ?? StageOutcome::Lost);
    }

    // -- Ownership -----------------------------------------------------------

    public function reassign(): void
    {
        $deal = $this->deal();

        $this->authorize('assign', $deal);

        $owner = User::query()->find((int) $this->reassignTo);

        if ($owner === null) {
            $this->addError('reassignTo', 'Choose somebody to hand this to.');

            return;
        }

        if ($deal->owner_id === $owner->id) {
            $this->dispatch('notify', type: 'error', message: 'That deal already belongs to '.$owner->name.'.');

            return;
        }

        $deal->forceFill(['owner_id' => $owner->id])->save();

        $this->dispatch('deal-updated', message: 'Handed to '.$owner->name.'.');
    }

    /**
     * PHP casts a numeric string array key back to int, so the id keys here are
     * ints however they are written — typed as such rather than pretending.
     *
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        $options = [];

        foreach (User::query()->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name;
        }

        return $options;
    }

    public function delete(): void
    {
        $deal = $this->deal();

        $this->authorize('delete', $deal);

        app(DeleteDealAction::class)($deal);

        session()->flash('status', $deal->name.' was removed.');

        $this->redirectRoute('deals.index', navigate: true);
    }

    public function render(): View
    {
        $deal = $this->deal();

        return view('livewire.deals.deal-show', [
            'deal' => $deal,
            'stages' => $this->availableStages(),
            'history' => $this->stageHistory(),
        ])->title($deal->name);
    }
}
