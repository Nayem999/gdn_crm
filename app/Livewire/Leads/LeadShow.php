<?php

namespace App\Livewire\Leads;

use App\Domain\Leads\Actions\ChangeLeadStatusAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Actions\SyncLeadAssigneesAction;
use App\Domain\Leads\DTOs\LeadScore;
use App\Domain\Leads\DTOs\QualificationCheck;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadDuplicates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Services\LeadQualification;
use App\Domain\Leads\Services\LeadScoring;
use App\Domain\Shared\Concerns\FindsDuplicates;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One lead's profile, with the moves it can make from where it is.
 *
 * Task 2.6 adds the convert action here; task 2.8 the timeline.
 */
class LeadShow extends Component
{
    use AuthorizesRequests;
    use FindsDuplicates;

    #[Locked]
    public int $leadId;

    /**
     * The person picked in the "add an assignee" control.
     */
    public ?string $newAssigneeId = null;

    /**
     * Their optional priority, alongside it.
     */
    public ?string $newAssigneePriority = null;

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);

        $this->leadId = $lead->id;
    }

    /**
     * Trashed records are included so a merged one stays readable: keeping it
     * is what makes its history survive, and a page nobody can open is not
     * kept in any useful sense. An ordinary deletion is still gone.
     */
    public function lead(): Lead
    {
        $lead = Lead::withTrashed()
            ->with(['assignees.user', 'convertedAccount', 'convertedContact', 'convertedDeal'])
            ->findOrFail($this->leadId);

        abort_if($lead->trashed() && ! $lead->isMerged(), 404);

        return $lead;
    }

    /**
     * Only the moves the current status allows, so the page never offers one
     * that would be refused.
     *
     * @return array<int, LeadStatus>
     */
    public function availableTransitions(): array
    {
        return $this->lead()->allowedTransitions();
    }

    /**
     * The score with the rules that produced it, so a number on the page is
     * never left unexplained.
     */
    public function scoreBreakdown(): LeadScore
    {
        return app(LeadScoring::class)->scoreFor($this->lead());
    }

    public function qualification(): QualificationCheck
    {
        return app(LeadQualification::class)->for($this->lead());
    }

    public function changeStatus(string $value): void
    {
        $lead = $this->lead();

        $this->authorize('update', $lead);

        $target = LeadStatus::tryFrom($value);

        if ($target === null) {
            return;
        }

        try {
            app(ChangeLeadStatusAction::class)($lead, $target);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->dispatch('lead-updated', message: 'Moved to '.$target->label().'.');
    }

    public function addAssignee(): void
    {
        $lead = $this->lead();

        $this->authorize('assign', $lead);

        $this->validate([
            'newAssigneeId' => ['required', 'integer', 'exists:users,id'],
            'newAssigneePriority' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], [], ['newAssigneeId' => 'person', 'newAssigneePriority' => 'priority']);

        $user = User::query()->findOrFail((int) $this->newAssigneeId);
        $priority = $this->newAssigneePriority === null || $this->newAssigneePriority === ''
            ? null
            : (int) $this->newAssigneePriority;

        $actor = auth()->user();

        app(SyncLeadAssigneesAction::class)->add($lead, $user, $priority, $actor instanceof User ? $actor : null);

        $this->reset(['newAssigneeId', 'newAssigneePriority']);

        $this->dispatch('lead-updated', message: $user->name.' was added to this lead.');
    }

    public function removeAssignee(int $userId): void
    {
        $lead = $this->lead();

        $this->authorize('assign', $lead);

        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        try {
            $removed = app(SyncLeadAssigneesAction::class)->remove($lead, $user);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        if (! $removed) {
            return;
        }

        $this->dispatch('lead-updated', message: $user->name.' was taken off this lead.');
    }

    public function delete(): void
    {
        $lead = $this->lead();

        $this->authorize('delete', $lead);

        app(DeleteLeadAction::class)($lead);

        session()->flash('status', $lead->fullName().' was removed.');

        $this->redirectRoute('leads.index', navigate: true);
    }

    /**
     * Every user not already on the lead, since adding somebody already there
     * would just change their priority through the wrong control.
     *
     * @return array<int, string>
     */
    public function assignableOptions(): array
    {
        $lead = $this->lead();
        $already = $lead->assignees->pluck('user_id')->all();

        $options = [];

        foreach (User::query()->whereNotIn('id', $already)->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name;
        }

        return $options;
    }

    public function duplicateSource(): ?DuplicateSource
    {
        return app(LeadDuplicates::class);
    }

    public function render(): View
    {
        $lead = $this->lead();

        return view('livewire.leads.lead-show', [
            'lead' => $lead,
            'transitions' => $this->availableTransitions(),
            'assignableUsers' => $this->assignableOptions(),
            'breakdown' => $this->scoreBreakdown(),
            'qualification' => $this->qualification(),
            'duplicates' => $this->duplicatesOf($lead),
        ])->title($lead->fullName());
    }
}
