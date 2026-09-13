<?php

namespace App\Livewire\Support;

use App\Domain\Support\Actions\AssignTicketAction;
use App\Domain\Support\Actions\ChangeTicketStatusAction;
use App\Domain\Support\Actions\DeleteTicketAction;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

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
            ->with(['owner:id,name', 'contact', 'account'])
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

    public function moveTo(string $status): void
    {
        $ticket = $this->ticket();

        $this->authorize('update', $ticket);

        $moved = TicketStatus::tryFrom($status);

        if ($moved === null) {
            return;
        }

        if (app(ChangeTicketStatusAction::class)($ticket, $moved)) {
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

        if (app(AssignTicketAction::class)($ticket, $agent)) {
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
