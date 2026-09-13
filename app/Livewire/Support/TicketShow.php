<?php

namespace App\Livewire\Support;

use App\Domain\Support\Actions\AddTicketCommentAction;
use App\Domain\Support\Actions\AssignTicketAction;
use App\Domain\Support\Actions\ChangeTicketStatusAction;
use App\Domain\Support\Actions\DeleteTicketAction;
use App\Domain\Support\Actions\DeleteTicketCommentAction;
use App\Domain\Support\Actions\ToggleTicketWatchAction;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use App\Domain\Support\SlaClock;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One ticket: what was asked, who is on it, and everything said since.
 *
 * The conversation itself is 9.2's — comments and replies bring their own
 * table. What is here is the record, its status, and the shared timeline of
 * notes, documents and history that every module gets.
 */
class TicketShow extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $ticketId;

    /**
     * What is being typed into the reply box.
     */
    public string $reply = '';

    /**
     * Whether that reply is a private note.
     *
     * Defaults to false — a reply is for the customer. The dangerous mistake is
     * a note the customer was not meant to see, so the box says which it is
     * before it is sent rather than after.
     */
    public bool $replyIsInternal = false;

    public function mount(Ticket $ticket): void
    {
        $this->authorize('view', $ticket);

        $this->ticketId = $ticket->id;
    }

    /**
     * Loaded withTrashed so a removed ticket's page still opens for anybody
     * following an old link — the component says it is gone rather than 404ing
     * on a reference a customer is reading down the phone.
     */
    public function ticket(): Ticket
    {
        return Ticket::query()
            ->withTrashed()
            ->with(['owner:id,name', 'contact', 'account', 'watchers:id,name', 'slaPolicy.targets'])
            ->findOrFail($this->ticketId);
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return TicketStatus::options();
    }

    /**
     * @return array<int, string>
     */
    public function agentOptions(): array
    {
        $options = [];

        foreach (User::query()->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name;
        }

        return $options;
    }

    /**
     * The conversation, oldest first.
     *
     * Everything is shown: this page is only ever seen by our own people, and
     * `is_internal` governs who is *notified*, not who may read it. A customer
     * portal in a later phase reads the public ones through a query of its own.
     *
     * @return Collection<int, TicketComment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return TicketComment::query()
            ->where('ticket_id', $this->ticketId)
            ->with('author:id,name')
            ->oldest('id')
            ->get();
    }

    /**
     * Say something on the ticket.
     */
    public function comment(): void
    {
        $ticket = $this->ticket();

        $this->authorize('create', [TicketComment::class, $ticket]);

        $this->validate([
            'reply' => ['required', 'string', 'max:20000'],
        ], attributes: ['reply' => 'reply']);

        try {
            app(AddTicketCommentAction::class)(
                ticket: $ticket,
                body: $this->reply,
                author: $this->currentUser(),
                internal: $this->replyIsInternal,
            );
        } catch (RuntimeException $exception) {
            $this->addError('reply', $exception->getMessage());

            return;
        }

        $this->reply = '';
        unset($this->comments);

        $this->dispatch('notify', type: 'success', message: $this->replyIsInternal
            ? 'Noted internally.'
            : 'Your reply has been added.');
    }

    public function deleteComment(int $commentId): void
    {
        $comment = TicketComment::query()->whereKey($commentId)->where('ticket_id', $this->ticketId)->first();

        if ($comment === null) {
            return;
        }

        $this->authorize('delete', $comment);

        app(DeleteTicketCommentAction::class)($comment);

        unset($this->comments);
    }

    /**
     * Follow or stop following this ticket.
     *
     * Needs no permission beyond being able to see it: watching is a personal
     * choice, and mount() has already refused anybody who cannot.
     */
    public function toggleWatch(): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        $watching = app(ToggleTicketWatchAction::class)($this->ticket(), $user);

        $this->dispatch('notify', type: 'success', message: $watching
            ? 'You are following this ticket.'
            : 'You have stopped following this ticket.');
    }

    public function isWatching(): bool
    {
        $user = $this->currentUser();

        return $user !== null && $this->ticket()->isWatchedBy($user);
    }

    public function moveTo(string $status): void
    {
        $ticket = $this->ticket();

        $this->authorize('update', $ticket);

        $moved = TicketStatus::tryFrom($status);

        if ($moved === null) {
            return;
        }

        if (app(ChangeTicketStatusAction::class)($ticket, $moved, $this->currentUser())) {
            $this->dispatch('notify', type: 'success', message: $ticket->reference().' is now '.strtolower($moved->label()).'.');
        }
    }

    public function assignTo(int $agentId): void
    {
        $ticket = $this->ticket();

        $this->authorize('assign', $ticket);

        $agent = User::query()->find($agentId);

        if ($agent === null) {
            return;
        }

        if (app(AssignTicketAction::class)($ticket, $agent, $this->currentUser())) {
            $this->dispatch('notify', type: 'success', message: $ticket->reference().' is now with '.$agent->name.'.');
        }
    }

    public function delete(): void
    {
        $ticket = $this->ticket();

        $this->authorize('delete', $ticket);

        app(DeleteTicketAction::class)($ticket);

        session()->flash('status', $ticket->reference().' was removed.');

        $this->redirectRoute('tickets.index', navigate: true);
    }

    /**
     * Who is doing this, for the notifications the actions raise — nobody is
     * told about something they did themselves.
     */
    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Both halves of the promise, for the panel on the ticket page.
     *
     * @return array<int, array{label: string, value: string, state: string}>
     */
    public function slaRows(): array
    {
        $ticket = $this->ticket();
        $clock = app(SlaClock::class);
        $rows = [];

        foreach ([SlaClock::RESPONSE => 'First reply', SlaClock::RESOLUTION => 'Resolution'] as $kind => $label) {
            $value = $clock->label($ticket, $kind);

            if ($value === null) {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'value' => $value,
                'state' => match (true) {
                    $clock->hasBreached($ticket, $kind) => 'breached',
                    $clock->isWarning($ticket, $kind) => 'warning',
                    $clock->isPaused($ticket) => 'paused',
                    default => 'running',
                },
            ];
        }

        return $rows;
    }

    public function canUpdate(): bool
    {
        return auth()->user()?->can('update', $this->ticket()) === true;
    }

    public function canAssign(): bool
    {
        return auth()->user()?->can('assign', $this->ticket()) === true;
    }

    public function render(): View
    {
        $ticket = $this->ticket();

        return view('livewire.support.ticket-show', ['ticket' => $ticket])
            ->title($ticket->reference().' — '.$ticket->subject);
    }
}
