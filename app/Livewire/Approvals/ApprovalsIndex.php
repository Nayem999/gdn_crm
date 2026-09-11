<?php

namespace App\Livewire\Approvals;

use App\Domain\Approvals\Actions\DecideApprovalAction;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * What is waiting on me, and what I have already answered.
 *
 * Two lists rather than one filtered table: the first is a queue somebody works
 * through and the second is a record they consult, and putting them in one grid
 * makes the queue easy to miss — which is the failure mode of the whole
 * feature, since a workflow is standing still until somebody answers.
 */
#[Title('Approvals')]
class ApprovalsIndex extends Component
{
    use AuthorizesRequests;

    /**
     * @var array<int, string>
     */
    public array $comments = [];

    public ?string $error = null;

    public bool $showAll = false;

    public function mount(): void
    {
        $this->authorize('viewAny', ApprovalRequest::class);
    }

    /**
     * @return Collection<int, ApprovalRequest>
     */
    #[Computed]
    public function waitingOnMe(): Collection
    {
        return ApprovalRequest::query()
            ->awaiting((int) auth()->id())
            ->with(['levels.approver', 'subject'])
            ->orderBy('requested_at')
            ->get();
    }

    /**
     * Requests this person has a stake in, settled or not.
     *
     * Widened to every request only for somebody who may audit them — being an
     * approver shows you your own history, not everybody's.
     *
     * @return Collection<int, ApprovalRequest>
     */
    #[Computed]
    public function history(): Collection
    {
        $userId = (int) auth()->id();

        return ApprovalRequest::query()
            ->when(
                ! ($this->showAll && auth()->user()?->can('workflows.approvals')),
                fn ($query) => $query->whereHas('levels', fn ($level) => $level->where('approver_id', $userId)),
            )
            ->where('status', '!=', ApprovalStatus::Waiting->value)
            ->with(['levels.approver', 'levels.decider'])
            ->latest('completed_at')
            ->limit(50)
            ->get();
    }

    public function canAudit(): bool
    {
        return (bool) auth()->user()?->can('workflows.approvals');
    }

    public function approve(int $requestId): void
    {
        $this->decide($requestId, true);
    }

    public function reject(int $requestId): void
    {
        $this->decide($requestId, false);
    }

    private function decide(int $requestId, bool $approved): void
    {
        $request = ApprovalRequest::query()->with('levels')->whereKey($requestId)->first();

        if ($request === null) {
            return;
        }

        // The policy asks whether this person is the one being asked *now*,
        // which is the whole guarantee of a sequential chain.
        $this->authorize('decide', $request);

        try {
            app(DecideApprovalAction::class)(
                $request,
                auth()->user(),
                $approved,
                trim($this->comments[$requestId] ?? '') ?: null,
            );
            $this->error = null;
        } catch (RuntimeException $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->comments[$requestId]);
        unset($this->waitingOnMe, $this->history);

        $this->dispatch(
            'notify',
            type: 'success',
            message: $approved ? 'Approved. The workflow will carry on.' : 'Rejected. The workflow has stopped.',
        );
    }

    public function render(): View
    {
        return view('livewire.approvals.approvals-index');
    }
}
